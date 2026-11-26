<?php

declare( strict_types = 1 );

namespace Miraheze\ImportDump\Tests\Maintenance;

use MediaWiki\MainConfigNames;
use MediaWiki\Tests\Maintenance\MaintenanceBaseTestCase;
use MediaWiki\WikiMap\WikiMap;
use Miraheze\ImportDump\ConfigNames;
use Miraheze\ImportDump\Jobs\ImportDumpNotifyJob;
use Miraheze\ImportDump\Maintenance\FailStalledImports;
use Miraheze\ImportDump\Progress\ImportHeartbeat;
use Miraheze\ImportDump\Request\RequestManager;
use Miraheze\ImportDump\Request\RequestStage;
use Miraheze\ImportDump\Request\RequestStatus;
use Wikimedia\TestingAccessWrapper;
use Wikimedia\Timestamp\ConvertibleTimestamp;
use Wikimedia\Timestamp\TimestampFormat;

/**
 * @group ImportDump
 * @group Database
 * @group medium
 * @covers \Miraheze\ImportDump\Maintenance\FailStalledImports
 */
class FailStalledImportsTest extends MaintenanceBaseTestCase {

	/** @inheritDoc */
	protected function getMaintenanceClass(): string {
		return FailStalledImports::class;
	}

	protected function setUp(): void {
		parent::setUp();

		$this->overrideConfigValues( [
			MainConfigNames::VirtualDomainsMapping => [
				'virtual-requestimport' => [ 'db' => WikiMap::getCurrentWikiId() ],
			],
			ConfigNames::StalledImportHours => 6,
		] );
	}

	private function getHeartbeat(): ImportHeartbeat {
		$heartbeat = $this->getServiceContainer()->getService( 'RequestImport.Heartbeat' );
		'@phan-var ImportHeartbeat $heartbeat';
		return $heartbeat;
	}

	private function getRequestManager( int $id ): RequestManager {
		$manager = $this->getServiceContainer()->getService( 'RequestImport.RequestManager' );
		'@phan-var RequestManager $manager';
		$manager->loadFromID( $id );
		return $manager;
	}

	private function addRequest( RequestStatus $status, int $ageInHours ): int {
		$dbw = $this->getServiceContainer()->getConnectionProvider()
			->getPrimaryDatabase( 'virtual-requestimport' );

		$dbw->newInsertQueryBuilder()
			->insertInto( 'import_requests' )
			->row( [
				'request_source' => 'https://requestimporttest.com',
				'request_target' => 'requestimporttest',
				'request_reason' => "test {$status->value} $ageInHours",
				'request_status' => $status->value,
				'request_actor' => $this->getTestUser()->getUser()->getActorId(),
				'request_timestamp' => $dbw->timestamp(
					(int)ConvertibleTimestamp::now( TimestampFormat::UNIX ) - $ageInHours * 3600
				),
			] )
			->caller( __METHOD__ )
			->execute();

		return $dbw->insertId();
	}

	public function testFailsStalledImports(): void {
		$this->addRequest( RequestStatus::InProgress, 10 );
		$alive = $this->addRequest( RequestStatus::InProgress, 10 );
		$recent = $this->addRequest( RequestStatus::InProgress, 1 );
		$pending = $this->addRequest( RequestStatus::Pending, 10 );

		$this->getHeartbeat()->beat( $alive, 3600 );

		$this->expectOutputRegex( '/Marked 1 import\(s\) as failed\./' );
		$this->maintenance->execute();

		$this->assertSame( 1, $this->getServiceContainer()->getJobQueueGroup()
			->get( ImportDumpNotifyJob::JOB_NAME )->getSize() );

		$this->assertSame( RequestStatus::InProgress, $this->getRequestManager( $alive )->getStatus() );
		$this->assertSame( RequestStatus::InProgress, $this->getRequestManager( $recent )->getStatus() );
		$this->assertSame( RequestStatus::Pending, $this->getRequestManager( $pending )->getStatus() );
	}

	public function testMarksImportedRequestsAsPartial(): void {
		$id = $this->addRequest( RequestStatus::InProgress, 10 );
		$this->getRequestManager( $id )->setStage( RequestStage::RefreshLinks );

		$this->expectOutputRegex( '/Marked 1 import\(s\)/' );
		$this->maintenance->execute();

		$queue = $this->getServiceContainer()->getJobQueueGroup()->get( ImportDumpNotifyJob::JOB_NAME );
		$job = $queue->pop();
		$this->assertSame( RequestStatus::Partial->value, $job->getParams()['status'] );
	}

	public function testDryRun(): void {
		$stalled = $this->addRequest( RequestStatus::InProgress, 10 );

		$this->maintenance->loadWithArgv( [ '--dry-run' ] );

		$this->expectOutputRegex( "/Would mark request #$stalled as failed\\.\\n.*Would mark 1 import/s" );
		$this->maintenance->execute();

		$this->assertSame( 0, $this->getServiceContainer()->getJobQueueGroup()
			->get( ImportDumpNotifyJob::JOB_NAME )->getSize() );
	}

	public function testBatchSize(): void {
		$this->addRequest( RequestStatus::InProgress, 10 );
		$this->addRequest( RequestStatus::InProgress, 11 );
		$this->addRequest( RequestStatus::InProgress, 12 );

		$maintenance = $this->maintenance;
		'@phan-var TestingAccessWrapper $maintenance';
		$maintenance->setBatchSize( 1 );
		$this->assertSame( 1, $maintenance->getBatchSize() );

		$this->expectOutputRegex( '/Marked 3 import\(s\) as failed\./' );
		$this->maintenance->execute();
	}

	public function testNothingConfigured(): void {
		$this->overrideConfigValue( ConfigNames::StalledImportHours, null );
		$this->addRequest( RequestStatus::InProgress, 100 );

		$this->expectOutputRegex( '/Nothing to do/' );
		$this->maintenance->execute();

		$this->assertSame( 0, $this->getServiceContainer()->getJobQueueGroup()
			->get( ImportDumpNotifyJob::JOB_NAME )->getSize() );
	}
}
