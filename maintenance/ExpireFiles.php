<?php

declare( strict_types = 1 );

namespace Miraheze\ImportDump\Maintenance;

use MediaWiki\Maintenance\Maintenance;
use MediaWiki\WikiMap\WikiMap;
use Miraheze\ImportDump\ConfigNames;
use Miraheze\ImportDump\Request\RequestManager;
use Miraheze\ImportDump\Request\RequestStatus;
use Miraheze\ImportDump\Request\RequestType;
use Wikimedia\Rdbms\IConnectionProvider;
use Wikimedia\Timestamp\ConvertibleTimestamp;
use Wikimedia\Timestamp\TimestampFormat;
use function count;
use function in_array;
use function is_array;
use function max;

class ExpireFiles extends Maintenance {

	private IConnectionProvider $connectionProvider;
	private RequestManager $requestManager;

	public function __construct() {
		parent::__construct();

		$this->addDescription(
			'Deletes the stored files of old import requests using $wgRequestImportFileExpiryDays.'
		);

		$this->addOption( 'type',
			'Only expire the files of requests of this type, which is images, xml or all. Defaults to all.',
			false, true
		);

		$this->addOption( 'dry-run', 'List the files that would be deleted without deleting them.' );

		$this->setBatchSize( 100 );
		$this->requireExtension( 'ImportDump' );
	}

	private function initServices(): void {
		$services = $this->getServiceContainer();
		$this->connectionProvider = $services->getConnectionProvider();
		$this->requestManager = $services->get( 'RequestImport.RequestManager' );
	}

	public function execute(): void {
		$this->initServices();

		$dbr = $this->connectionProvider->getReplicaDatabase( RequestManager::VIRTUAL_DOMAIN );
		if ( !WikiMap::isCurrentWikiDbDomain( $dbr->getDomainID() ) ) {
			$this->fatalError( 'This script must be run on the wiki that stores import requests.' );
		}

		$expiry = $this->getConfig()->get( ConfigNames::FileExpiryDays );
		if ( !is_array( $expiry ) || $expiry === [] ) {
			$this->output( "No expiry is configured in \$wgRequestImportFileExpiryDays. Nothing to do.\n" );
			return;
		}

		$type = (string)$this->getOption( 'type', 'all' );
		if ( $type !== 'all' && !RequestType::tryFrom( $type ) ) {
			$this->fatalError( "Unknown type '$type'. Use images, xml or all." );
		}

		$dryRun = $this->hasOption( 'dry-run' );
		$batchSize = max( 1, $this->getBatchSize() );

		$deleted = 0;
		$failed = 0;

		foreach ( $expiry as $status => $days ) {
			$expirable = RequestStatus::tryFrom( (string)$status );
			if ( !$expirable || !in_array( $expirable, RequestManager::EXPIRABLE_STATUSES, true ) ) {
				$this->output( "Skipping '$status'. Files for this status cannot be expired.\n" );
				continue;
			}

			if ( (int)$days <= 0 ) {
				continue;
			}

			$cutoff = $dbr->timestamp( (int)ConvertibleTimestamp::now( TimestampFormat::UNIX ) - (int)$days * 86400 );
			$lastId = 0;

			do {
				$ids = $dbr->newSelectQueryBuilder()
					->select( 'request_id' )
					->from( 'import_requests' )
					->where( [ 'request_status' => $status ] )
					->andWhere( $dbr->expr( 'request_timestamp', '<', $cutoff ) )
					->andWhere( $dbr->expr( 'request_id', '>', $lastId ) )
					->andWhere( $type === 'all' ? [] : [ 'request_type' => $type ] )
					->orderBy( 'request_id' )
					->limit( $batchSize )
					->caller( __METHOD__ )
					->fetchFieldValues();

				foreach ( $ids as $id ) {
					$lastId = (int)$id;

					$this->requestManager->loadFromID( $lastId );
					if ( !$this->requestManager->fileExists() ) {
						continue;
					}

					$fileName = $this->requestManager->getFileName();
					if ( $dryRun ) {
						$this->output( "Would delete $fileName (request #$lastId, $status).\n" );
						$deleted++;
						continue;
					}

					if ( !$this->requestManager->deleteFile() ) {
						$this->error( "Failed to delete $fileName (request #$lastId)." );
						$failed++;
						continue;
					}

					$this->output( "Deleted $fileName (request #$lastId, $status).\n" );
					$deleted++;
				}
			} while ( count( $ids ) === $batchSize );
		}

		$verb = $dryRun ? 'Would delete' : 'Deleted';
		$this->output( "$verb $deleted file(s). $failed failure(s).\n" );
	}
}

// @codeCoverageIgnoreStart
return ExpireFiles::class;
// @codeCoverageIgnoreEnd
