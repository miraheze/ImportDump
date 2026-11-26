<?php

declare( strict_types = 1 );

namespace Miraheze\ImportDump\Tests\Jobs;

use MediaWiki\MainConfigNames;
use MediaWiki\WikiMap\WikiMap;
use MediaWikiIntegrationTestCase;
use Miraheze\ImportDump\ConfigNames;
use Miraheze\ImportDump\Jobs\ImportDumpJob;
use Miraheze\ImportDump\Jobs\ImportDumpNotifyJob;
use Miraheze\ImportDump\Progress\ImportHeartbeat;
use Miraheze\ImportDump\Request\RequestManager;
use Miraheze\ImportDump\Request\RequestStage;
use Miraheze\ImportDump\Request\RequestStatus;
use RuntimeException;
use Wikimedia\Rdbms\IConnectionProvider;
use Wikimedia\TestingAccessWrapper;
use Wikimedia\Timestamp\ConvertibleTimestamp;
use function array_column;
use function array_map;
use function array_unique;
use function array_values;
use function count;
use function file_put_contents;
use function wfTempDir;

/**
 * @group ImportDump
 * @group Database
 * @group medium
 * @covers \Miraheze\ImportDump\Jobs\ImportDumpJob
 */
class ImportDumpJobTest extends MediaWikiIntegrationTestCase {

	protected function setUp(): void {
		parent::setUp();

		$this->overrideConfigValues( [
			MainConfigNames::VirtualDomainsMapping => [
				'virtual-interwiki' => [ 'db' => WikiMap::getCurrentWikiId() ],
				'virtual-requestimport' => [ 'db' => WikiMap::getCurrentWikiId() ],
			],
			ConfigNames::StalledImportHours => 6,
		] );
	}

