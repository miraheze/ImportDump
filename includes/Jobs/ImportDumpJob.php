<?php

namespace Miraheze\RequestImport\Jobs;

use FakeMaintenance;
use ImportStreamSource;
use InitEditCount;
use Job;
use JobSpecification;
use MediaWiki\Config\Config;
use MediaWiki\Config\ConfigFactory;
use MediaWiki\JobQueue\JobQueueGroupFactory;
use MediaWiki\MainConfigNames;
use MediaWiki\Permissions\UltimateAuthority;
use MediaWiki\SiteStats\SiteStatsInit;
use MediaWiki\Title\Title;
use MediaWiki\User\User;
use MessageLocalizer;
use Miraheze\RequestImport\Hooks\ImportDumpHookRunner;
use Miraheze\RequestImport\ImportDumpRequestManager;
use Miraheze\RequestImport\ImportDumpStatus;
use RebuildRecentchanges;
use RebuildTextIndex;
use RefreshLinks;
use RequestContext;
use SiteStatsUpdate;
use Throwable;
use WikiImporterFactory;
use Wikimedia\Rdbms\ILBFactory;

class ImportDumpJob extends Job
	implements ImportDumpStatus {

	public const JOB_NAME = 'ImportDumpJob';

	/** @var int */
	private $requestID;

	/** @var string */
	private $username;

	/** @var Config */
	private $config;

	/** @var ILBFactory */
	private $dbLoadBalancerFactory;

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
	 * @param Title $title
	 * @param array $params
	 * @param ConfigFactory $configFactory
	 * @param ILBFactory $dbLoadBalancerFactory
	 * @param JobQueueGroupFactory $jobQueueGroupFactory
	 * @param ImportDumpHookRunner $importDumpHookRunner
	 * @param ImportDumpRequestManager $importDumpRequestManager
	 * @param WikiImporterFactory $wikiImporterFactory
	 */
	public function __construct(
		Title $title,
		array $params,
		ConfigFactory $configFactory,
		ILBFactory $dbLoadBalancerFactory,
		JobQueueGroupFactory $jobQueueGroupFactory,
		ImportDumpHookRunner $importDumpHookRunner,
		ImportDumpRequestManager $importDumpRequestManager,
		WikiImporterFactory $wikiImporterFactory
	) {
		parent::__construct( self::JOB_NAME, $params );

		$this->requestID = $params['requestid'];
		$this->username = $params['username'];

		$this->dbLoadBalancerFactory = $dbLoadBalancerFactory;
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
		$dbw = $this->dbLoadBalancerFactory->getMainLB()->getMaintenanceConnectionRef( DB_PRIMARY );

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
			$this->setLastError( $e->getMessage() );
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
	 * @return string
	 */
	private function getLoggingWiki(): string {
		return $this->config->get( 'ImportDumpCentralWiki' ) ?:
			$this->config->get( MainConfigNames::DBname );
	}

	/**
	 * @return bool
	 */
	public function allowRetries(): bool {
		return false;
	}
}
