<?php

namespace Miraheze\ImportDump\Tests\Jobs;

use MediaWiki\MainConfigNames;
use MediaWiki\WikiMap\WikiMap;
use MediaWikiIntegrationTestCase;
use Miraheze\ImportDump\ConfigNames;
use Miraheze\ImportDump\ImportHeartbeat;
use Miraheze\ImportDump\Jobs\ImportDumpJob;
use Miraheze\ImportDump\Jobs\ImportDumpNotifyJob;
use Miraheze\ImportDump\RequestManager;
use Miraheze\ImportDump\RequestStatus;
use RuntimeException;
use Wikimedia\Timestamp\ConvertibleTimestamp;

/**
 * @group ImportDump
 * @group Database
 * @group medium
 * @covers \Miraheze\ImportDump\Jobs\ImportDumpJob
 */
class ImportDumpJobTest extends MediaWikiIntegrationTestCase
	implements RequestStatus {

	protected function setUp(): void {
		parent::setUp();

		$this->overrideConfigValues( [
			MainConfigNames::VirtualDomainsMapping => [
				'virtual-importdump' => [ 'db' => WikiMap::getCurrentWikiId() ],
			],
			ConfigNames::StalledImportHours => 6,
		] );
	}

	private function addRequest(): int {
		$dbw = $this->getServiceContainer()->getConnectionProvider()
			->getPrimaryDatabase( 'virtual-importdump' );

		$dbw->newInsertQueryBuilder()
			->insertInto( 'import_requests' )
			->row( [
				'request_source' => 'https://requestimporttest.com',
				'request_target' => 'requestimporttest',
				'request_reason' => 'test',
				'request_status' => self::STATUS_STARTING,
				'request_actor' => $this->getTestUser()->getUser()->getActorId(),
				'request_timestamp' => $dbw->timestamp( ConvertibleTimestamp::now() ),
			] )
			->caller( __METHOD__ )
			->execute();

		return $dbw->insertId();
	}

	private function newJob( int $requestID ): ImportDumpJob {
		$job = $this->getServiceContainer()->getJobFactory()->newJob( ImportDumpJob::JOB_NAME, [
			'requestid' => $requestID,
			'username' => $this->getTestUser()->getUser()->getName(),
		] );

		$this->assertInstanceOf( ImportDumpJob::class, $job );
		return $job;
	}

	private function getRequestManager( int $id ): RequestManager {
		$manager = $this->getServiceContainer()->getService( 'ImportDumpRequestManager' );
		'@phan-var RequestManager $manager';
		$manager->loadFromID( $id );
		return $manager;
	}

	private function getHeartbeat(): ImportHeartbeat {
		$heartbeat = $this->getServiceContainer()->getService( 'ImportDumpHeartbeat' );
		'@phan-var ImportHeartbeat $heartbeat';
		return $heartbeat;
	}

	private function assertFailureQueued(): void {
		$this->assertSame( 1, $this->getServiceContainer()->getJobQueueGroup()
			->get( ImportDumpNotifyJob::JOB_NAME )->getSize() );
	}

	public function testRunReportsFailureWhenFileFetchThrows(): void {
		$this->setTemporaryHook( 'ImportDumpJobGetFile', static function (): void {
			throw new RuntimeException( 'Download failed' );
		} );

		$id = $this->addRequest();
		$job = $this->newJob( $id );

		$this->assertTrue( $job->run() );
		$this->assertFailureQueued();
		$this->assertFalse( $this->getHeartbeat()->isAlive( $id ) );
	}

	public function testRunReportsFailureWhenSourceIsMissing(): void {
		$id = $this->addRequest();
		$job = $this->newJob( $id );

		$this->assertTrue( $job->run() );
		$this->assertStringContainsString( 'Import source', $job->getLastError() );
		$this->assertFailureQueued();
		$this->assertFalse( $this->getHeartbeat()->isAlive( $id ) );
	}

	public function testRunRemovesTemporaryFile(): void {
		$id = $this->addRequest();
		$path = wfTempDir() . '/' . $this->getRequestManager( $id )->getFileName();

		$this->setTemporaryHook( 'ImportDumpJobGetFile', static function ( string $filePath ): void {
			file_put_contents( $filePath, 'not xml' );
		} );

		$this->newJob( $id )->run();
		$this->assertFileDoesNotExist( $path );
	}

	public function testRunSkipsCompletedRequest(): void {
		$id = $this->addRequest();
		$this->getRequestManager( $id )->setStatus( self::STATUS_COMPLETE );

		$this->assertTrue( $this->newJob( $id )->run() );
		$this->assertSame( 0, $this->getServiceContainer()->getJobQueueGroup()
			->get( ImportDumpNotifyJob::JOB_NAME )->getSize() );
	}
}
