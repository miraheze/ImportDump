<?php

declare( strict_types = 1 );

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
use Miraheze\ImportDump\Progress\ImportHeartbeat;
use Miraheze\ImportDump\Progress\RefreshLinksWithProgress;
use Miraheze\ImportDump\Request\RequestManager;
use Miraheze\ImportDump\Request\RequestStage;
use Miraheze\ImportDump\Request\RequestStatus;
use Miraheze\ImportDump\Upload\PageCounter;
use RebuildRecentchanges;
use RebuildTextIndex;
use Throwable;
use UpdateArticleCount;
use Wikimedia\Rdbms\IConnectionProvider;
use function error_get_last;
use function file_exists;
use function get_class;
use function intdiv;
use function is_dir;
use function max;
use function min;
use function register_shutdown_function;
use function str_repeat;
use function time;
use function unlink;
use function wfMkdirParents;
use function wfTempDir;
use const E_COMPILE_ERROR;
use const E_CORE_ERROR;
use const E_ERROR;
use const E_PARSE;
use const E_RECOVERABLE_ERROR;
use const E_USER_ERROR;

class ImportDumpJob extends Job {

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
	private RequestStage $startStage = RequestStage::Download;

	/** Taken before anything is written, as it cannot be taken once changes are pending. */
	private mixed $ticket = null;
	private int $refreshLinksChunkSize = 1000;

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
		if ( $this->requestManager->getStatus() === RequestStatus::Complete ) {
			// Don't rerun a job that is already completed.
			return true;
		}

		$this->ticket = $this->connectionProvider->getEmptyTransactionTicket( __METHOD__ );
		$this->startStage = $this->requestManager->getStage() ?? RequestStage::Download;
		$directory = $this->getTempDirectory();
		$filePath = $directory . '/' . $this->requestManager->getFileName();

		$this->running = true;
		$this->registerShutdownHandler();
		$this->beat( force: true );

		try {
			$importStreamSource = null;
			if ( !$this->startStage->hasImported() ) {
				$this->enterStage( RequestStage::Download );
				$this->hookRunner->onImportDumpJobGetFile( $filePath, $this->requestManager );

				// @phan-suppress-next-line SecurityCheck-PathTraversal False positive
				$importStreamSource = $this->getImportSource( $filePath );
				if ( !$importStreamSource ) {
					return true;
				}
			}

			$this->queueNotification( RequestStatus::InProgress, '' );

			if ( $importStreamSource ) {
				$this->enterStage( RequestStage::Import );
				// @phan-suppress-next-line SecurityCheck-PathTraversal False positive
				$this->importPages( $importStreamSource, $filePath );
			}

			$this->updateSiteStats();
			$this->runMaintenanceScripts();
			$this->runAfterImport( $filePath );
		} catch ( Throwable $t ) {
			// We want to handle any potential errors gracefully.
			MWExceptionHandler::rollbackPrimaryChangesAndLog( $t );
			$this->jobError = $this->getLogMessage( $t );
			$this->notifyFailed();
			return true;
		} finally {
			$this->cleanup( $filePath );
		}

