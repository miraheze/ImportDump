<?php

namespace Miraheze\ImportDump\Tests;

use MediaWiki\FileRepo\LocalRepo;
use MediaWiki\FileRepo\RepoGroup;
use MediaWiki\MainConfigNames;
use MediaWiki\WikiMap\WikiMap;
use MediaWikiIntegrationTestCase;
use Miraheze\ImportDump\RequestManager;
use Miraheze\ImportDump\RequestStatus;
use Wikimedia\FileBackend\FSFileBackend;
use Wikimedia\Mime\MimeAnalyzer;
use Wikimedia\Timestamp\ConvertibleTimestamp;

/**
 * @group ImportDump
 * @group Database
 * @group medium
 * @coversDefaultClass \Miraheze\ImportDump\RequestManager
 */
class RequestManagerTest extends MediaWikiIntegrationTestCase
	implements RequestStatus {

	public function addDBDataOnce(): void {
		$this->overrideConfigValue( MainConfigNames::VirtualDomainsMapping, [
			'virtual-importdump' => [ 'db' => 'wikidb' ],
		] );

		ConvertibleTimestamp::setFakeTime( ConvertibleTimestamp::now() );

		$connectionProvider = $this->getServiceContainer()->getConnectionProvider();
		$dbw = $connectionProvider->getPrimaryDatabase( 'virtual-importdump' );

		$dbw->newInsertQueryBuilder()
			->insertInto( 'import_requests' )
			->ignore()
			->row( [
				'request_source' => 'https://requestimporttest.com',
				'request_target' => 'requestimporttest',
				'request_reason' => 'test',
				'request_status' => self::STATUS_PENDING,
				'request_actor' => $this->getTestUser()->getUser()->getActorId(),
				'request_timestamp' => $this->db->timestamp(),
			] )
			->caller( __METHOD__ )
			->execute();
	}

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
			$this->getServiceContainer()->getMainWANObjectCache(),
			$this->createMock( MimeAnalyzer::class )
		) );
	}

	private function getRequestManager( int $id ): RequestManager {
		$manager = $this->getServiceContainer()->getService( 'ImportDumpRequestManager' );
		'@phan-var RequestManager $manager';
		$manager->loadFromID( $id );
		return $manager;
	}

	/**
	 * @covers ::__construct
	 */
	public function testConstructor(): void {
		$manager = $this->getServiceContainer()->getService( 'ImportDumpRequestManager' );
		$this->assertInstanceOf( RequestManager::class, $manager );
	}

	/**
	 * @covers ::loadFromID
	 */
	public function testLoadFromID(): void {
		$manager = $this->getRequestManager( id: 1 );
		$this->assertInstanceOf( RequestManager::class, $manager );
	}

	/**
	 * @covers ::exists
	 */
	public function testExists(): void {
		$manager = $this->getRequestManager( id: 1 );
		$this->assertTrue( $manager->exists() );

		$manager = $this->getRequestManager( id: 2 );
		$this->assertFalse( $manager->exists() );
	}

	/**
	 * @covers ::addComment
	 * @covers ::getComments
	 * @covers ::sendNotification
	 */
	public function testComments(): void {
		$manager = $this->getRequestManager( id: 1 );
		$this->assertArrayEquals( [], $manager->getComments() );

		$manager->addComment( 'Test', $this->getTestUser()->getUser() );
		$this->assertCount( 1, $manager->getComments() );
	}

	/**
	 * @covers ::setStatus
	 */
	public function testSetStatusRunsHook(): void {
		$calls = [];
		$this->setTemporaryHook( 'ImportDumpRequestStatusUpdate',
			static function (
				RequestManager $manager,
				string $newStatus,
				string $oldStatus
			) use ( &$calls ): void {
				$calls[] = [ $newStatus, $oldStatus ];
			}
		);

		$manager = $this->getRequestManager( id: 1 );
		$oldStatus = $manager->getStatus();
		$newStatus = $oldStatus === self::STATUS_DECLINED ?
			self::STATUS_PENDING : self::STATUS_DECLINED;

		$manager->setStatus( $newStatus );

		$this->assertSame( [ [ $newStatus, $oldStatus ] ], $calls );
	}

	/**
	 * @covers ::setStatus
	 */
	public function testSetStatusUnchangedSkipsHook(): void {
		$calls = 0;
		$this->setTemporaryHook( 'ImportDumpRequestStatusUpdate',
			static function () use ( &$calls ): void {
				$calls++;
			}
		);

		$manager = $this->getRequestManager( id: 1 );
		$manager->setStatus( $manager->getStatus() );

		$this->assertSame( 0, $calls );
	}

	/**
	 * @covers ::deleteFile
	 * @covers ::getArchiveFilePath
	 */
	public function testDeleteFile(): void {
		$manager = $this->getRequestManager( id: 1 );
		$backend = $this->getServiceContainer()->getRepoGroup()->getLocalRepo()->getBackend();

		$this->assertStringContainsString( '/ImportDump/archive/', $manager->getArchiveFilePath() );

		foreach ( [ $manager->getFilePath(), $manager->getArchiveFilePath() ] as $path ) {
			$status = $backend->quickCreate( [
				'dst' => $path,
				'content' => 'test',
				'overwrite' => true,
			] );

			$this->assertStatusGood( $status );
		}

		$this->assertTrue( $manager->fileExists() );
		$this->assertTrue( $manager->deleteFile() );
		$this->assertFalse( $manager->fileExists() );
		$this->assertFalse( $backend->fileExists( [ 'src' => $manager->getArchiveFilePath() ] ) );

		$this->assertTrue( $manager->deleteFile() );
	}
}
