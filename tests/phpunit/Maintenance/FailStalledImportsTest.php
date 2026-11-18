<?php

namespace Miraheze\ImportDump\Tests\Maintenance;

use MediaWiki\MainConfigNames;
use MediaWiki\Tests\Maintenance\MaintenanceBaseTestCase;
use MediaWiki\WikiMap\WikiMap;
use Miraheze\ImportDump\ConfigNames;
use Miraheze\ImportDump\ImportHeartbeat;
use Miraheze\ImportDump\Jobs\ImportDumpNotifyJob;
use Miraheze\ImportDump\Maintenance\FailStalledImports;
use Miraheze\ImportDump\RequestManager;
use Miraheze\ImportDump\RequestStatus;
use Wikimedia\Timestamp\ConvertibleTimestamp;

/**
 * @group ImportDump
 * @group Database
 * @group medium
 * @covers \Miraheze\ImportDump\Maintenance\FailStalledImports
 */
class FailStalledImportsTest extends MaintenanceBaseTestCase
	implements RequestStatus {

	/** @inheritDoc */
	protected function getMaintenanceClass(): string {
		return FailStalledImports::class;
	}

	protected function setUp(): void {
		parent::setUp();

		$this->overrideConfigValues( [
			MainConfigNames::VirtualDomainsMapping => [
				'virtual-importdump' => [ 'db' => WikiMap::getCurrentWikiId() ],
			],
			ConfigNames::StalledImportHours => 6,
		] );
	}

	private function getHeartbeat(): ImportHeartbeat {
		$heartbeat = $this->getServiceContainer()->getService( 'ImportDumpHeartbeat' );
		'@phan-var ImportHeartbeat $heartbeat';
		return $heartbeat;
	}

	private function getRequestManager( int $id ): RequestManager {
		$manager = $this->getServiceContainer()->getService( 'ImportDumpRequestManager' );
		'@phan-var RequestManager $manager';
		$manager->loadFromID( $id );
		return $manager;
	}

	private function addRequest( string $status, int $ageInHours ): int {
		$dbw = $this->getServiceContainer()->getConnectionProvider()
			->getPrimaryDatabase( 'virtual-importdump' );

		$dbw->newInsertQueryBuilder()
			->insertInto( 'import_requests' )
			->row( [
				'request_source' => 'https://requestimporttest.com',
				'request_target' => 'requestimporttest',
				'request_reason' => "test $status $ageInHours",
				'request_status' => $status,
				'request_actor' => $this->getTestUser()->getUser()->getActorId(),
				'request_timestamp' => $dbw->timestamp(
					(int)ConvertibleTimestamp::now( TS_UNIX ) - $ageInHours * 3600
				),
			] )
			->caller( __METHOD__ )
			->execute();

		return $dbw->insertId();
	}

	public function testFailsStalledImports(): void {
		$this->addRequest( self::STATUS_INPROGRESS, 10 );
		$alive = $this->addRequest( self::STATUS_INPROGRESS, 10 );
		$recent = $this->addRequest( self::STATUS_INPROGRESS, 1 );
		$pending = $this->addRequest( self::STATUS_PENDING, 10 );

		$this->getHeartbeat()->beat( $alive, 3600 );

		$this->expectOutputRegex( '/Marked 1 import\(s\) as failed\./' );
		$this->maintenance->execute();

		$this->assertSame( 1, $this->getServiceContainer()->getJobQueueGroup()
			->get( ImportDumpNotifyJob::JOB_NAME )->getSize() );

		$this->assertSame( self::STATUS_INPROGRESS, $this->getRequestManager( $alive )->getStatus() );
		$this->assertSame( self::STATUS_INPROGRESS, $this->getRequestManager( $recent )->getStatus() );
		$this->assertSame( self::STATUS_PENDING, $this->getRequestManager( $pending )->getStatus() );
	}

	public function testDryRun(): void {
		$stalled = $this->addRequest( self::STATUS_INPROGRESS, 10 );

		$this->maintenance->loadWithArgv( [ '--dry-run' ] );

		$this->expectOutputRegex( "/Would mark request #$stalled as failed\\.\\n.*Would mark 1 import/s" );
		$this->maintenance->execute();

		$this->assertSame( 0, $this->getServiceContainer()->getJobQueueGroup()
			->get( ImportDumpNotifyJob::JOB_NAME )->getSize() );
	}

	public function testBatchSize(): void {
		$this->addRequest( self::STATUS_INPROGRESS, 10 );
		$this->addRequest( self::STATUS_INPROGRESS, 11 );
		$this->addRequest( self::STATUS_INPROGRESS, 12 );

		$this->maintenance->setBatchSize( 1 );
		$this->assertSame( 1, $this->maintenance->getBatchSize() );

		$this->expectOutputRegex( '/Marked 3 import\(s\) as failed\./' );
		$this->maintenance->execute();
	}

	public function testNothingConfigured(): void {
		$this->overrideConfigValue( ConfigNames::StalledImportHours, null );
		$this->addRequest( self::STATUS_INPROGRESS, 100 );

		$this->expectOutputRegex( '/Nothing to do/' );
		$this->maintenance->execute();

		$this->assertSame( 0, $this->getServiceContainer()->getJobQueueGroup()
			->get( ImportDumpNotifyJob::JOB_NAME )->getSize() );
	}
}
