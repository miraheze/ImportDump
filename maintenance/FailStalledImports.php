<?php

declare( strict_types = 1 );

namespace Miraheze\ImportDump\Maintenance;

use MediaWiki\JobQueue\JobQueueGroupFactory;
use MediaWiki\JobQueue\JobSpecification;
use MediaWiki\Maintenance\Maintenance;
use MediaWiki\WikiMap\WikiMap;
use Miraheze\ImportDump\ConfigNames;
use Miraheze\ImportDump\Jobs\ImportDumpNotifyJob;
use Miraheze\ImportDump\Progress\ImportHeartbeat;
use Miraheze\ImportDump\Request\RequestManager;
use Miraheze\ImportDump\Request\RequestStatus;
use Wikimedia\Rdbms\IConnectionProvider;
use Wikimedia\Timestamp\ConvertibleTimestamp;
use Wikimedia\Timestamp\TimestampFormat;
use function count;
use function max;

class FailStalledImports extends Maintenance {

	private IConnectionProvider $connectionProvider;
	private ImportHeartbeat $heartbeat;
	private JobQueueGroupFactory $jobQueueGroupFactory;
	private RequestManager $requestManager;

	public function __construct() {
		parent::__construct();

		$this->addDescription(
			'Marks imports that stopped reporting progress as failed using $wgRequestImportStalledImportHours.'
		);

		$this->addOption( 'dry-run', 'List the imports that would be marked as failed without changing them.' );

		$this->setBatchSize( 100 );
		$this->requireExtension( 'ImportDump' );
	}

	private function initServices(): void {
		$services = $this->getServiceContainer();
		$this->connectionProvider = $services->getConnectionProvider();
		$this->heartbeat = $services->get( 'RequestImport.Heartbeat' );
		$this->jobQueueGroupFactory = $services->getJobQueueGroupFactory();
		$this->requestManager = $services->get( 'RequestImport.RequestManager' );
	}

	public function execute(): void {
		$this->initServices();

		$dbr = $this->connectionProvider->getReplicaDatabase( RequestManager::VIRTUAL_DOMAIN );
		if ( !WikiMap::isCurrentWikiDbDomain( $dbr->getDomainID() ) ) {
			$this->fatalError( 'This script must be run on the wiki that stores import requests.' );
		}

		$hours = (int)$this->getConfig()->get( ConfigNames::StalledImportHours );
		if ( $hours <= 0 ) {
			$this->output( "\$wgRequestImportStalledImportHours is not set. Nothing to do.\n" );
			return;
		}

		$dryRun = $this->hasOption( 'dry-run' );
		$batchSize = max( 1, $this->getBatchSize() );
		$cutoff = $dbr->timestamp( (int)ConvertibleTimestamp::now( TimestampFormat::UNIX ) - $hours * 3600 );

		$count = 0;
		$lastId = 0;

		do {
			$ids = $dbr->newSelectQueryBuilder()
				->select( 'request_id' )
				->from( 'import_requests' )
				->where( [ 'request_status' => RequestStatus::InProgress->value ] )
				->andWhere( $dbr->expr( 'request_timestamp', '<', $cutoff ) )
				->andWhere( $dbr->expr( 'request_id', '>', $lastId ) )
				->orderBy( 'request_id' )
				->limit( $batchSize )
				->caller( __METHOD__ )
				->fetchFieldValues();

			foreach ( $ids as $id ) {
				$lastId = (int)$id;

				if ( $this->heartbeat->isAlive( $lastId ) ) {
					continue;
				}

				$count++;
				if ( $dryRun ) {
					$this->output( "Would mark request #$lastId as failed.\n" );
					continue;
				}

				$this->requestManager->loadFromID( $lastId );
				$status = $this->requestManager->hasImported() ?
					RequestStatus::Partial :
					RequestStatus::Failed;

				$this->jobQueueGroupFactory->makeJobQueueGroup( $dbr->getDomainID() )->push(
					new JobSpecification(
						ImportDumpNotifyJob::JOB_NAME,
						[
							'joberror' => "The import did not report progress for $hours hours.",
							'requestid' => $lastId,
							'status' => $status->value,
							'username' => $this->requestManager->getRequester()->getName(),
						]
					)
				);

				$this->output( "Marked request #$lastId as failed.\n" );
			}
		} while ( count( $ids ) === $batchSize );

		$verb = $dryRun ? 'Would mark' : 'Marked';
		$this->output( "$verb $count import(s) as failed.\n" );
	}
}

// @codeCoverageIgnoreStart
return FailStalledImports::class;
// @codeCoverageIgnoreEnd
