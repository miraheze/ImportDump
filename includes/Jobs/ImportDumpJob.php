<?php

namespace Miraheze\ImportDump\Jobs;

use InitEditCount;
use MediaWiki\Config\Config;
use MediaWiki\Context\RequestContext;
use MediaWiki\Deferred\SiteStatsUpdate;
use MediaWiki\Exception\MWExceptionHandler;
use MediaWiki\Import\ImportStreamSource;
use MediaWiki\Import\WikiImporterFactory;
use MediaWiki\JobQueue\Job;
use MediaWiki\JobQueue\JobQueueGroupFactory;
use MediaWiki\JobQueue\JobSpecification;
use MediaWiki\Language\MessageLocalizer;
use MediaWiki\MainConfigNames;
use MediaWiki\Maintenance\FakeMaintenance;
use MediaWiki\Permissions\UltimateAuthority;
use MediaWiki\SiteStats\SiteStatsInit;
use MediaWiki\User\User;
use Miraheze\ImportDump\ConfigNames;
use Miraheze\ImportDump\Hooks\HookRunner;
use Miraheze\ImportDump\ImportHeartbeat;
use Miraheze\ImportDump\RequestManager;
use Miraheze\ImportDump\RequestStatus;
use RebuildRecentchanges;
use RebuildTextIndex;
use RefreshLinks;
use Throwable;
use UpdateArticleCount;
use Wikimedia\Rdbms\IConnectionProvider;

