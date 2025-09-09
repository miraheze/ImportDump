<?php

namespace Miraheze\ImportDump\Jobs;

use ImportStreamSource;
use InitEditCount;
use MediaWiki\Config\Config;
use MediaWiki\Context\RequestContext;
use MediaWiki\Deferred\SiteStatsUpdate;
use MediaWiki\Exception\MWExceptionHandler;
use MediaWiki\JobQueue\Job;
use MediaWiki\JobQueue\JobQueueGroupFactory;
use MediaWiki\JobQueue\JobSpecification;
use MediaWiki\MainConfigNames;
use MediaWiki\Maintenance\FakeMaintenance;
use MediaWiki\Permissions\UltimateAuthority;
use MediaWiki\SiteStats\SiteStatsInit;
use MediaWiki\User\User;
use MessageLocalizer;
use Miraheze\ImportDump\Hooks\HookRunner;
use Miraheze\ImportDump\ImportDumpStatus;
use Miraheze\ImportDump\RequestManager;
use RebuildRecentchanges;
use RebuildTextIndex;
use RefreshLinks;
use Throwable;
use UpdateArticleCount;
use WikiImporterFactory;
use Wikimedia\Rdbms\IConnectionProvider;

class ImportDumpJob extends Job
	implements ImportDumpStatus {

	public const JOB_NAME = 'ImportDumpJob';

	private readonly MessageLocalizer $messageLocalizer;

	private readonly int $requestID;
	private readonly string $username;

	private string $jobError;

	public function __construct(
		array $params,
		private readonly IConnectionProvider $connectionProvider,
		private readonly Config $config,
		private readonly HookRunner $hookRunner,
		private readonly RequestManager $requestManager,
		private readonly JobQueueGroupFactory $jobQueueGroupFactory,
		private readonly WikiImporterFactory $wikiImporterFactory
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

		$dbw = $this->connectionProvider->getPrimaryDatabase();
		$filePath = wfTempDir() . '/' . $this->requestManager->getFileName();

		$this->hookRunner->onImportDumpJobGetFile( $filePath, $this->requestManager );

		// @phan-suppress-next-line SecurityCheck-PathTraversal False positive
		$importStreamSource = ImportStreamSource::newFromFile( $filePath );
		if ( !$importStreamSource->isGood() ) {
			$this->jobError = "Import source for $filePath failed";
			$this->setLastError( $this->jobError );
			$this->notifyFailed();
			return true;
		}

		$this->jobQueueGroupFactory->makeJobQueueGroup( $this->getLoggingWiki() )->push(
			new JobSpecification(
				ImportDumpNotifyJob::JOB_NAME,
				[
					// No errors
					'joberror' => '',
					'requestid' => $this->requestID,
					'status' => self::STATUS_INPROGRESS,
					'username' => $this->username,
				]
			)
		);

		try {
			$user = User::newSystemUser( 'ImportDump Extension', [ 'steal' => true ] );
			$importer = $this->wikiImporterFactory->getWikiImporter(
				$importStreamSource->value, new UltimateAuthority( $user )
			);

			$importer->disableStatisticsUpdate();
			$importer->setNoUpdates( true );
			$importer->setUsernamePrefix(
				$this->requestManager->getInterwikiPrefix(),
				true
			);

			$importer->doImport();

			$siteStatsInit = new SiteStatsInit();
			$siteStatsInit->refresh();

			SiteStatsUpdate::cacheUpdate( $dbw );

			$maintenance = new FakeMaintenance;
			if ( !$this->config->get( MainConfigNames::DisableSearchUpdate ) ) {
				$rebuildText = $maintenance->createChild( RebuildTextIndex::class );
				$rebuildText->execute();
			}

			$rebuildRC = $maintenance->createChild( RebuildRecentchanges::class );
			$rebuildRC->execute();

			$rebuildLinks = $maintenance->createChild( RefreshLinks::class );
			$rebuildLinks->execute();

			$initEditCount = $maintenance->createChild( InitEditCount::class );
			$initEditCount->execute();

			$updateArticleCount = $maintenance->createChild( UpdateArticleCount::class );
			$updateArticleCount->setOption( 'update', true );
			$updateArticleCount->execute();

			$this->hookRunner->onImportDumpJobAfterImport( $filePath, $this->requestManager );
		} catch ( Throwable $t ) {
			// We want to handle any potential errors gracefully.
			MWExceptionHandler::rollbackPrimaryChangesAndLog( $t );
			$this->jobError = $this->getLogMessage( $t );
			$this->notifyFailed();
			return true;
		}

		$this->jobQueueGroupFactory->makeJobQueueGroup( $this->getLoggingWiki() )->push(
			new JobSpecification(
				ImportDumpNotifyJob::JOB_NAME,
				[
					// No errors
					'joberror' => '',
					'requestid' => $this->requestID,
					'status' => self::STATUS_COMPLETE,
					'username' => $this->username,
				]
			)
		);

		return true;
	}

	private function notifyFailed(): void {
		$this->jobQueueGroupFactory->makeJobQueueGroup( $this->getLoggingWiki() )->push(
			new JobSpecification(
				ImportDumpNotifyJob::JOB_NAME,
				[
					'joberror' => $this->jobError,
					'requestid' => $this->requestID,
					'status' => self::STATUS_FAILED,
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
