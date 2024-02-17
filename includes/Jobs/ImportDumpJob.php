<?php

namespace Miraheze\ImportDump\Jobs;

use GenericParameterJob;
use ImportStreamSource;
use Job;
use MediaWiki\Permissions\Authority;
use Miraheze\ImportDump\ImportDumpRequestManager;

class ImportDumpJob extends Job implements GenericParameterJob {

	/** @var Authority */
	private $authority;

	/** @var ImportDumpRequestManager */
	private $importDumpRequestManager;

	/** @var int */
	private $requestID;

	/**
	 * @param array $params
	 */
	public function __construct( array $params ) {
		parent::__construct( 'ImportDumpJob', $params );

		$this->authority = $params['authority'];
		$this->requestID = $params['requestid'];
		$this->importDumpRequestManager = $params['manager'];
	}

	/**
	 * @return bool
	 */
	public function run() {
		$this->importDumpRequestManager->fromID( $this->requestID );
		$file = $this->importDumpRequestManager->getTarget() . '-' . $this->importDumpRequestManager->getTimestamp() . '.xml',

		$importStreamSource = ImportStreamSource::newFromFile( $file );
		if ( !$importStreamSource->isGood() ) {
			$this->markFailed();
			$this->setLastError( "Import source for {$file} failed" );
			return false;
		}

		if ( version_compare( MW_VERSION, '1.42', '>=' ) ) {
			$importer = $this->wikiImporterFactory()->getWikiImporter(
				$importStreamSource->value, $this->authority
			);
		} else {

			$importer = $this->wikiImporterFactory()->getWikiImporter(
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

		return true;
	}
}
