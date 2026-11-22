<?php

declare( strict_types = 1 );

namespace Miraheze\ImportDump\Jobs;

use InitEditCount;
use MediaWiki\Config\Config;
use MediaWiki\Deferred\SiteStatsUpdate;
use MediaWiki\Import\ImportStreamSource;
use MediaWiki\Import\WikiImporterFactory;
use MediaWiki\JobQueue\JobQueueGroupFactory;
use MediaWiki\MainConfigNames;
use MediaWiki\Maintenance\FakeMaintenance;
use MediaWiki\Permissions\UltimateAuthority;
use MediaWiki\SiteStats\SiteStatsInit;
use MediaWiki\User\User;
use Miraheze\ImportDump\Hooks\HookRunner;
use Miraheze\ImportDump\Progress\ImportHeartbeat;
use Miraheze\ImportDump\Request\RequestManager;
use Miraheze\ImportDump\Request\RequestStage;
use Miraheze\ImportDump\Request\RequestStatus;
use Miraheze\ImportDump\Upload\PageCounter;
use RebuildRecentchanges;
use RebuildTextIndex;
use RefreshLinks;
use UpdateArticleCount;
use Wikimedia\Rdbms\IConnectionProvider;
use function error_get_last;
use function file_exists;
use function intdiv;
use function min;
use function register_shutdown_function;
use function str_repeat;
use function unlink;
use function wfTempDir;
use const E_COMPILE_ERROR;
use const E_CORE_ERROR;
use const E_ERROR;
use const E_PARSE;
use const E_RECOVERABLE_ERROR;
use const E_USER_ERROR;

class ImportDumpJob extends AbstractImportJob {

	public const string JOB_NAME = 'ImportDumpJob';

	private const int MEMORY_RESERVE = 2097152;
	private const int FATAL_ERRORS = E_ERROR | E_PARSE | E_CORE_ERROR |
		E_COMPILE_ERROR | E_USER_ERROR | E_RECOVERABLE_ERROR;

	private bool $running = false;
	private string $filePath = '';
	private RequestStage $startStage = RequestStage::Download;
	private int $refreshLinksChunkSize = 1000;

	public function __construct(
		array $params,
		IConnectionProvider $connectionProvider,
		Config $config,
		ImportHeartbeat $heartbeat,
		private readonly HookRunner $hookRunner,
		RequestManager $requestManager,
		JobQueueGroupFactory $jobQueueGroupFactory,
		private readonly WikiImporterFactory $wikiImporterFactory,
	) {
		parent::__construct(
			self::JOB_NAME,
			$params,
			$connectionProvider,
			$config,
			$heartbeat,
			$requestManager,
			$jobQueueGroupFactory
		);
	}

	/** @inheritDoc */
	protected function execute(): bool {
		$this->startStage = $this->requestManager->getStage() ?? RequestStage::Download;
		$filePath = wfTempDir() . '/' . $this->requestManager->getFileName();
		$this->filePath = $filePath;

		$this->running = true;
		$this->registerShutdownHandler();

		$importStreamSource = null;
		if ( !$this->startStage->hasImported() ) {
			$this->enterStage( RequestStage::Download );
			$this->hookRunner->onImportDumpJobGetFile( $filePath, $this->requestManager );

			// @phan-suppress-next-line SecurityCheck-PathTraversal False positive
			$importStreamSource = $this->getImportSource( $filePath );
			if ( !$importStreamSource ) {
				return false;
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

		return true;
	}

	private function getImportSource( string $filePath ): ?ImportStreamSource {
		$status = ImportStreamSource::newFromFile( $filePath );
		if ( !$status->isGood() ) {
			$error = "Import source for $filePath failed";
			$this->setLastError( $error );
			$this->notifyFailed( $error );
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

			$refreshLinks = $maintenance->createChild( RefreshLinks::class );
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

		$this->saveStage( $stage );
		return true;
	}

	/** @inheritDoc */
	protected function cleanup(): void {
		$this->running = false;

		if ( $this->filePath !== '' && file_exists( $this->filePath ) ) {
			unlink( $this->filePath );
		}
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
			$message = $error !== null && ( $error['type'] & self::FATAL_ERRORS ) ?
				'Fatal error: ' . $error['message'] :
				'The import job was stopped before it finished.';

			try {
				$this->notifyFailed( $message );
			} catch ( Throwable ) {
				// Nothing else can be done during shutdown.
			}
		} );
	}

	/** @inheritDoc */
	protected function notifyFailed( string $error ): void {
		$status = $this->requestManager->hasImported() ? RequestStatus::Partial : RequestStatus::Failed;
		$this->queueNotification( $status, $error );
	}
}
