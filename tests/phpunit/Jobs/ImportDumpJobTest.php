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

	/**
	 * @return array[] The params of every queued notification, in queue order.
	 */
	private function popNotifications(): array {
		$queue = $this->getServiceContainer()->getJobQueueGroup()
			->get( ImportDumpNotifyJob::JOB_NAME );

		$notifications = [];
		while ( $job = $queue->pop() ) {
			$notifications[] = $job->getParams();
		}

		return $notifications;
	}

	/**
	 * @param array[] $notifications
	 * @return string[]
	 */
	private function getStatuses( array $notifications ): array {
		return array_column( $notifications, 'status' );
	}

	private function writeEmptyDump( string $filePath ): void {
		file_put_contents( $filePath,
			'<mediawiki xmlns="http://www.mediawiki.org/xml/export-0.11/" version="0.11" xml:lang="en">' .
			'<siteinfo><sitename>Test</sitename></siteinfo></mediawiki>'
		);
	}

	public function testRunReportsFailureWhenFileFetchThrows(): void {
		$this->setTemporaryHook( 'ImportDumpJobGetFile',
			static fn (): never => throw new RuntimeException( 'Download failed' )
		);

		$id = $this->addRequest();
		$job = $this->newJob( $id );

		$this->assertTrue( $job->run() );

		$notifications = $this->popNotifications();
		$this->assertSame( [ self::STATUS_FAILED ], $this->getStatuses( $notifications ) );
		$this->assertStringContainsString( 'Download failed', $notifications[0]['joberror'] );
		$this->assertFalse( $this->getHeartbeat()->isAlive( $id ) );
	}

	public function testGetImportSourceReportsFailureWhenSourceIsMissing(): void {
		$id = $this->addRequest();
		$job = $this->newJob( $id );

		$this->assertTrue( $job->run() );
		$this->assertStringContainsString( 'Import source', $job->getLastError() );

		$notifications = $this->popNotifications();
		$this->assertSame( [ self::STATUS_FAILED ], $this->getStatuses( $notifications ) );
		$this->assertStringContainsString( 'Import source', $notifications[0]['joberror'] );
		$this->assertFalse( $this->getHeartbeat()->isAlive( $id ) );
	}

	public function testRunCompletesEmptyDump(): void {
		$this->overrideConfigValue( MainConfigNames::DisableSearchUpdate, true );

		$afterImportCalled = false;
		$this->setTemporaryHook( 'ImportDumpJobGetFile', $this->writeEmptyDump( ... ) );
		$this->setTemporaryHook( 'ImportDumpJobAfterImport',
			static function () use ( &$afterImportCalled ): void {
				$afterImportCalled = true;
			}
		);

		$id = $this->addRequest();
		$path = wfTempDir() . '/' . $this->getRequestManager( $id )->getFileName();

		$this->assertTrue( $this->newJob( $id )->run() );
		$this->assertTrue( $afterImportCalled );

		$notifications = $this->popNotifications();
		$this->assertSame(
			[ self::STATUS_INPROGRESS, self::STATUS_COMPLETE ],
			$this->getStatuses( $notifications )
		);

		$this->assertSame( '', $notifications[1]['joberror'] );
		$this->assertSame( $id, $notifications[1]['requestid'] );
		$this->assertFileDoesNotExist( $path );
		$this->assertFalse( $this->getHeartbeat()->isAlive( $id ) );
	}

	public function testRunBeatsBeforeAfterImportHook(): void {
		$this->overrideConfigValue( MainConfigNames::DisableSearchUpdate, true );

		$id = $this->addRequest();
		$alive = null;

		$this->setTemporaryHook( 'ImportDumpJobGetFile', $this->writeEmptyDump( ... ) );
		$this->setTemporaryHook( 'ImportDumpJobAfterImport',
			function () use ( $id, &$alive ): void {
				$alive = $this->getHeartbeat()->isAlive( $id );
			}
		);

		$this->newJob( $id )->run();

		$this->assertTrue( $alive );
		$this->assertFalse( $this->getHeartbeat()->isAlive( $id ) );
	}

	public function testRunReportsFailureWhenAfterImportHookThrows(): void {
		$this->overrideConfigValue( MainConfigNames::DisableSearchUpdate, true );

		$this->setTemporaryHook( 'ImportDumpJobGetFile', $this->writeEmptyDump( ... ) );
		$this->setTemporaryHook( 'ImportDumpJobAfterImport',
			static fn (): never => throw new RuntimeException( 'After import failed' )
		);

		$id = $this->addRequest();
		$path = wfTempDir() . '/' . $this->getRequestManager( $id )->getFileName();

		$this->assertTrue( $this->newJob( $id )->run() );

		$notifications = $this->popNotifications();
		$this->assertSame(
			[ self::STATUS_INPROGRESS, self::STATUS_FAILED ],
			$this->getStatuses( $notifications )
		);

		$this->assertStringContainsString( 'RuntimeException', $notifications[1]['joberror'] );
		$this->assertStringContainsString( 'After import failed', $notifications[1]['joberror'] );
		$this->assertFileDoesNotExist( $path );
		$this->assertFalse( $this->getHeartbeat()->isAlive( $id ) );
	}

	public function testCleanupRemovesTemporaryFile(): void {
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
		$this->assertSame( [], $this->popNotifications() );
	}
}