class ImportDumpJob extends Job
	implements RequestStatus {

	public const string JOB_NAME = 'ImportDumpJob';

	private const int HEARTBEAT_INTERVAL = 60;
	private const int MEMORY_RESERVE = 2097152;
	private const int FATAL_ERRORS = E_ERROR | E_PARSE | E_CORE_ERROR |
		E_COMPILE_ERROR | E_USER_ERROR | E_RECOVERABLE_ERROR;

	private readonly MessageLocalizer $messageLocalizer;

	private readonly int $requestID;
	private readonly string $username;

	private string $jobError;

	private bool $running = false;
	private int $lastBeat = 0;

	public function __construct(
		array $params,
		private readonly IConnectionProvider $connectionProvider,
		private readonly Config $config,
		private readonly ImportHeartbeat $heartbeat,
		private readonly HookRunner $hookRunner,
		private readonly RequestManager $requestManager,
		private readonly JobQueueGroupFactory $jobQueueGroupFactory,
		private readonly WikiImporterFactory $wikiImporterFactory,
	) {
		parent::__construct( self::JOB_NAME, $params );

		$this->requestID = $params['requestid'];
		$this->username = $params['username'];

		$this->messageLocalizer = RequestContext::getMain();
		$this->executionFlags |= self::JOB_NO_EXPLICIT_TRX_ROUND;
	}

	/** @inheritDoc */
	public function run(): true {
		$this->requestManager->loadFromID( $this->requestID );
		if ( $this->requestManager->getStatus() === self::STATUS_COMPLETE ) {
			// Don't rerun a job that is already completed.
			return true;
		}

		$filePath = wfTempDir() . '/' . $this->requestManager->getFileName();

		$this->running = true;
		$this->registerShutdownHandler();
		$this->beat( force: true );

		try {
			$this->hookRunner->onImportDumpJobGetFile( $filePath, $this->requestManager );

			// @phan-suppress-next-line SecurityCheck-PathTraversal False positive
			$importStreamSource = $this->getImportSource( $filePath );
			if ( !$importStreamSource ) {
				return true;
			}

			$this->queueNotification( self::STATUS_INPROGRESS, '' );

			$this->importPages( $importStreamSource );
			$this->updateSiteStats();
			$this->runMaintenanceScripts();

			$this->hookRunner->onImportDumpJobAfterImport( $filePath, $this->requestManager );
		} catch ( Throwable $t ) {
			// We want to handle any potential errors gracefully.
			MWExceptionHandler::rollbackPrimaryChangesAndLog( $t );
			$this->jobError = $this->getLogMessage( $t );
			$this->notifyFailed();
			return true;
		} finally {
			$this->cleanup( $filePath );
		}

		$this->queueNotification( self::STATUS_COMPLETE, '' );
		return true;
	}

	private function getImportSource( string $filePath ): ?ImportStreamSource {
		$status = ImportStreamSource::newFromFile( $filePath );
		if ( !$status->isGood() ) {
			$this->jobError = "Import source for $filePath failed";
			$this->setLastError( $this->jobError );
			$this->notifyFailed();
			return null;
		}

		return $status->value;
	}

	private function importPages( ImportStreamSource $importStreamSource ): void {
		$user = User::newSystemUser( 'ImportDump Extension', [ 'steal' => true ] );
		$importer = $this->wikiImporterFactory->getWikiImporter(
			$importStreamSource, new UltimateAuthority( $user )
		);

		$importer->disableStatisticsUpdate();
		$importer->setNoUpdates( true );
		$importer->setUsernamePrefix(
			$this->requestManager->getInterwikiPrefix(),
			true
		);

		$importer->setPageOutCallback( fn () => $this->beat( force: false ) );

		$importer->doImport();
		$this->beat( force: true );
	}

	private function updateSiteStats(): void {
		$siteStatsInit = new SiteStatsInit();
		$siteStatsInit->refresh();

		SiteStatsUpdate::cacheUpdate( $this->connectionProvider->getPrimaryDatabase() );
	}

	private function runMaintenanceScripts(): void {
		$maintenance = new FakeMaintenance;

		$this->rebuildTextIndex( $maintenance );
		$this->rebuildRecentChanges( $maintenance );
		$this->refreshLinks( $maintenance );
		$this->updateCounts( $maintenance );
	}

	private function rebuildTextIndex( FakeMaintenance $maintenance ): void {
		if ( $this->config->get( MainConfigNames::DisableSearchUpdate ) ) {
			return;
		}

		$rebuildText = $maintenance->createChild( RebuildTextIndex::class );
		$rebuildText->execute();
		$this->beat( force: true );
	}

	private function rebuildRecentChanges( FakeMaintenance $maintenance ): void {
		$rebuildRC = $maintenance->createChild( RebuildRecentchanges::class );
		$rebuildRC->execute();
		$this->beat( force: true );
	}

	private function refreshLinks( FakeMaintenance $maintenance ): void {
		$rebuildLinks = $maintenance->createChild( RefreshLinks::class );
		$rebuildLinks->execute();
		$this->beat( force: true );
	}

	private function updateCounts( FakeMaintenance $maintenance ): void {
		$initEditCount = $maintenance->createChild( InitEditCount::class );
		$initEditCount->execute();

		$updateArticleCount = $maintenance->createChild( UpdateArticleCount::class );
		$updateArticleCount->setOption( 'update', true );
		$updateArticleCount->execute();
	}

	private function cleanup( string $filePath ): void {
		$this->running = false;
		$this->heartbeat->clear( $this->requestID );

		if ( file_exists( $filePath ) ) {
			unlink( $filePath );
		}
	}

	private function beat( bool $force ): void {
		$now = time();
		if ( !$force && $now - $this->lastBeat < self::HEARTBEAT_INTERVAL ) {
			return;
		}

		$this->lastBeat = $now;

		$hours = $this->config->get( ConfigNames::StalledImportHours );
		$ttl = max( 1, (int)( $hours ?: 24 ) ) * 3600;

		$this->heartbeat->beat( $this->requestID, $ttl );
	}

	private function registerShutdownHandler(): void {
		$reserve = str_repeat( ' ', self::MEMORY_RESERVE );

		register_shutdown_function( function () use ( &$reserve ): void {
			if ( !$this->running ) {
				return;
			}

			$this->running = false;
			$reserve = null;

			$error = error_get_last();
			$this->jobError = $error !== null && ( $error['type'] & self::FATAL_ERRORS ) ?
				'Fatal error: ' . $error['message'] :
				'The import job was stopped before it finished.';

			try {
				$this->notifyFailed();
			} catch ( Throwable ) {
				// Nothing else can be done during shutdown.
			}
		} );
	}

	private function notifyFailed(): void {
		$this->queueNotification( self::STATUS_FAILED, $this->jobError );
	}

	private function queueNotification( string $status, string $error ): void {
		$this->jobQueueGroupFactory->makeJobQueueGroup( $this->getLoggingWiki() )->push(
			new JobSpecification(
				ImportDumpNotifyJob::JOB_NAME,
				[
					'joberror' => $error,
					'requestid' => $this->requestID,
					'status' => $status,
					'username' => $this->username,
				]
			)
		);
	}

	private function getLogMessage( Throwable $t ): string {
		// This is the request ID from Telemetry.
		$requestId = $this->getRequestId();
		$type = get_class( $t );
		$message = $t->getMessage();

		return "[$requestId]   $type: $message";
	}

	private function getLoggingWiki(): string {
		$dbr = $this->connectionProvider->getReplicaDatabase( 'virtual-importdump' );
		return $dbr->getDomainID();
	}

	public function allowRetries(): false {
		return false;
	}
}
