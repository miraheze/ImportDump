<?php

namespace Miraheze\ImportDump\Jobs;

use Config;
use ConfigFactory;
use ExtensionRegistry;
use FakeMaintenance;
use ImportStreamSource;
use Job;
use JobSpecification;
use MediaWiki\Extension\Notifications\Model\Event;
use MediaWiki\JobQueue\JobQueueGroupFactory;
use MediaWiki\MainConfigNames;
use MediaWiki\Permissions\UltimateAuthority;
use MessageLocalizer;
use Miraheze\ImportDump\Hooks\ImportDumpHookRunner;
use Miraheze\ImportDump\ImportDumpRequestManager;
use Miraheze\ImportDump\ImportDumpStatus;
use RebuildRecentchanges;
use RebuildTextIndex;
use RefreshLinks;
use RequestContext;
use SiteStatsInit;
use SiteStatsUpdate;
use SpecialPage;
use Throwable;
use Title;
use User;
use WikiImporterFactory;
use Wikimedia\Rdbms\ILBFactory;

class ImportDumpJob extends Job
	implements ImportDumpStatus {

	public const JOB_NAME = 'ImportDumpJob';

	/** @var int */
	private $requestID;

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
			$this->importDumpRequestManager->setStatus( self::STATUS_FAILED );
			$this->setLastError( "Import source for {$filePath} failed" );
			$this->notifyFailed();
			return false;
		}

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
				$rebuildText = $maintenance->runChild( RebuildTextIndex::class, 'rebuildtextindex.php' );
				$rebuildText->execute();
			}

			$rebuildRC = $maintenance->runChild( RebuildRecentchanges::class, 'rebuildrecentchanges.php' );
			$rebuildRC->execute();

			$rebuildLinks = $maintenance->runChild( RefreshLinks::class, 'refreshLinks.php' );
			$rebuildLinks->execute();
		} catch ( Throwable $e ) {
			$this->importDumpRequestManager->setStatus( self::STATUS_FAILED );
			$this->setLastError( 'Import failed: ' . $e->getMessage() );
			$this->notifyFailed();
			return false;
		}

		$this->importDumpRequestManager->setStatus( self::STATUS_COMPLETE );

		$commentUser = User::newSystemUser( 'ImportDump Status Update' );
		$comment = $this->messageLocalizer->msg( 'importdump-import-completed-comment' )
			->inContentLanguage()
			->escaped();

		$this->importDumpRequestManager->addComment( $comment, $commentUser );
		$this->importDumpRequestManager->sendNotification( $comment, 'importdump-request-comment', $commentUser );

		return true;
	}

	private function notifyFailed() {
		$this->jobQueueGroupFactory->makeJobQueueGroup( $this->config->get( 'ImportDumpCentralWiki' ) )->push(
			new JobSpecification(
				ImportDumpNotifyJob::JOB_NAME,
				[
					'requestid' => $this->ID,
					'type' => 'failed',
				]
			)
		);
	}
}
