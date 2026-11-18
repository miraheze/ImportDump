<?php

namespace Miraheze\ImportDump\Tests;

use MediaWiki\FileRepo\LocalRepo;
use MediaWiki\FileRepo\RepoGroup;
use MediaWiki\MainConfigNames;
use MediaWiki\User\ActorStoreFactory;
use MediaWiki\User\UserGroupManager;
use MediaWiki\User\UserGroupManagerFactory;
use MediaWiki\User\UserIdentityLookup;
use MediaWiki\User\UserIdentityValue;
use MediaWiki\WikiMap\WikiMap;
use MediaWikiIntegrationTestCase;
use Miraheze\ImportDump\ConfigNames;
use Miraheze\ImportDump\RequestManager;
use Miraheze\ImportDump\RequestStage;
use Miraheze\ImportDump\RequestStatus;
use Wikimedia\FileBackend\FileBackend;
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
	implements RequestStage, RequestStatus {

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

		$this->overrideConfigValue( MainConfigNames::LocalDatabases, [ 'requestimporttest' ] );

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
	 * @covers ::getStage
	 * @covers ::hasImported
	 * @covers ::setStage
	 */
	public function testStage(): void {
		$manager = $this->getRequestManager( id: 1 );
		$manager->setStage( '' );
		$this->assertSame( '', $manager->getStage() );
		$this->assertFalse( $manager->hasImported() );

		$manager->setStage( self::STAGE_IMPORT );
		$this->assertSame( self::STAGE_IMPORT, $manager->getStage() );
		$this->assertFalse( $manager->hasImported() );

		$manager->setStage( self::STAGE_SITESTATS );
		$this->assertTrue( $manager->hasImported() );

		$this->assertSame( self::STAGE_SITESTATS, $this->getRequestManager( id: 1 )->getStage() );
	}

	/**
	 * @covers ::setStatus
	 * @dataProvider provideStatusesThatClearStage
	 */
	public function testSetStatusClearsStage( string $status, bool $cleared ): void {
		$manager = $this->getRequestManager( id: 1 );
		$manager->setStage( self::STAGE_REFRESHLINKS );
		$manager->setStatus( $status );

		$this->assertSame(
			$cleared ? '' : self::STAGE_REFRESHLINKS,
			$this->getRequestManager( id: 1 )->getStage()
		);
	}

	public static function provideStatusesThatClearStage(): array {
		return [
			'pending' => [ self::STATUS_PENDING, true ],
			'complete' => [ self::STATUS_COMPLETE, true ],
			'partial' => [ self::STATUS_PARTIAL, false ],
			'failed' => [ self::STATUS_FAILED, false ],
		];
	}

	/**
	 * @covers ::getRemainingCommands
	 */
	public function testGetRemainingCommands(): void {
		$manager = $this->getRequestManager( id: 1 );
		$manager->setStage( self::STAGE_REFRESHLINKS );

		$commands = $manager->getRemainingCommands();
		$this->assertCount( 3, $commands );
		$this->assertStringContainsString( 'refreshLinks.php --wiki=requestimporttest', $commands[0] );
		$this->assertStringContainsString( 'initEditCount.php', $commands[1] );
		$this->assertStringContainsString( 'updateArticleCount.php --wiki=requestimporttest --update', $commands[2] );

		$manager->setStage( self::STAGE_AFTERIMPORT );
		$this->assertSame( [], $manager->getRemainingCommands() );
	}

	/**
	 * @covers ::getRemainingCommands
	 */
	public function testGetRemainingCommandsUsesConfiguredCommand(): void {
		$this->overrideConfigValue( ConfigNames::MaintenanceCommand, 'mwscript {script-name} {wiki} {arguments}' );

		$manager = $this->getRequestManager( id: 1 );
		$manager->setStage( self::STAGE_COUNTS );

		$this->assertSame(
			[
				'mwscript initEditCount requestimporttest',
				'mwscript updateArticleCount requestimporttest --update',
			],
			$manager->getRemainingCommands()
		);
	}

	/**
	 * @covers ::targetExists
	 */
	public function testTargetExists(): void {
		$this->assertTrue( $this->getRequestManager( id: 1 )->targetExists() );

		$this->overrideConfigValue( MainConfigNames::LocalDatabases, [] );
		$this->assertFalse( $this->getRequestManager( id: 1 )->targetExists() );
	}

	/**
	 * @covers ::getUserGroupsFromTarget
	 * @covers ::isPrivate
	 */
	public function testDeletedTargetDoesNotQueryTargetDatabase(): void {
		$this->overrideConfigValue( MainConfigNames::LocalDatabases, [] );

		$actorStoreFactory = $this->createMock( ActorStoreFactory::class );
		$actorStoreFactory->expects( $this->never() )->method( 'getUserIdentityLookup' );
		$this->setService( 'ActorStoreFactory', $actorStoreFactory );

		$manager = $this->getRequestManager( id: 1 );

		$this->assertSame(
			[ wfMessage( 'importdump-usergroups-none' )->text() ],
			$manager->getUserGroupsFromTarget()
		);

		$this->assertTrue( $manager->isPrivate( forced: false ) );
		$this->assertTrue( $manager->isPrivate( forced: true ) );
	}

	/**
	 * @covers ::getUserGroupsFromTarget
	 * @dataProvider provideUserGroupsFromTarget
	 */
	public function testGetUserGroupsFromTarget( ?array $groups, array $expected ): void {
		$lookup = $this->createMock( UserIdentityLookup::class );
		$lookup->method( 'getUserIdentityByName' )->willReturn(
			$groups === null ? null : new UserIdentityValue( 1, 'Test user' )
		);

		$actorStoreFactory = $this->createMock( ActorStoreFactory::class );
		$actorStoreFactory->method( 'getUserIdentityLookup' )->willReturn( $lookup );

		$groupManager = $this->createMock( UserGroupManager::class );
		$groupManager->method( 'getUserGroups' )->willReturn( $groups ?? [] );

		$groupManagerFactory = $this->createMock( UserGroupManagerFactory::class );
		$groupManagerFactory->method( 'getUserGroupManager' )->willReturn( $groupManager );

		$this->setService( 'ActorStoreFactory', $actorStoreFactory );
		$this->setService( 'UserGroupManagerFactory', $groupManagerFactory );

		$manager = $this->getRequestManager( id: 1 );

		$this->assertSame(
			array_map(
				static fn ( string $group ): string => $group === 'none' ?
					wfMessage( 'importdump-usergroups-none' )->text() : $group,
				$expected
			),
			$manager->getUserGroupsFromTarget()
		);
	}

	public static function provideUserGroupsFromTarget(): array {
		return [
			'user does not exist on the target' => [ null, [ 'none' ] ],
			'user has no groups' => [ [], [ 'none' ] ],
			'user has one group' => [ [ 'bureaucrat' ], [ 'bureaucrat' ] ],
			'user has several groups' => [ [ 'bureaucrat', 'sysop' ], [ 'bureaucrat', 'sysop' ] ],
		];
	}

	/**
	 * @covers ::getFileExpiryDays
	 * @covers ::isPastFileExpiry
	 * @covers ::hasFileExpired
	 * @dataProvider provideFileExpiry
	 */
	public function testFileExpiry(
		string $status,
		array $config,
		int $daysElapsed,
		int $expectedDays,
		bool $expectedPast
	): void {
		$this->overrideConfigValue( ConfigNames::FileExpiryDays, $config );

		$manager = $this->getRequestManager( id: 1 );
		$manager->setStatus( $status );

		ConvertibleTimestamp::setFakeTime(
			(int)wfTimestamp( TS_UNIX, $manager->getTimestamp() ) + $daysElapsed * 86400
		);

		$this->assertSame( $expectedDays, $manager->getFileExpiryDays() );
		$this->assertSame( $expectedPast, $manager->isPastFileExpiry() );

		// The file does not exist in this test.
		$this->assertSame( $expectedPast, $manager->hasFileExpired() );
	}

	public static function provideFileExpiry(): array {
		return [
			'past the retention period' => [ self::STATUS_COMPLETE, [ self::STATUS_COMPLETE => 7 ], 8, 7, true ],
			'exactly at the retention period' => [ self::STATUS_FAILED, [ self::STATUS_FAILED => 3 ], 3, 3, true ],
			'within the retention period' => [ self::STATUS_COMPLETE, [ self::STATUS_COMPLETE => 7 ], 6, 7, false ],
			'status is not configured' => [ self::STATUS_DECLINED, [ self::STATUS_COMPLETE => 7 ], 30, 0, false ],
			'status that never expires' => [ self::STATUS_PARTIAL, [ self::STATUS_PARTIAL => 1 ], 30, 0, false ],
			'nothing configured' => [ self::STATUS_COMPLETE, [], 30, 0, false ],
		];
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
			$this->assertStatusGood( $backend->prepare( [
				'dir' => FileBackend::parentStoragePath( $path ),
			] ) );

			$this->assertStatusGood( $backend->quickCreate( [
				'dst' => $path,
				'content' => 'test',
				'overwrite' => true,
			] ) );
		}

		$this->assertTrue( $manager->fileExists() );
		$this->assertTrue( $manager->deleteFile() );
		$this->assertFalse( $manager->fileExists() );
		$this->assertFalse( $backend->fileExists( [ 'src' => $manager->getArchiveFilePath() ] ) );

		$this->assertTrue( $manager->deleteFile() );
	}
}
