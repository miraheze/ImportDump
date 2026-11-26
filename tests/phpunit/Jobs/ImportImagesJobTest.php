<?php

declare( strict_types = 1 );

namespace Miraheze\ImportDump\Tests\Jobs;

use MediaWiki\FileRepo\LocalRepo;
use MediaWiki\FileRepo\RepoGroup;
use MediaWiki\MainConfigNames;
use MediaWiki\WikiMap\WikiMap;
use MediaWikiIntegrationTestCase;
use Miraheze\ImportDump\ConfigNames;
use Miraheze\ImportDump\Jobs\ImportDumpNotifyJob;
use Miraheze\ImportDump\Jobs\ImportImagesJob;
use Miraheze\ImportDump\Jobs\ImportOutputStoreJob;
use Miraheze\ImportDump\Progress\ImportHeartbeat;
use Miraheze\ImportDump\Request\RequestManager;
use Miraheze\ImportDump\Request\RequestStatus;
use Miraheze\ImportDump\Request\RequestType;
use RuntimeException;
use stdClass;
use Wikimedia\FileBackend\FSFileBackend;
use Wikimedia\Mime\MimeAnalyzer;
use Wikimedia\ObjectCache\WANObjectCache;
use Wikimedia\Timestamp\ConvertibleTimestamp;
use function array_column;
use function array_map;
use function count;
use function file_put_contents;
use function is_dir;

/**
 * @group ImportDump
 * @group Database
 * @group medium
 * @covers \Miraheze\ImportDump\Jobs\ImportImagesJob
 */
class ImportImagesJobTest extends MediaWikiIntegrationTestCase {

	protected function setUp(): void {
		parent::setUp();

		$this->setService( 'RepoGroup', new RepoGroup(
			[
				'class' => LocalRepo::class,
				'name' => 'temp',
				'backend' => new FSFileBackend( [
					'name' => 'temp-backend',
					'wikiId' => WikiMap::getCurrentWikiId(),
					'basePath' => $this->getNewTempDirectory(),
				] ),
			],
			[],
			WANObjectCache::newEmpty(),
			$this->createMock( MimeAnalyzer::class )
		) );

		$this->overrideConfigValues( [
			MainConfigNames::VirtualDomainsMapping => [
				'virtual-requestimport' => [ 'db' => WikiMap::getCurrentWikiId() ],
			],
			ConfigNames::ImagesChunkSize => 500,
			ConfigNames::StalledImportHours => 6,
		] );
	}

	private function addRequest( RequestStatus $status ): int {
		$dbw = $this->getDb();
		$dbw->newInsertQueryBuilder()
			->insertInto( 'import_requests' )
			->row( [
				'request_source' => 'https://requestimporttest.com',
				'request_target' => WikiMap::getCurrentWikiId(),
				'request_reason' => 'test',
				'request_status' => $status->value,
				'request_actor' => $this->getTestUser()->getUser()->getActorId(),
				'request_timestamp' => $dbw->timestamp( ConvertibleTimestamp::now() ),
				'request_type' => RequestType::Images->value,
			] )
			->caller( __METHOD__ )
			->execute();

		return $dbw->insertId();
	}

	private function getRequestManager( int $id ): RequestManager {
		$manager = $this->getServiceContainer()->getService( 'RequestImport.RequestManager' );
		'@phan-var RequestManager $manager';
		$manager->loadFromID( $id );
		return $manager;
	}

	/**
	 * Makes a request that has the given files stored.
	 *
	 * @param list<string> $names
	 */
	private function addStoredRequest( array $names ): int {
		$id = $this->addRequest( RequestStatus::Starting );

		$source = $this->getNewTempFile();
		file_put_contents( $source, 'content' );

		$files = [];
		foreach ( $names as $name ) {
			$files[$name] = $source;
		}

		$percents = [];
		$status = $this->getRequestManager( $id )->publishExtractedImages(
			$files,
			static function ( int $percent ) use ( &$percents ): void {
				$percents[] = $percent;
			}
		);

		$this->assertStatusGood( $status );
		$this->assertNotSame( [], $percents );

		$this->getRequestManager( $id )->setFileStats( count( $files ), 7 );
		return $id;
	}

	private function newJob( int $requestID, int $offset ): ImportImagesJob {
		$job = $this->getServiceContainer()->getJobFactory()->newJob( ImportImagesJob::JOB_NAME, [
			'offset' => $offset,
			'requestid' => $requestID,
			'username' => $this->getTestUser()->getUser()->getName(),
		] );

		$this->assertInstanceOf( ImportImagesJob::class, $job );
		return $job;
	}

	private function getHeartbeat(): ImportHeartbeat {
		$heartbeat = $this->getServiceContainer()->getService( 'RequestImport.Heartbeat' );
		'@phan-var ImportHeartbeat $heartbeat';
		return $heartbeat;
	}

	/** @return array[] The params of every queued notification, in queue order. */
	private function popNotifications(): array {
		$queue = $this->getServiceContainer()->getJobQueueGroup()
			->get( ImportDumpNotifyJob::JOB_NAME );

		$notifications = [];
		for ( $job = $queue->pop(); $job; $job = $queue->pop() ) {
			$notifications[] = $job->getParams();
		}

		return $notifications;
	}