		$this->queueNotification( RequestStatus::Complete, '' );
		return true;
	}

	private function getTempDirectory(): string {
		$directory = (string)$this->config->get( ConfigNames::TempDirectory );
		if ( $directory === '' ) {
			return wfTempDir();
		}

		if ( !is_dir( $directory ) ) {
			wfMkdirParents( $directory, null, __METHOD__ );
		}

		return $directory;
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

	private function importPages( ImportStreamSource $importStreamSource, string $filePath ): void {
		$user = User::newSystemUser( RequestManager::EXTENSION_USER, [ 'steal' => true ] );
		$importer = $this->wikiImporterFactory->getWikiImporter(
			$importStreamSource, new UltimateAuthority( $user )
		);

		$importer->disableStatisticsUpdate();
		$importer->setNoUpdates( true );
		$importer->setUsernamePrefix(
			$this->requestManager->getInterwikiPrefix(),
			true
		);

		$total = PageCounter::count( $filePath );
		$done = 0;
		$lastUpdate = 0;

		$importer->setPageOutCallback( function () use ( $total, &$done, &$lastUpdate ): void {
			$this->beat( force: false );
			$done++;

			$now = time();
			if ( !$total || $now === $lastUpdate ) {
				return;
			}

			$lastUpdate = $now;
			$pages = min( $done, $total );
			$this->requestManager->setImportPercent( intdiv( $pages * 100, $total ), done: $pages, total: $total );
		} );

		$importer->doImport();
		if ( $total ) {
			$this->requestManager->setImportPercent( 100, done: $total, total: $total );
		}

		$this->beat( force: true );
	}

	private function updateSiteStats(): void {
		if ( !$this->enterStage( RequestStage::SiteStats ) ) {
			return;
		}

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
		if (
			$this->config->get( MainConfigNames::DisableSearchUpdate ) ||
			!$this->enterStage( RequestStage::TextIndex )
		) {
			return;
		}

		$rebuildText = $maintenance->createChild( RebuildTextIndex::class );
		$rebuildText->execute();
		$this->beat( force: true );
	}

	private function rebuildRecentChanges( FakeMaintenance $maintenance ): void {
		if ( !$this->enterStage( RequestStage::RecentChanges ) ) {
			return;
		}

		$rebuildRC = $maintenance->createChild( RebuildRecentchanges::class );
		$rebuildRC->execute();
		$this->beat( force: true );
	}

	private function refreshLinks( FakeMaintenance $maintenance ): void {
		if ( !$this->enterStage( RequestStage::RefreshLinks ) ) {
			return;
		}

		$dbr = $this->connectionProvider->getReplicaDatabase();

		$total = $dbr->newSelectQueryBuilder()
			->select( 'COUNT(*)' )
			->from( 'page' )
			->caller( __METHOD__ )
			->fetchField();

		$total = (int)$total;
		$batches = 0;

		// The first batch can take minutes, so the bar starts at zero instead of looking like nothing is known.
		if ( $total > 0 ) {
			$this->requestManager->setImportPercent( 0, 0, $total );
		}

		// Run in page ID ranges so we can beat between them. RefreshLinks can
		// take a long time to run so we need to check if its still running.
		$start = null;
		do {
			$next = $dbr->newSelectQueryBuilder()
				->select( 'page_id' )
				->from( 'page' )
				->where( $dbr->expr( 'page_id', '>=', $start ?? 0 ) )
				->orderBy( 'page_id' )
				->offset( $this->refreshLinksChunkSize )
				->caller( __METHOD__ )
				->fetchField();

			$refreshLinks = $maintenance->createChild( RefreshLinksWithProgress::class );
			'@phan-var RefreshLinksWithProgress $refreshLinks';
			$offset = $batches * $this->refreshLinksChunkSize;
			$refreshLinks->setPagesCallback( function ( int $pages ) use ( $offset, $total ): void {
				$this->beat( force: false );

				$done = min( $total, $offset + $pages );
				$percent = min( 99, intdiv( $done * 100, max( 1, $total ) ) );
				$this->requestManager->setImportPercent( $percent, $done, $total );
			} );

			if ( $start !== null ) {
				$refreshLinks->setArg( 0, (string)$start );
			}

			if ( $next !== false ) {
				$refreshLinks->setOption( 'e', (string)( (int)$next - 1 ) );
			}

			$refreshLinks->execute();
			$this->beat( force: true );

			$batches++;
			if ( $total > 0 ) {
				$done = $next === false ? $total : min( $total, $batches * $this->refreshLinksChunkSize );
				$this->requestManager->setImportPercent(
					$next === false ? 100 : min( 99, intdiv( $done * 100, $total ) ),
					$done,
					$total
				);
			}

			$start = $next === false ? null : (int)$next;
		} while ( $start !== null );
	}

	private function updateCounts( FakeMaintenance $maintenance ): void {
		if ( !$this->enterStage( RequestStage::Counts ) ) {
			return;
		}

		$initEditCount = $maintenance->createChild( InitEditCount::class );
		$initEditCount->execute();

		$updateArticleCount = $maintenance->createChild( UpdateArticleCount::class );
		$updateArticleCount->setOption( 'update', true );
		$updateArticleCount->execute();
	}

	private function runAfterImport( string $filePath ): void {
		$this->enterStage( RequestStage::AfterImport );
		$this->hookRunner->onImportDumpJobAfterImport( $filePath, $this->requestManager );
	}

	private function enterStage( RequestStage $stage ): bool {
		if ( $stage->getPosition() < $this->startStage->getPosition() ) {
			return false;
		}

		// Save the stage now. Otherwise this job keeps the request locked until it ends,
		// and the notify jobs cannot update the request while the import runs.
		$this->requestManager->setStage( $stage );
		$this->connectionProvider->commitAndWaitForReplication( __METHOD__, $this->ticket );

		return true;
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
		$status = $this->requestManager->hasImported() ? RequestStatus::Partial : RequestStatus::Failed;
		$this->queueNotification( $status, $this->jobError );
	}

	private function queueNotification( RequestStatus $status, string $error ): void {
		$this->jobQueueGroupFactory->makeJobQueueGroup( $this->getLoggingWiki() )->push(
			new JobSpecification(
				ImportDumpNotifyJob::JOB_NAME,
				[
					'joberror' => $error,
					'requestid' => $this->requestID,
					'status' => $status->value,
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
		$dbr = $this->connectionProvider->getReplicaDatabase( RequestManager::VIRTUAL_DOMAIN );
		return $dbr->getDomainID();
	}

	public function allowRetries(): false {
		return false;
	}
}
