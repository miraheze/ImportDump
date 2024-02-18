<?php

namespace Miraheze\ImportDump\Jobs;

use Exception;
use FakeMaintenance;
use GenericParameterJob;
use ImportStreamSource;
use Job;
use MediaWiki\MainConfigNames;
use MediaWiki\MediaWikiServices;
use MediaWiki\Permissions\UltimateAuthority;
use RebuildRecentchanges;
use RebuildTextIndex;
use RefreshLinks;
use SiteStatsInit;
use SiteStatsUpdate;
use User;

class ImportDumpJob extends Job implements GenericParameterJob {

	/** @var int */
	private $requestID;

	/**
	 * @param array $params
	 */
	public function __construct( array $params ) {
		parent::__construct( 'ImportDumpJob', $params );

		$this->requestID = $params['requestid'];
	}

	/**
	 * @return bool
	 */
	public function run() {
		$services = MediaWikiServices::getInstance();
		$hookRunner = $services->get( 'ImportDumpHookRunner' );
		$importDumpRequestManager = $services->get( 'ImportDumpRequestManager' );
		$lbFactory = $services->getDBLoadBalancerFactory();
		$mainConfig = $services->getMainConfig();

		$dbw = $lbFactory->getMainLB()->getMaintenanceConnectionRef( DB_PRIMARY );

		$importDumpRequestManager->fromID( $this->requestID );
		$filePath = wfTempDir() . '/' . $importDumpRequestManager->getTarget() . '-' . $importDumpRequestManager->getTimestamp() . '.xml';

		$hookRunner->onImportDumpJobGetFile( $filePath );

		$importStreamSource = ImportStreamSource::newFromFile( $filePath );
		if ( !$importStreamSource->isGood() ) {
			$importDumpRequestManager->setStatus( 'failed' );
			$this->setLastError( "Import source for {$filePath} failed" );
			return false;
		}

		$user = User::newSystemUser( 'ImportDump Extension', [ 'steal' => true ] );

		if ( version_compare( MW_VERSION, '1.42', '>=' ) ) {
			// @phan-suppress-next-line PhanParamTooMany
			$importer = $services->getWikiImporterFactory()->getWikiImporter(
				$importStreamSource->value, new UltimateAuthority( $user )
			);
		} else {
			$importer = $services->getWikiImporterFactory()->getWikiImporter(
				$importStreamSource->value
			);
		}

		$importer->disableStatisticsUpdate();
		$importer->setNoUpdates( true );
		$importer->setUsernamePrefix(
			$importDumpRequestManager->getInterwikiPrefix(),
			true
		);

		$importDumpRequestManager->setStatus( 'inprogress' );

		try {
			$importer->doImport();

			$siteStatsInit = new SiteStatsInit();
			$siteStatsInit->refresh();

			SiteStatsUpdate::cacheUpdate( $dbw );

			$maintenance = new FakeMaintenance;

			if ( !$mainConfig->get( MainConfigNames::DisableSearchUpdate ) ) {
				$rebuildText = $maintenance->runChild( RebuildTextIndex::class, 'rebuildtextindex.php' );
				$rebuildText->execute();
			}

			$rebuildRC = $maintenance->runChild( RebuildRecentchanges::class, 'rebuildrecentchanges.php' );
			$rebuildRC->execute();

			$rebuildLinks = $maintenance->runChild( RefreshLinks::class, 'refreshLinks.php' );
			$rebuildLinks->execute();
		} catch ( Exception $ex ) {
			$importDumpRequestManager->setStatus( 'failed' );
			$this->setLastError( 'Import failed' );
			return false;
		}

		$importDumpRequestManager->setStatus( 'complete' );

		return true;
	}
}