	/** @return array[] The params of every queued job that stores output, in queue order. */
	private function popStoredOutput(): array {
		$queue = $this->getServiceContainer()->getJobQueueGroup()
			->get( ImportOutputStoreJob::JOB_NAME );

		$stored = [];
		for ( $job = $queue->pop(); $job; $job = $queue->pop() ) {
			$stored[] = $job->getParams();
		}

		return $stored;
	}

	/**
	 * @param array[] $notifications
	 * @return RequestStatus[]
	 */
	private function getStatuses( array $notifications ): array {
		return array_map( RequestStatus::from( ... ), array_column( $notifications, 'status' ) );
	}

	/**
	 * Makes the files hook record what it was asked for, and stop the import there.
	 *
	 * @return stdClass With whether the directory existed, the offset, the limit and the request ID.
	 */
	private function recordHook(): stdClass {
		$asked = new stdClass();
		$asked->directory = false;
		$asked->offset = -1;
		$asked->limit = -1;
		$asked->request = 0;

		$this->setTemporaryHook( 'ImportImagesJobGetFiles',
			static function (
				string $directory,
				RequestManager $manager,
				int $offset,
				int $limit
			) use ( $asked ): never {
				$asked->directory = is_dir( $directory );
				$asked->offset = $offset;
				$asked->limit = $limit;
				$asked->request = $manager->getID();
				throw new RuntimeException( 'Stopped' );
			}
		);

		return $asked;
	}

	public function testCompleteRequestIsLeftAlone(): void {
		$id = $this->addRequest( RequestStatus::Complete );

		$this->assertTrue( $this->newJob( $id, offset: 0 )->run() );
		$this->assertSame( [], $this->popNotifications() );
	}

	public function testRequestWithoutFilesFails(): void {
		$id = $this->addRequest( RequestStatus::Starting );

		$this->assertTrue( $this->newJob( $id, offset: 0 )->run() );

		$notifications = $this->popNotifications();
		$this->assertSame( [ RequestStatus::InProgress, RequestStatus::Failed ], $this->getStatuses( $notifications ) );
		$this->assertFalse( $this->getHeartbeat()->isAlive( $id ) );

		$stored = $this->popStoredOutput();
		$this->assertCount( 2, $stored );
		$this->assertTrue( $stored[0]['reset'] );
		$this->assertSame( 0, $stored[1]['part'] );
	}

	public function testFailureOfTheHookIsReported(): void {
		$this->setTemporaryHook( 'ImportImagesJobGetFiles',
			static function (): never {
				throw new RuntimeException( 'Download failed' );
			}
		);

		$id = $this->addStoredRequest( [ 'a.png' ] );
		$this->assertTrue( $this->newJob( $id, offset: 0 )->run() );

		$notifications = $this->popNotifications();
		$this->assertSame( [ RequestStatus::InProgress, RequestStatus::Failed ], $this->getStatuses( $notifications ) );
		$this->assertStringContainsString( 'Download failed', $notifications[1]['joberror'] );
		$output = $this->getRequestManager( $id )->getImportOutput();
		$this->assertNotNull( $output );
		$this->assertStringContainsString( 'Download failed', $output['text'] );

		$stored = $this->popStoredOutput();
		$this->assertCount( 2, $stored );
		$this->assertSame( $id, $stored[0]['requestid'] );
		$this->assertTrue( $stored[0]['reset'] );
		$this->assertSame( $id, $stored[1]['requestid'] );
		$this->assertSame( 0, $stored[1]['part'] );
		$this->assertStringContainsString( 'Download failed', $stored[1]['text'] );
		$this->assertFalse( $this->getHeartbeat()->isAlive( $id ) );
	}

	public function testSmallRequestAsksForAllFiles(): void {
		$asked = $this->recordHook();

		$id = $this->addStoredRequest( [ 'a.png', 'b.png' ] );
		$this->assertTrue( $this->newJob( $id, offset: 0 )->run() );

		$this->assertTrue( $asked->directory );
		$this->assertSame( 0, $asked->offset );
		$this->assertSame( 0, $asked->limit );
		$this->assertSame( $id, $asked->request );
	}

	public function testLargeRequestAsksForItsChunk(): void {
		$this->overrideConfigValue( ConfigNames::ImagesChunkSize, 1 );

		$asked = $this->recordHook();

		$id = $this->addStoredRequest( [ 'a.png', 'b.png' ] );
		$this->assertTrue( $this->newJob( $id, offset: 0 )->run() );

		$this->assertTrue( $asked->directory );
		$this->assertSame( 0, $asked->offset );
		$this->assertSame( 1, $asked->limit );
		$notifications = $this->popNotifications();
		$this->assertSame( [ RequestStatus::InProgress, RequestStatus::Failed ], $this->getStatuses( $notifications ) );

		$this->assertTrue( $this->newJob( $id, offset: 1 )->run() );

		$this->assertSame( 1, $asked->offset );
		$this->assertSame( 1, $asked->limit );
		$this->assertSame( [ RequestStatus::Failed ], $this->getStatuses( $this->popNotifications() ) );
	}
}
