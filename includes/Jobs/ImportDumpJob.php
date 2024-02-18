<?php

namespace Miraheze\ImportDump\Jobs;

use Exception;
use GenericParameterJob;
use ImportStreamSource;
use Job;
use MediaWiki\MediaWikiServices;
use MediaWiki\Permissions\UltimateAuthority;

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

		$importDumpRequestManager->fromID( $this->requestID );
		$filePath = $importDumpRequestManager->getTarget() . '-' . $importDumpRequestManager->getTimestamp() . '.xml';

		$hookRunner->onImportDumpJobGetFile( $filePath );

		$importStreamSource = ImportStreamSource::newFromFile( $filePath );
		if ( !$importStreamSource->isGood() ) {
			$this->markFailed();
			$this->setLastError( "Import source for {$filePath} failed" );
			return false;
		}

		$user = $services->getUserFactory()->newSystemUser( 'ImportDump Extension', [ 'steal' => true ] );

		if ( version_compare( MW_VERSION, '1.42', '>=' ) ) {
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

		try {
			$importer->doImport();
		} catch ( Exception $ex ) {
			$this->markFailed();
			$this->setLastError( 'Import failed' );
			return false;
		}

		return true;
	}
}
