<?php

namespace Miraheze\ImportDump\Jobs;

use Config;
use Exception;
use FakeMaintenance;
use ImportStreamSource;
use Job;
use MediaWiki\MainConfigNames;
use MediaWiki\Permissions\UltimateAuthority;
use Miraheze\ImportDump\Hooks\ImportDumpHookRunner;
use Miraheze\ImportDump\ImportDumpRequestManager;
use Miraheze\ImportDump\ImportDumpStatus;
use RebuildRecentchanges;
use RebuildTextIndex;
use RefreshLinks;
use SiteStatsInit;
use SiteStatsUpdate;
use User;
use WikiImporterFactory;
use Wikimedia\Rdbms\ILBFactory;

class ImportDumpJob extends Job
	implements ImportDumpStatus {

	public const JOB_NAME = 'ImportDumpJob';

	/** @var int */
	private $requestID;

	/** @var ILBFactory */
	private $dbLoadBalancerFactory;

	/** @var ImportDumpHookRunner */
	private $importDumpHookRunner;

	/** @var ImportDumpRequestManager */
	private $importDumpRequestManager;

	/** @var Config */
	private $mainConfig;

	/** @var WikiImporterFactory */
	private $wikiImporterFactory;

	/**
	 * @param array $params
	 * @param ILBFactory $dbLoadBalancerFactory
	 * @param ImportDumpHookRunner $importDumpHookRunner
	 * @param ImportDumpRequestManager $importDumpRequestManager
	 * @param Config $mainConfig
	 * @param WikiImporterFactory $wikiImporterFactory
	 */
	public function __construct(
		array $params,
		ILBFactory $dbLoadBalancerFactory,
		ImportDumpHookRunner $importDumpHookRunner,
		ImportDumpRequestManager $importDumpRequestManager,
		Config $mainConfig,
		WikiImporterFactory $wikiImporterFactory
	) {
		parent::__construct( self::JOB_NAME, $params );

		$this->requestID = $params['requestid'];

		$this->dbLoadBalancerFactory = $dbLoadBalancerFactory;
		$this->importDumpHookRunner = $importDumpHookRunner;
		$this->importDumpRequestManager = $importDumpRequestManager;
		$this->mainConfig = $mainConfig;
		$this->wikiImporterFactory = $wikiImporterFactory;
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
			return false;
		}

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

		$this->importDumpRequestManager->setStatus( self::STATUS_INPROGRESS );

		try {
			$importer->doImport();

			$siteStatsInit = new SiteStatsInit();
			$siteStatsInit->refresh();

			SiteStatsUpdate::cacheUpdate( $dbw );

			$maintenance = new FakeMaintenance;
			if ( !$this->mainConfig->get( MainConfigNames::DisableSearchUpdate ) ) {
				$rebuildText = $maintenance->runChild( RebuildTextIndex::class, 'rebuildtextindex.php' );
				$rebuildText->execute();
			}

			$rebuildRC = $maintenance->runChild( RebuildRecentchanges::class, 'rebuildrecentchanges.php' );
			$rebuildRC->execute();

			$rebuildLinks = $maintenance->runChild( RefreshLinks::class, 'refreshLinks.php' );
			$rebuildLinks->execute();
		} catch ( Exception $ex ) {
			$this->importDumpRequestManager->setStatus( self::STATUS_FAILED );
			$this->setLastError( 'Import failed' );
			return false;
		}

		$this->importDumpRequestManager->setStatus( self::STATUS_COMPLETE );

		return true;
	}
}
