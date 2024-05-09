<?php

namespace Miraheze\ImportDump\Jobs;

use FakeMaintenance;
use ImportStreamSource;
use InitEditCount;
use Job;
use JobSpecification;
use MediaWiki\Config\Config;
use MediaWiki\Config\ConfigFactory;
use MediaWiki\Http\Telemetry;
use MediaWiki\JobQueue\JobQueueGroupFactory;
use MediaWiki\MainConfigNames;
use MediaWiki\Permissions\UltimateAuthority;
use MediaWiki\SiteStats\SiteStatsInit;
use MediaWiki\User\User;
use MediaWiki\WikiMap\WikiMap;
use MessageLocalizer;
use Miraheze\ImportDump\Hooks\ImportDumpHookRunner;
use Miraheze\ImportDump\ImportDumpRequestManager;
use Miraheze\ImportDump\ImportDumpStatus;
use MWExceptionHandler;
use RebuildRecentchanges;
use RebuildTextIndex;
use RefreshLinks;
use RequestContext;
use SiteStatsUpdate;
use Throwable;
use WikiImporterFactory;
use Wikimedia\Rdbms\IConnectionProvider;

class ImportDumpJob extends Job
	implements ImportDumpStatus {

	public const JOB_NAME = 'ImportDumpJob';

	/** @var int */
	private $requestID;

	/** @var string */
	private $username;

	/** @var Config */
	private $config;

	/** @var IConnectionProvider */
	private $connectionProvider;

	/** @var JobQueueGroupFactory */
	private $jobQueueGroupFactory;

	/** @var ImportDumpHookRunner */
	private $importDumpHookRunner;

	/** @var ImportDumpRequestManager */
	private $importDumpRequestManager;

	/** @var MessageLocalizer */
	private $messageLocalizer;

	/** @var WikiImporterFactory */
	private $wikiImporterFactory;

	/**
	 * @param array $params
	 * @param ConfigFactory $configFactory
	 * @param IConnectionProvider $connectionProvider
	 * @param JobQueueGroupFactory $jobQueueGroupFactory
	 * @param ImportDumpHookRunner $importDumpHookRunner
	 * @param ImportDumpRequestManager $importDumpRequestManager
	 * @param WikiImporterFactory $wikiImporterFactory
	 */
	public function __construct(
		array $params,
		ConfigFactory $configFactory,
		IConnectionProvider $connectionProvider,
		JobQueueGroupFactory $jobQueueGroupFactory,
		ImportDumpHookRunner $importDumpHookRunner,
		ImportDumpRequestManager $importDumpRequestManager,
		WikiImporterFactory $wikiImporterFactory
	) {
		parent::__construct( self::JOB_NAME, $params );

		$this->requestID = $params['requestid'];
		$this->username = $params['username'];

		$this->connectionProvider = $connectionProvider;
		$this->jobQueueGroupFactory = $jobQueueGroupFactory;
		$this->importDumpHookRunner = $importDumpHookRunner;
		$this->importDumpRequestManager = $importDumpRequestManager;
		$this->wikiImporterFactory = $wikiImporterFactory;

		$this->config = $configFactory->makeConfig( 'ImportDump' );
		$this->messageLocalizer = RequestContext::getMain();
	}

	/**
	 * @return bool
	 */
	public function run(): bool {
		$dbw = $this->connectionProvider->getPrimaryDatabase();

		$this->importDumpRequestManager->fromID( $this->requestID );
		$filePath = wfTempDir() . '/' . $this->importDumpRequestManager->getFileName();

		$this->importDumpHookRunner->onImportDumpJobGetFile( $filePath, $this->importDumpRequestManager );

		// @phan-suppress-next-line SecurityCheck-PathTraversal False positive
		$importStreamSource = ImportStreamSource::newFromFile( $filePath );
		if ( !$importStreamSource->isGood() ) {
			$this->setLastError( "Import source for {$filePath} failed" );
			$this->notifyFailed();
			return true;
		}

		$this->jobQueueGroupFactory->makeJobQueueGroup( $this->getLoggingWiki() )->push(
			new JobSpecification(
				ImportDumpNotifyJob::JOB_NAME,
				[
					'requestid' => $this->requestID,
					'status' => self::STATUS_INPROGRESS,
					'username' => $this->username,
				]
			)
		);

		try {
			$user = User::newSystemUser( 'ImportDump Extension', [ 'steal' => true ] );

			if ( version_compare( MW_VERSION, '1.42', '>=' ) ) {
				// @phan-suppress-next-line PhanParamTooMany
				$importer = $this->wikiImporterFactory->getWikiImporter(
					$importStreamSource->value, new UltimateAuthority( $user )
				);
			} else {
				$importer = $this->wikiImporterFactory->getWikiImporter(
					$importStreamSource->value
				);
			}

			$importer->disableStatisticsUpdate();
			$importer->setNoUpdates( true );
			$importer->setUsernamePrefix(
				$this->importDumpRequestManager->getInterwikiPrefix(),
				true
			);

			$importer->doImport();

			$siteStatsInit = new SiteStatsInit();
			$siteStatsInit->refresh();

			SiteStatsUpdate::cacheUpdate( $dbw );

			$maintenance = new FakeMaintenance;
			if ( !$this->config->get( MainConfigNames::DisableSearchUpdate ) ) {
				$rebuildText = $maintenance->runChild( RebuildTextIndex::class );
				$rebuildText->execute();
			}

			$rebuildRC = $maintenance->runChild( RebuildRecentchanges::class );
			$rebuildRC->execute();

			$rebuildLinks = $maintenance->runChild( RefreshLinks::class );
			$rebuildLinks->execute();

			$initEditCount = $maintenance->runChild( InitEditCount::class );
			$initEditCount->execute();

			$this->importDumpHookRunner->onImportDumpJobAfterImport( $filePath, $this->importDumpRequestManager );
		} catch ( Throwable $e ) {
			MWExceptionHandler::rollbackPrimaryChangesAndLog( $e );
			$this->setLastError( $this->getLogMessage( $e ) );
			$this->notifyFailed();
			return true;
		}

		$this->jobQueueGroupFactory->makeJobQueueGroup( $this->getLoggingWiki() )->push(
			new JobSpecification(
				ImportDumpNotifyJob::JOB_NAME,
				[
					'requestid' => $this->requestID,
					'status' => self::STATUS_COMPLETE,
					'username' => $this->username,
				]
			)
		);

		return true;
	}

	private function notifyFailed() {
		$this->jobQueueGroupFactory->makeJobQueueGroup( $this->getLoggingWiki() )->push(
			new JobSpecification(
				ImportDumpNotifyJob::JOB_NAME,
				[
					'lasterror' => $this->getLastError(),
					'requestid' => $this->requestID,
					'status' => self::STATUS_FAILED,
					'username' => $this->username,
				]
			)
		);
	}

	/**
	 * @param Throwable $e
	 * @return string
	 */
	private function getLogMessage( Throwable $e ): string {
		$id = Telemetry::getInstance()->getRequestId();
		$type = get_class( $e );
		$message = $e->getMessage();

		return "[$id]   $type: $message";
	}

	/**
	 * @return string
	 */
	private function getLoggingWiki(): string {
		return $this->config->get( 'ImportDumpCentralWiki' ) ?:
			WikiMap::getCurrentWikiId();
	}

	/**
	 * @return bool
	 */
	public function allowRetries(): bool {
		return false;
	}
}