	private function addRequest(): int {
		$dbw = $this->getServiceContainer()->getConnectionProvider()
			->getPrimaryDatabase( 'virtual-requestimport' );

		$dbw->newInsertQueryBuilder()
			->insertInto( 'import_requests' )
			->row( [
				'request_source' => 'https://requestimporttest.com',
				'request_target' => WikiMap::getCurrentWikiId(),
				'request_reason' => 'test',
				'request_status' => RequestStatus::Starting->value,
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
		$manager = $this->getServiceContainer()->getService( 'RequestImport.RequestManager' );
		'@phan-var RequestManager $manager';
		$manager->loadFromID( $id );
		return $manager;
	}

	private function getHeartbeat(): ImportHeartbeat {
		$heartbeat = $this->getServiceContainer()->getService( 'RequestImport.Heartbeat' );
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
		for ( $job = $queue->pop(); $job; $job = $queue->pop() ) {
			$notifications[] = $job->getParams();
		}

		return $notifications;
	}

	/**
	 * @param array[] $notifications
	 * @return RequestStatus[]
	 */
	private function getStatuses( array $notifications ): array {
		return array_map( RequestStatus::from( ... ), array_column( $notifications, 'status' ) );
	}

	private function writeEmptyDump( string $filePath ): void {
		file_put_contents( $filePath,
			'<mediawiki xmlns="http://www.mediawiki.org/xml/export-0.11/" version="0.11" xml:lang="en">' .
			'<siteinfo><sitename>Test</sitename></siteinfo></mediawiki>'
		);
	}

	public function testRunReportsFailureWhenFileFetchThrows(): void {
		$this->setTemporaryHook( 'ImportDumpJobGetFile',
			static function (): never {
				throw new RuntimeException( 'Download failed' );
			}
		);

		$id = $this->addRequest();
		$job = $this->newJob( $id );

		$this->assertTrue( $job->run() );

		$notifications = $this->popNotifications();
		$this->assertSame( [ RequestStatus::Failed ], $this->getStatuses( $notifications ) );
		$this->assertStringContainsString( 'Download failed', $notifications[0]['joberror'] );
		$this->assertFalse( $this->getHeartbeat()->isAlive( $id ) );
	}

	public function testGetImportSourceReportsFailureWhenSourceIsMissing(): void {
		$id = $this->addRequest();
		$job = $this->newJob( $id );

		$this->assertTrue( $job->run() );
		$this->assertStringContainsString( 'Import source', $job->getLastError() );

		$notifications = $this->popNotifications();
		$this->assertSame( [ RequestStatus::Failed ], $this->getStatuses( $notifications ) );
		$this->assertStringContainsString( 'Import source', $notifications[0]['joberror'] );
		$this->assertFalse( $this->getHeartbeat()->isAlive( $id ) );
	}

	public function testRunCompletesEmptyDump(): void {
		$this->overrideConfigValue( MainConfigNames::DisableSearchUpdate, true );
		// The maintenance scripts print their progress.
		$this->expectOutputRegex( '/Done/' );

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
			[ RequestStatus::InProgress, RequestStatus::Complete ],
			$this->getStatuses( $notifications )
		);

		$this->assertSame( '', $notifications[1]['joberror'] );
		$this->assertSame( $id, $notifications[1]['requestid'] );
		$this->assertSame( RequestStage::AfterImport, $this->getRequestManager( $id )->getStage() );
		$this->assertFileDoesNotExist( $path );
		$this->assertFalse( $this->getHeartbeat()->isAlive( $id ) );
	}

	public function testRunUsesTheConfiguredTempDirectory(): void {
		$this->overrideConfigValue( MainConfigNames::DisableSearchUpdate, true );
		$this->expectOutputRegex( '/Done/' );

		$directory = $this->getNewTempDirectory() . '/importdump';
		$this->overrideConfigValue( ConfigNames::TempDirectory, $directory );

		$given = null;
		$this->setTemporaryHook( 'ImportDumpJobGetFile',
			function ( string $filePath ) use ( &$given ): void {
				$given = $filePath;
				$this->writeEmptyDump( $filePath );
			}
		);

		$id = $this->addRequest();
		$this->assertTrue( $this->newJob( $id )->run() );

		$this->assertSame( $directory . '/' . $this->getRequestManager( $id )->getFileName(), $given );
	}

	public function testStagesAreCommittedWithTheTicketTakenBeforeAnyWrites(): void {
		$this->overrideConfigValue( MainConfigNames::DisableSearchUpdate, true );
		$this->expectOutputRegex( '/Done/' );
		$this->setTemporaryHook( 'ImportDumpJobGetFile', $this->writeEmptyDump( ... ) );

		$id = $this->addRequest();
		$services = $this->getServiceContainer();
		$real = $services->getConnectionProvider();

		// Once changes are pending, a ticket can no longer be taken, and nothing is committed without one.
		$tickets = 0;
		$committed = [];
		$provider = $this->createMock( IConnectionProvider::class );
		$provider->method( 'getEmptyTransactionTicket' )->willReturnCallback(
			static function () use ( &$tickets ): ?string {
				return ++$tickets === 1 ? 'ticket' : null;
			}
		);
		$provider->method( 'commitAndWaitForReplication' )->willReturnCallback(
			/** @param string $fname @phan-unused-param */
			static function ( string $fname, mixed $ticket ) use ( &$committed ): bool {
				$committed[] = $ticket;
				return $ticket === 'ticket';
			}
		);

		$provider->method( 'getPrimaryDatabase' )->willReturnCallback( $real->getPrimaryDatabase( ... ) );
		$provider->method( 'getReplicaDatabase' )->willReturnCallback( $real->getReplicaDatabase( ... ) );

		$config = $services->getService( 'RequestImport.Config' );
		$heartbeat = $services->getService( 'RequestImport.Heartbeat' );
		$hookRunner = $services->getService( 'RequestImport.HookRunner' );
		$job = new ImportDumpJob(
			[ 'requestid' => $id, 'username' => $this->getTestUser()->getUser()->getName() ],
			$provider,
			$config,
			$heartbeat,
			$hookRunner,
			$this->getRequestManager( $id ),
			$services->getJobQueueGroupFactory(),
			$services->getWikiImporterFactory()
		);

		$this->assertTrue( $job->run() );
		$this->assertSame( 1, $tickets );
		$this->assertGreaterThan( 2, count( $committed ) );
		$this->assertSame( [ 'ticket' ], array_values( array_unique( $committed ) ) );
	}

	public function testRunBeatsBeforeAfterImportHook(): void {
		$this->overrideConfigValue( MainConfigNames::DisableSearchUpdate, true );
		// The maintenance scripts print their progress.
		$this->expectOutputRegex( '/Done/' );

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
		// The maintenance scripts print their progress.
		$this->expectOutputRegex( '/Done/' );

		$this->setTemporaryHook( 'ImportDumpJobGetFile', $this->writeEmptyDump( ... ) );
		$this->setTemporaryHook( 'ImportDumpJobAfterImport',
			static function (): never {
				throw new RuntimeException( 'After import failed' );
			}
		);

		$id = $this->addRequest();
		$path = wfTempDir() . '/' . $this->getRequestManager( $id )->getFileName();

		$this->assertTrue( $this->newJob( $id )->run() );

		$notifications = $this->popNotifications();
		$this->assertSame(
			[ RequestStatus::InProgress, RequestStatus::Partial ],
			$this->getStatuses( $notifications )
		);

		$this->assertStringContainsString( 'RuntimeException', $notifications[1]['joberror'] );
		$this->assertStringContainsString( 'After import failed', $notifications[1]['joberror'] );
		$this->assertSame( RequestStage::AfterImport, $this->getRequestManager( $id )->getStage() );
		$this->assertFileDoesNotExist( $path );
		$this->assertFalse( $this->getHeartbeat()->isAlive( $id ) );
	}

	public function testRunFailureBeforeImportLeavesDownloadStage(): void {
		$id = $this->addRequest();

		$this->assertTrue( $this->newJob( $id )->run() );
		$this->assertSame( [ RequestStatus::Failed ], $this->getStatuses( $this->popNotifications() ) );
		$this->assertSame( RequestStage::Download, $this->getRequestManager( $id )->getStage() );
	}

	public function testRunResumesAfterImportWithoutFetchingFile(): void {
		$this->overrideConfigValue( MainConfigNames::DisableSearchUpdate, true );
		// Only the stages from the counts stage onwards run, so recent changes is not rebuilt.
		$this->expectOutputRegex( '/\A(?!.*Rebuilding).*Counting articles/s' );

		$fileRequested = false;
		$afterImportCalled = false;
		$this->setTemporaryHook( 'ImportDumpJobGetFile',
			static function () use ( &$fileRequested ): void {
				$fileRequested = true;
			}
		);

		$this->setTemporaryHook( 'ImportDumpJobAfterImport',
			static function () use ( &$afterImportCalled ): void {
				$afterImportCalled = true;
			}
		);

		$id = $this->addRequest();
		$this->getRequestManager( $id )->setStage( RequestStage::Counts );

		$this->assertTrue( $this->newJob( $id )->run() );

		$this->assertFalse( $fileRequested );
		$this->assertTrue( $afterImportCalled );
		$this->assertSame(
			[ RequestStatus::InProgress, RequestStatus::Complete ],
			$this->getStatuses( $this->popNotifications() )
		);
	}

	public function testRunResumeFromLastStageOnlyRunsHook(): void {
		$this->expectOutputString( '' );

		$afterImportCalled = false;
		$this->setTemporaryHook( 'ImportDumpJobAfterImport',
			static function () use ( &$afterImportCalled ): void {
				$afterImportCalled = true;
			}
		);

		$id = $this->addRequest();
		$this->getRequestManager( $id )->setStage( RequestStage::AfterImport );

		$this->assertTrue( $this->newJob( $id )->run() );
		$this->assertTrue( $afterImportCalled );
		$this->assertSame( RequestStage::AfterImport, $this->getRequestManager( $id )->getStage() );
	}

	public function testRunResumeFailureStaysPartial(): void {
		$this->setTemporaryHook( 'ImportDumpJobAfterImport',
			static function (): never {
				throw new RuntimeException( 'Still failing' );
			}
		);

		$id = $this->addRequest();
		$this->getRequestManager( $id )->setStage( RequestStage::AfterImport );

		$this->assertTrue( $this->newJob( $id )->run() );
		$this->assertSame(
			[ RequestStatus::InProgress, RequestStatus::Partial ],
			$this->getStatuses( $this->popNotifications() )
		);
	}

	/** @dataProvider provideChunkSizes */
	public function testRefreshLinksCoversEveryPageAcrossChunks( int $chunkSize ): void {
		$this->overrideConfigValue( MainConfigNames::DisableSearchUpdate, true );
		// The maintenance scripts print their progress.
		$this->expectOutputRegex( '/Done/' );

		$pageIds = [];
		for ( $i = 1; $i <= 5; $i++ ) {
			$status = $this->editPage( "RefreshLinksChunk$i", "[[RefreshLinksTarget$i]]" );
			$pageIds[] = $status->getNewRevision()->getPageId();
		}

		$dbw = $this->getDb();
		$caller = __METHOD__;
		$getLinkedPages = static fn (): array => $dbw->newSelectQueryBuilder()
			->select( 'pl_from' )
			->from( 'pagelinks' )
			->where( [ 'pl_from' => $pageIds ] )
			->caller( $caller )
			->fetchFieldValues();

		$this->assertEqualsCanonicalizing( $pageIds, array_map( 'intval', $getLinkedPages() ) );

		$dbw->newDeleteQueryBuilder()
			->deleteFrom( 'pagelinks' )
			->where( [ 'pl_from' => $pageIds ] )
			->caller( __METHOD__ )
			->execute();

		$this->assertSame( [], $getLinkedPages() );

		$this->setTemporaryHook( 'ImportDumpJobGetFile', $this->writeEmptyDump( ... ) );

		$id = $this->addRequest();
		$job = $this->newJob( $id );
		TestingAccessWrapper::newFromObject( $job )->refreshLinksChunkSize = $chunkSize;
		$this->assertTrue( $job->run() );

		$this->assertEqualsCanonicalizing( $pageIds, array_map( 'intval', $getLinkedPages() ) );
		$this->assertSame(
			[ RequestStatus::InProgress, RequestStatus::Complete ],
			$this->getStatuses( $this->popNotifications() )
		);

		$progress = $this->getServiceContainer()->getService( 'RequestImport.ImportProgress' );
		'@phan-var \Miraheze\ImportDump\Progress\ImportProgress $progress';
		$total = (int)$dbw->newSelectQueryBuilder()
			->select( 'COUNT(*)' )
			->from( 'page' )
			->caller( __METHOD__ )
			->fetchField();

		$this->assertSame(
			[ 'stage' => RequestStage::RefreshLinks->value, 'percent' => 100, 'done' => $total, 'total' => $total ],
			$progress->get( $id )
		);
	}

	public static function provideChunkSizes(): array {
		return [
			'one page per chunk' => [ 1 ],
			'two pages per chunk' => [ 2 ],
			'three pages per chunk' => [ 3 ],
			'single chunk' => [ 1000 ],
		];
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
		$this->getRequestManager( $id )->setStatus( RequestStatus::Complete );

		$this->assertTrue( $this->newJob( $id )->run() );
		$this->assertSame( [], $this->popNotifications() );
	}
}
