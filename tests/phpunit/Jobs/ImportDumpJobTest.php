<?php

namespace Miraheze\ImportDump\Tests\Jobs;

use MediaWiki\Config\Config;
use MediaWiki\Import\WikiImporterFactory;
use MediaWiki\JobQueue\JobQueueGroupFactory;
use MediaWiki\MainConfigNames;
use MediaWiki\WikiMap\WikiMap;
use MediaWikiIntegrationTestCase;
use Miraheze\ImportDump\ConfigNames;
use Miraheze\ImportDump\Hooks\HookRunner;
use Miraheze\ImportDump\ImportHeartbeat;
use Miraheze\ImportDump\Jobs\ImportDumpJob;
use Miraheze\ImportDump\Jobs\ImportDumpNotifyJob;
use Miraheze\ImportDump\RequestManager;
use Miraheze\ImportDump\RequestStatus;
use RuntimeException;
use Wikimedia\Rdbms\IConnectionProvider;
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
				'virtual-interwiki' => [ 'db' => WikiMap::getCurrentWikiId() ],
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
				'request_target' => WikiMap::getCurrentWikiId(),
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

	private function newChunkedJob( int $requestID, int $chunkSize ): ImportDumpJob {
		$services = $this->getServiceContainer();
		$config = $services->getService( 'ImportDumpConfig' );
		$heartbeat = $services->getService( 'ImportDumpHeartbeat' );
		$hookRunner = $services->getService( 'ImportDumpHookRunner' );
		$requestManager = $services->getService( 'ImportDumpRequestManager' );
		'@phan-var Config $config';
		'@phan-var ImportHeartbeat $heartbeat';
		'@phan-var HookRunner $hookRunner';
		'@phan-var RequestManager $requestManager';

		return new class(
			[
				'requestid' => $requestID,
				'username' => $this->getTestUser()->getUser()->getName(),
			],
			$services->getConnectionProvider(),
			$config,
			$heartbeat,
			$hookRunner,
			$requestManager,
			$services->getJobQueueGroupFactory(),
			$services->getWikiImporterFactory(),
			$chunkSize
		) extends ImportDumpJob {

			public function __construct(
				array $params,
				IConnectionProvider $connectionProvider,
				Config $config,
				ImportHeartbeat $heartbeat,
				HookRunner $hookRunner,
				RequestManager $requestManager,
				JobQueueGroupFactory $jobQueueGroupFactory,
				WikiImporterFactory $wikiImporterFactory,
				private readonly int $chunkSize,
			) {
				parent::__construct(
					$params,
					$connectionProvider,
					$config,
					$heartbeat,
					$hookRunner,
					$requestManager,
					$jobQueueGroupFactory,
					$wikiImporterFactory
				);
			}

			protected function getRefreshLinksChunkSize(): int {
				return $this->chunkSize;
			}
		};
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
		for ( $job = $queue->pop(); $job; $job = $queue->pop() ) {
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

	/** @dataProvider provideChunkSizes */
	public function testRefreshLinksCoversEveryPageAcrossChunks( int $chunkSize ): void {
		$this->overrideConfigValue( MainConfigNames::DisableSearchUpdate, true );

		$pageIds = [];
		for ( $i = 1; $i <= 5; $i++ ) {
			$status = $this->editPage( "RefreshLinksChunk$i", "[[RefreshLinksTarget$i]]" );
			$pageIds[] = $status->getNewRevision()->getPageId();
		}

		$dbw = $this->getDb();
		$getLinkedPages = static fn (): array => $dbw->newSelectQueryBuilder()
			->select( 'pl_from' )
			->from( 'pagelinks' )
			->where( [ 'pl_from' => $pageIds ] )
			->caller( __METHOD__ )
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
		$this->assertTrue( $this->newChunkedJob( $id, $chunkSize )->run() );

		$this->assertEqualsCanonicalizing( $pageIds, array_map( 'intval', $getLinkedPages() ) );
		$this->assertSame(
			[ self::STATUS_INPROGRESS, self::STATUS_COMPLETE ],
			$this->getStatuses( $this->popNotifications() )
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
		$this->getRequestManager( $id )->setStatus( self::STATUS_COMPLETE );

		$this->assertTrue( $this->newJob( $id )->run() );
		$this->assertSame( [], $this->popNotifications() );
	}
}
