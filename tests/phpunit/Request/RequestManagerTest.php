<?php

declare( strict_types = 1 );

namespace Miraheze\ImportDump\Tests\Request;

use Generator;
use MediaWiki\FileRepo\LocalRepo;
use MediaWiki\FileRepo\RepoGroup;
use MediaWiki\Logging\LogEntryBase;
use MediaWiki\MainConfigNames;
use MediaWiki\Notification\Notification;
use MediaWiki\Notification\NotificationService;
use MediaWiki\Notification\RecipientSet;
use MediaWiki\Permissions\SimpleAuthority;
use MediaWiki\User\ActorStoreFactory;
use MediaWiki\User\User;
use MediaWiki\User\UserGroupManager;
use MediaWiki\User\UserGroupManagerFactory;
use MediaWiki\User\UserIdentity;
use MediaWiki\User\UserIdentityLookup;
use MediaWiki\User\UserIdentityValue;
use MediaWiki\WikiMap\WikiMap;
use MediaWikiIntegrationTestCase;
use Miraheze\ImportDump\ConfigNames;
use Miraheze\ImportDump\Jobs\VerifyDumpJob;
use Miraheze\ImportDump\Notifications\AttentionNotifier;
use Miraheze\ImportDump\Notifications\RequestNotification;
use Miraheze\ImportDump\Request\RequestHistory;
use Miraheze\ImportDump\Request\RequestManager;
use Miraheze\ImportDump\Request\RequestStage;
use Miraheze\ImportDump\Request\RequestStatus;
use Miraheze\ImportDump\Request\RequestType;
use Miraheze\ImportDump\Requirements\RequirementProblem;
use Miraheze\ImportDump\Requirements\RequirementProblemKind;
use Miraheze\ImportDump\Tests\IsolatesManageWikiTrait;
use Miraheze\ImportDump\Upload\SkippedFile;
use stdClass;
use Wikimedia\FileBackend\FileBackend;
use Wikimedia\FileBackend\FSFileBackend;
use Wikimedia\Mime\MimeAnalyzer;
use Wikimedia\ObjectCache\WANObjectCache;
use Wikimedia\Timestamp\ConvertibleTimestamp;
use Wikimedia\Timestamp\TimestampFormat;
use function array_keys;
use function array_map;
use function file_get_contents;
use function file_put_contents;
use function strlen;
use function strpos;
use function substr;
use function wfMessage;
use function wfTimestamp;

/**
 * @group ImportDump
 * @group Database
 * @group medium
 * @coversDefaultClass \Miraheze\ImportDump\Request\RequestManager
 */
class RequestManagerTest extends MediaWikiIntegrationTestCase {

	use IsolatesManageWikiTrait;

	public function addDBDataOnce(): void {
		$this->overrideConfigValue( MainConfigNames::VirtualDomainsMapping, [
			'virtual-requestimport' => [ 'db' => 'wikidb' ],
		] );

		ConvertibleTimestamp::setFakeTime( ConvertibleTimestamp::now() );

		$connectionProvider = $this->getServiceContainer()->getConnectionProvider();
		$dbw = $connectionProvider->getPrimaryDatabase( 'virtual-requestimport' );

		$dbw->newInsertQueryBuilder()
			->insertInto( 'import_requests' )
			->ignore()
			->row( [
				'request_source' => 'https://requestimporttest.com',
				'request_target' => 'requestimporttest',
				'request_reason' => 'test',
				'request_status' => RequestStatus::Pending->value,
				'request_actor' => $this->getTestUser()->getUser()->getActorId(),
				'request_timestamp' => $dbw->timestamp(),
			] )
			->caller( __METHOD__ )
			->execute();
	}

	protected function setUp(): void {
		parent::setUp();
		$this->isolateFromManageWiki();
		$this->resetRequest();

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
			WANObjectCache::newEmpty(),
			$this->createMock( MimeAnalyzer::class )
		) );
	}

	/** The fixture is only added once, so undo what earlier tests did to it. */
	private function resetRequest(): void {
		$dbw = $this->getServiceContainer()
			->getConnectionProvider()
			->getPrimaryDatabase( RequestManager::VIRTUAL_DOMAIN );

		$dbw->newUpdateQueryBuilder()
			->update( 'import_requests' )
			->set( [
				'request_source' => 'https://requestimporttest.com',
				'request_target' => 'requestimporttest',
				'request_reason' => 'test',
				'request_status' => RequestStatus::Pending->value,
				'request_stage' => null,
				'request_history' => null,
				'request_requirements' => null,
				'request_skipped' => null,
				'request_locked' => 0,
				'request_private' => 0,
			] )
			->where( [ 'request_id' => 1 ] )
			->caller( __METHOD__ )
			->execute();

		$dbw->newDeleteQueryBuilder()
			->deleteFrom( 'import_request_comments' )
			->where( [ 'request_id' => 1 ] )
			->caller( __METHOD__ )
			->execute();
	}

	private function getRequestManager( int $id ): RequestManager {
		$manager = $this->getServiceContainer()->getService( 'RequestImport.RequestManager' );
		'@phan-var RequestManager $manager';
		$manager->loadFromID( $id );
		return $manager;
	}

	/**
	 * @covers ::__construct
	 */
	public function testConstructor(): void {
		$manager = $this->getServiceContainer()->getService( 'RequestImport.RequestManager' );
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
	 * @covers ::addHandler
	 * @covers ::getHandlers
	 * @covers ::getInvolvedUsers
	 */
	public function testHandlersAreInvolved(): void {
		$manager = $this->getRequestManager( id: 1 );
		$this->assertSame( [], $manager->getHandlers() );

		$first = $this->getMutableTestUser()->getUser();
		$second = $this->getMutableTestUser()->getUser();

		foreach ( [ $first, $first, $second ] as $handler ) {
			$manager->addHandler( $handler );
		}

		$names = array_map( static fn ( User $user ): string => $user->getName(), $manager->getHandlers() );
		$this->assertEqualsCanonicalizing( [ $first->getName(), $second->getName() ], $names );

		$involved = array_map(
			static fn ( User $user ): string => $user->getName(),
			$manager->getInvolvedUsers()
		);

		$this->assertContains( $first->getName(), $involved );
		$this->assertContains( $second->getName(), $involved );
		$this->assertContains( $manager->getRequester()->getName(), $involved );
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
	 * @covers ::logStatusUpdate
	 * @covers ::logStarted
	 */
	public function testLogsStoreRequestDataInsteadOfRenderedText(): void {
		$manager = $this->getRequestManager( id: 1 );
		$user = $this->getTestUser()->getUser();

		$manager->logStatusUpdate( 'Done', RequestStatus::Complete, $user );
		$manager->logStarted( $user );

		$rows = $this->getDb()->newSelectQueryBuilder()
			->select( [ 'log_action', 'log_params' ] )
			->from( 'logging' )
			->where( [ 'log_type' => 'requestimport' ] )
			->caller( __METHOD__ )
			->fetchResultSet();

		$params = [];
		foreach ( $rows as $row ) {
			'@phan-var stdClass $row';
			// @phan-suppress-next-line SecurityCheck-PHPSerializeInjection Classes are not allowed
			$params[$row->log_action] = LogEntryBase::extractParams( $row->log_params );
		}

		$this->assertSame( [
			'4:request-link:requestID' => 1,
			'5:request-status:requestStatus' => RequestStatus::Complete->value,
		], $params['statusupdate'] );

		$this->assertSame( [
			'4::requestTarget' => 'requestimporttest',
			'5:request-link:requestID' => 1,
		], $params['started'] );
	}

	/**
	 * @covers ::sendNotification
	 * @covers ::addComment
	 */
	public function testCommentNotifiesTheOthersInvolved(): void {
		$captured = [];
		$service = $this->createMock( NotificationService::class );
		$service->method( 'notify' )->willReturnCallback(
			static function ( Notification $notification, RecipientSet $recipients ) use ( &$captured ): void {
				$captured[] = [ $notification, $recipients ];
			}
		);

		$this->setService( 'NotificationService', $service );

		$manager = $this->getRequestManager( id: 1 );
		$requester = $manager->getRequester();
		$commenter = $this->getTestSysop()->getUser();

		$manager->addComment( 'Hello', $commenter );

		'@phan-var list<array{Notification, RecipientSet}> $captured';
		$this->assertCount( 1, $captured );
		[ $notification, $recipients ] = $captured[0];

		$this->assertInstanceOf( RequestNotification::class, $notification );
		$this->assertSame( 'requestimport-request-comment', $notification->getType() );
		$this->assertSame( $commenter->getName(), $notification->getAgent()->getName() );
		$this->assertSame( [ $requester->getName() ], array_map(
			static fn ( UserIdentity $user ): string => $user->getName(),
			$recipients->getRecipients()
		) );

		$properties = $notification->getProperties();
		$this->assertSame( 1, $properties['request-id'] );
		$this->assertSame( 'Hello', $properties['comment'] );
		$this->assertStringContainsString( 'RequestImportQueue/1', $properties['request-url'] );
	}

	/**
	 * @covers ::sendNotification
	 */
	public function testNoNotificationWhenNobodyElseIsInvolved(): void {
		$service = $this->createMock( NotificationService::class );
		$service->expects( $this->never() )->method( 'notify' );
		$this->setService( 'NotificationService', $service );

		$manager = $this->getRequestManager( id: 1 );
		$manager->addComment( 'Note to self', $manager->getRequester() );
	}

	/**
	 * @covers ::sendNotification
	 */
	public function testSystemUsersAreNeverNotified(): void {
		$captured = [];
		$service = $this->createMock( NotificationService::class );
		$service->method( 'notify' )->willReturnCallback(
			static function ( Notification $notification, RecipientSet $recipients ) use ( &$captured ): void {
				$captured[] = [ $notification, $recipients ];
			}
		);

		$this->setService( 'NotificationService', $service );

		$manager = $this->getRequestManager( id: 1 );
		$manager->addComment( 'Updated', User::newSystemUser( 'RequestImport Status Update' ) );
		$manager->addComment( 'Hello', $this->getTestSysop()->getUser() );

		'@phan-var list<array{Notification, RecipientSet}> $captured';
		$this->assertCount( 1, $captured );
		$this->assertSame( [ $manager->getRequester()->getName() ], array_map(
			static fn ( UserIdentity $user ): string => $user->getName(),
			$captured[0][1]->getRecipients()
		) );
	}

	/**
	 * @covers ::addComment
	 */
	public function testSystemUsersDoNotNotifyAboutComments(): void {
		$service = $this->createMock( NotificationService::class );
		$service->expects( $this->never() )->method( 'notify' );
		$this->setService( 'NotificationService', $service );

		$manager = $this->getRequestManager( id: 1 );
		$manager->addComment( 'Updated', User::newSystemUser( 'RequestImport Status Update' ) );
	}

	/**
	 * @covers ::setStatus
	 */
	public function testSetStatusRunsHook(): void {
		$calls = [];
		$this->setTemporaryHook( 'ImportDumpRequestStatusUpdate',
			static function (
				// @phan-suppress-next-line PhanUnusedClosureParameter
				RequestManager $manager,
				RequestStatus $newStatus,
				RequestStatus $oldStatus
			) use ( &$calls ): void {
				$calls[] = [ $newStatus, $oldStatus ];
			}
		);

		$manager = $this->getRequestManager( id: 1 );
		$oldStatus = $manager->getStatus();
		$newStatus = $oldStatus === RequestStatus::Declined ?
			RequestStatus::Pending : RequestStatus::Declined;

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
	 * @covers ::getHistory
	 */
	public function testGetHistory(): void {
		$this->assertNull( $this->getRequestManager( id: 1 )->getHistory() );

		$this->getDb()->newUpdateQueryBuilder()
			->update( 'import_requests' )
			->set( [ 'request_history' => RequestHistory::Partial->value ] )
			->where( [ 'request_id' => 1 ] )
			->caller( __METHOD__ )
			->execute();

		$this->assertSame( RequestHistory::Partial, $this->getRequestManager( id: 1 )->getHistory() );
	}

	/**
	 * @covers ::getStage
	 * @covers ::hasImported
	 * @covers ::setStage
	 */
	public function testStage(): void {
		$manager = $this->getRequestManager( id: 1 );
		$manager->setStage( null );
		$this->assertNull( $manager->getStage() );
		$this->assertFalse( $manager->hasImported() );

		$manager->setStage( RequestStage::Download );
		$this->assertFalse( $manager->hasImported() );

		$manager->setStage( RequestStage::Import );
		$this->assertSame( RequestStage::Import, $manager->getStage() );
		$this->assertFalse( $manager->hasImported() );

		$manager->setStage( RequestStage::SiteStats );
		$this->assertTrue( $manager->hasImported() );

		$this->assertSame( RequestStage::SiteStats, $this->getRequestManager( id: 1 )->getStage() );
	}

	/**
	 * @covers ::setStatus
	 * @dataProvider provideStatusesThatClearStage
	 */
	public function testSetStatusClearsStage( RequestStatus $status, bool $cleared ): void {
		$manager = $this->getRequestManager( id: 1 );
		$manager->setStage( RequestStage::RefreshLinks );
		$manager->setStatus( $status );

		$this->assertSame(
			$cleared ? null : RequestStage::RefreshLinks,
			$this->getRequestManager( id: 1 )->getStage()
		);
	}

	public static function provideStatusesThatClearStage(): array {
		return [
			'pending' => [ RequestStatus::Pending, true ],
			'complete' => [ RequestStatus::Complete, true ],
			'partial' => [ RequestStatus::Partial, false ],
			'failed' => [ RequestStatus::Failed, false ],
		];
	}

	private function addImagesRequest( int $linkedID ): int {
		$dbw = $this->getDb();
		$dbw->newInsertQueryBuilder()
			->insertInto( 'import_requests' )
			->row( [
				'request_source' => 'https://requestimporttest.com',
				'request_target' => 'requestimporttest',
				'request_reason' => 'test',
				'request_status' => RequestStatus::Pending->value,
				'request_actor' => $this->getTestUser()->getUser()->getActorId(),
				'request_timestamp' => $dbw->timestamp(),
				'request_type' => RequestType::Images->value,
				'request_linked_id' => $linkedID,
			] )
			->caller( __METHOD__ )
			->execute();

		return $dbw->insertId();
	}

	/**
	 * @covers ::getType
	 * @covers ::getLinkedID
	 * @covers ::getLinkedIDs
	 */
	public function testRequestsWithoutImagesAreNotLinked(): void {
		$manager = $this->getRequestManager( id: 1 );

		$this->assertSame( RequestType::Xml, $manager->getType() );
		$this->assertNull( $manager->getLinkedID() );
		$this->assertSame( [], $manager->getLinkedIDs() );
		$this->assertStringEndsWith( '.xml', $manager->getFileName() );
	}

	/**
	 * @covers ::getType
	 * @covers ::getLinkedID
	 * @covers ::getLinkedIDs
	 * @covers ::getLinkedStatus
	 */
	public function testLinkedRequestsPointAtEachOther(): void {
		$imagesID = $this->addImagesRequest( linkedID: 1 );

		$images = $this->getRequestManager( $imagesID );
		$this->assertSame( RequestType::Images, $images->getType() );
		$this->assertSame( 1, $images->getLinkedID() );
		$this->assertSame( [ 1 ], $images->getLinkedIDs() );
		$this->assertSame( RequestStatus::Pending, $images->getLinkedStatus( 1 ) );
		$this->assertNull( $images->getLinkedStatus( 9999 ) );

		$xml = $this->getRequestManager( id: 1 );
		$this->assertSame( [ $imagesID ], $xml->getLinkedIDs() );
	}

	/**
	 * @covers ::getFileName
	 * @covers ::getRemainingCommands
	 * @covers ::hasImported
	 */
	public function testImagesRequestHasItsOwnFiles(): void {
		$images = $this->getRequestManager( $this->addImagesRequest( linkedID: 1 ) );

		$this->assertStringEndsWith( '-images', $images->getFileName() );

		$images->setStage( RequestStage::Images );
		$this->assertFalse( $images->hasImported() );
		$this->assertSame( [], $images->getRemainingCommands() );
	}

	/**
	 * @covers ::publishExtractedImages
	 * @covers ::fileExists
	 * @covers ::getFileSize
	 * @covers ::getFileCount
	 * @covers ::setFileStats
	 * @covers ::getImageObjectNames
	 * @covers ::deleteFile
	 */
	public function testImagesAreStoredAndDeleted(): void {
		$images = $this->getRequestManager( $this->addImagesRequest( linkedID: 1 ) );
		$this->assertFalse( $images->fileExists() );
		$this->assertNull( $images->getFileCount() );

		$first = $this->getNewTempFile();
		$second = $this->getNewTempFile();
		file_put_contents( $first, 'abc' );
		file_put_contents( $second, 'de' );

		$percents = [];
		$this->assertStatusGood( $images->publishExtractedImages(
			[ 'b.png' => $second, 'a.png' => $first ],
			static function ( int $percent ) use ( &$percents ): void {
				$percents[] = $percent;
			}
		) );

		$this->assertSame( [ 100 ], $percents );
		$this->assertTrue( $images->fileExists() );
		$this->assertSame( 5, $images->getFileSize() );

		$names = $images->getImageObjectNames();
		$this->assertCount( 2, $names );
		$this->assertStringEndsWith( '/a.png', $names[0] );
		$this->assertStringEndsWith( '/b.png', $names[1] );

		$images->setFileStats( 2, 7 );
		$this->assertSame( 2, $images->getFileCount() );
		$this->assertSame( 7, $images->getFileSize() );

		$this->assertTrue( $images->deleteFile() );
		$this->assertFalse( $images->fileExists() );
		$this->assertSame( [], $images->getImageObjectNames() );
	}

	/**
	 * @covers ::publishExtractedImages
	 * @covers ::deleteFile
	 * @covers ::getImageObjectNames
	 */
	public function testManyImagesAreStoredAndDeletedInBatches(): void {
		$images = $this->getRequestManager( $this->addImagesRequest( linkedID: 1 ) );

		$source = $this->getNewTempFile();
		file_put_contents( $source, 'a' );

		$files = [];
		for ( $i = 0; $i < 101; $i++ ) {
			$files["image-$i.png"] = $source;
		}

		$percents = [];
		$this->assertStatusGood( $images->publishExtractedImages(
			$files,
			static function ( int $percent ) use ( &$percents ): void {
				$percents[] = $percent;
			}
		) );

		$this->assertSame( [ 99, 100 ], $percents );
		$this->assertCount( 101, $images->getImageObjectNames() );
		$this->assertSame( 101, $images->getFileSize() );

		$this->assertTrue( $images->deleteFile() );
		$this->assertFalse( $images->fileExists() );
		$this->assertSame( [], $images->getImageObjectNames() );
	}

	/**
	 * @covers ::getImageObjectNames
	 * @covers ::getFileCount
	 */
	public function testXmlRequestsHaveNoImageFiles(): void {
		$manager = $this->getRequestManager( id: 1 );

		$this->assertSame( [], $manager->getImageObjectNames() );
		$this->assertNull( $manager->getFileCount() );
	}

	/**
	 * @covers ::appendImportOutput
	 * @covers ::getImportOutput
	 * @covers ::clearImportOutput
	 */
	public function testImportOutput(): void {
		$manager = $this->getRequestManager( id: 1 );
		$this->assertNull( $manager->getImportOutput() );

		$manager->appendImportOutput( 2, 'a' );
		$manager->appendImportOutput( 2, 'b' );
		$this->assertSame( [ 'part' => 2, 'text' => 'ab' ], $manager->getImportOutput() );

		$manager->clearImportOutput();
		$this->assertNull( $manager->getImportOutput() );
	}

	/**
	 * @covers ::storeOutputPart
	 * @covers ::getOutputDirectory
	 * @covers ::getOutputParts
	 * @covers ::getOutputPart
	 * @covers ::deleteFile
	 */
	public function testOutputPartsAreStoredAndDeleted(): void {
		$images = $this->getRequestManager( $this->addImagesRequest( linkedID: 1 ) );
		$this->assertSame( [], $images->getOutputParts() );
		$this->assertNull( $images->getOutputPart( 0 ) );

		$this->assertStatusGood( $images->storeOutputPart( 1, "b\nc\n" ) );
		$this->assertStatusGood( $images->storeOutputPart( 0, 'a' ) );

		$this->assertSame(
			[ [ 'part' => 0, 'lines' => 1 ], [ 'part' => 1, 'lines' => 2 ] ],
			$images->getOutputParts()
		);

		$this->assertSame( 'a', $images->getOutputPart( 0 ) );
		$this->assertSame( "b\nc\n", $images->getOutputPart( 1 ) );
		$this->assertNull( $images->getOutputPart( 2 ) );

		$this->assertTrue( $images->deleteFile() );
		$this->assertSame( [], $images->getOutputParts() );
	}

	/**
	 * @covers ::clearStoredOutput
	 */
	public function testClearingTheStoredOutputRemovesTheParts(): void {
		$images = $this->getRequestManager( $this->addImagesRequest( linkedID: 1 ) );
		$this->assertStatusGood( $images->storeOutputPart( 0, 'a' ) );
		$this->assertStatusGood( $images->storeOutputPart( 1, 'b' ) );

		$this->assertTrue( $images->clearStoredOutput() );

		$this->assertSame( [], $images->getOutputParts() );
	}

	/**
	 * @covers ::getRemainingCommands
	 */
	public function testGetRemainingCommands(): void {
		$manager = $this->getRequestManager( id: 1 );
		$manager->setStage( RequestStage::RefreshLinks );

		$commands = $manager->getRemainingCommands();
		$this->assertCount( 3, $commands );
		$this->assertStringContainsString( 'refreshLinks.php --wiki=requestimporttest', $commands[0] );
		$this->assertStringContainsString( 'initEditCount.php', $commands[1] );
		$this->assertStringContainsString( 'updateArticleCount.php --wiki=requestimporttest --update', $commands[2] );

		$manager->setStage( RequestStage::AfterImport );
		$this->assertSame( [], $manager->getRemainingCommands() );
	}

	/**
	 * @covers ::getRemainingCommands
	 */
	public function testGetRemainingCommandsUsesConfiguredCommand(): void {
		$this->overrideConfigValue( ConfigNames::MaintenanceCommand, 'mwscript {script-name} {wiki} {arguments}' );

		$manager = $this->getRequestManager( id: 1 );
		$manager->setStage( RequestStage::Counts );

		$this->assertSame(
			[
				'mwscript initEditCount requestimporttest',
				'mwscript updateArticleCount requestimporttest --update',
			],
			$manager->getRemainingCommands()
		);
	}

	/**
	 * @covers ::getImportComment
	 */
	public function testImportCommentCannotMakeMarkupFromTheSource(): void {
		$dbw = $this->getDb();
		$dbw->newInsertQueryBuilder()
			->insertInto( 'import_requests' )
			->row( [
				'request_source' => 'https://example.com/[[Category:A]]{{b}}<c>|__NOTOC__ d',
				'request_target' => 'requestimporttest',
				'request_reason' => 'test',
				'request_status' => RequestStatus::Pending->value,
				'request_actor' => $this->getTestUser()->getUser()->getActorId(),
				'request_timestamp' => $dbw->timestamp(),
			] )
			->caller( __METHOD__ )
			->execute();

		$comment = $this->getRequestManager( $dbw->insertId() )->getImportComment();

		$this->assertStringStartsWith( 'Imported from https://example.com/', $comment );
		$source = substr( $comment, 0, (int)strpos( $comment, ' in ' ) );
		foreach ( [ '[', ']', '{', '}', '<', '>', '|', '__', ' ' ] as $markup ) {
			$this->assertStringNotContainsString( $markup, substr( $source, strlen( 'Imported from ' ) ) );
		}
	}

	/**
	 * @covers ::getImportComment
	 */
	public function testImportCommentNamesTheSourceAndTheRequest(): void {
		$this->overrideConfigValue( MainConfigNames::LanguageCode, 'qqx' );
		$comment = $this->getRequestManager( id: 1 )->getImportComment();

		$this->assertMatchesRegularExpression(
			'~^\(requestimport-images-comment(?: = \S+)?: https://requestimporttest\.com, ' .
				'\S+Special:RequestImportQueue/1, 1\)$|' .
				'^\(requestimport-images-comment-plain(?: = \S+)?: https://requestimporttest\.com, 1\)$~',
			$comment
		);
	}

	/**
	 * @covers ::getCommand
	 * @covers ::getImportComment
	 */
	public function testImagesCommandHasAnEscapedComment(): void {
		$this->overrideConfigValue( MainConfigNames::LocalDatabases, [] );

		$images = $this->getRequestManager( $this->addImagesRequest( linkedID: 1 ) );
		$command = $images->getCommand();

		$this->assertStringContainsString(
			"--comment='Imported from https://requestimporttest.com in ",
			$command
		);
		$this->assertStringNotContainsString( '{comment}', $command );
	}

	/**
	 * @covers ::getCommand
	 */
	public function testCommandWithoutACommentPlaceholder(): void {
		$this->overrideConfigValues( [
			ConfigNames::ImagesCommand => 'import {file-path}',
			MainConfigNames::LocalDatabases => [],
		] );

		$images = $this->getRequestManager( $this->addImagesRequest( linkedID: 1 ) );

		$this->assertStringNotContainsString( 'Imported from', $images->getCommand() );
	}

	/**
	 * @covers ::getCommand
	 */
	public function testImagesCommandOnlySkipsDuplicatesWhenTheRequestDoes(): void {
		$this->overrideConfigValue( MainConfigNames::LocalDatabases, [] );

		$images = $this->getRequestManager( $this->addImagesRequest( linkedID: 1 ) );
		$images->setSkipDupes( false );
		$this->assertStringNotContainsString( '--skip-dupes', $images->getCommand() );
		$this->assertStringNotContainsString( '{skip-dupes}', $images->getCommand() );

		$images->setSkipDupes( true );
		$this->assertStringContainsString( ' --skip-dupes --comment=', $images->getCommand() );
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
	 * @covers ::isTargetPrivate
	 */
	public function testDeletedTargetDoesNotQueryTargetDatabase(): void {
		$this->overrideConfigValue( MainConfigNames::LocalDatabases, [] );

		$realFactory = $this->getServiceContainer()->getActorStoreFactory();
		$actorStoreFactory = $this->createMock( ActorStoreFactory::class );
		$actorStoreFactory->method( 'getUserIdentityLookup' )->willReturnCallback(
			function ( string|false $wikiId ) use ( $realFactory ) {
				$this->assertNotSame( 'requestimporttest', $wikiId );
				return $realFactory->getUserIdentityLookup( $wikiId );
			}
		);

		$this->setService( 'ActorStoreFactory', $actorStoreFactory );

		$manager = $this->getRequestManager( id: 1 );

		$this->assertSame(
			[ wfMessage( 'requestimport-usergroups-none' )->text() ],
			$manager->getUserGroupsFromTarget()
		);

		$this->assertTrue( $manager->isPrivate() );
		$this->assertTrue( $manager->isTargetPrivate() );
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
					wfMessage( 'requestimport-usergroups-none' )->text() : $group,
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
		RequestStatus $status,
		array $config,
		int $daysElapsed,
		int $expectedDays,
		bool $expectedPast
	): void {
		$this->overrideConfigValue( ConfigNames::FileExpiryDays, $config );

		$manager = $this->getRequestManager( id: 1 );
		$manager->setStatus( $status );

		ConvertibleTimestamp::setFakeTime(
			(int)wfTimestamp( TimestampFormat::UNIX, $manager->getTimestamp() ) + $daysElapsed * 86400
		);

		$this->assertSame( $expectedDays, $manager->getFileExpiryDays() );
		$this->assertSame( $expectedPast, $manager->isPastFileExpiry() );

		// The file does not exist in this test.
		$this->assertSame( $expectedPast, $manager->hasFileExpired() );
	}

	public static function provideFileExpiry(): array {
		$complete = [ RequestStatus::Complete->value => 7 ];
		return [
			'past the retention period' => [ RequestStatus::Complete, $complete, 8, 7, true ],
			'exactly at the retention period' => [
				RequestStatus::Failed, [ RequestStatus::Failed->value => 3 ], 3, 3, true,
			],
			'within the retention period' => [ RequestStatus::Complete, $complete, 6, 7, false ],
			'status is not configured' => [ RequestStatus::Declined, $complete, 30, 0, false ],
			'status that never expires' => [
				RequestStatus::Partial, [ RequestStatus::Partial->value => 1 ], 30, 0, false,
			],
			'nothing configured' => [ RequestStatus::Complete, [], 30, 0, false ],
		];
	}

	/**
	 * @covers ::deleteFile
	 * @covers ::getArchiveFilePath
	 * @covers ::getUnverifiedFilePath
	 */
	public function testDeleteFile(): void {
		$manager = $this->getRequestManager( id: 1 );
		$backend = $this->getServiceContainer()->getRepoGroup()->getLocalRepo()->getBackend();

		$this->assertStringContainsString( '/ImportDump/archive/', $manager->getArchiveFilePath() );

		$this->assertStringContainsString( '/ImportDump/unverified/', $manager->getUnverifiedFilePath() );

		$paths = [ $manager->getFilePath(), $manager->getArchiveFilePath(), $manager->getUnverifiedFilePath() ];
		foreach ( $paths as $path ) {
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
		$this->assertFalse( $backend->fileExists( [ 'src' => $manager->getUnverifiedFilePath() ] ) );

		$this->assertTrue( $manager->deleteFile() );
	}

	/**
	 * @covers ::getLocalUnverifiedFile
	 * @covers ::publishUnverifiedFile
	 * @covers ::publishVerified
	 * @covers ::deleteUnverifiedFile
	 */
	public function testPublishVerifiedFile(): void {
		$manager = $this->getRequestManager( id: 1 );
		$backend = $this->getServiceContainer()->getRepoGroup()->getLocalRepo()->getBackend();

		$this->assertNull( $manager->getLocalUnverifiedFile() );

		$unverified = $manager->getUnverifiedFilePath();
		$this->assertStatusGood( $backend->prepare( [ 'dir' => FileBackend::parentStoragePath( $unverified ) ] ) );
		$this->assertStatusGood( $backend->quickCreate( [ 'dst' => $unverified, 'content' => 'unverified' ] ) );
		$this->assertSame( 'unverified', file_get_contents( $manager->getLocalUnverifiedFile()->getPath() ) );

		$this->assertStatusGood( $manager->publishUnverifiedFile() );
		$this->assertTrue( $manager->fileExists() );
		$this->assertFalse( $backend->fileExists( [ 'src' => $unverified ] ) );
		$this->assertSame( 'unverified', $backend->getFileContents( [ 'src' => $manager->getFilePath() ] ) );
	}

	/**
	 * @covers ::publishExtractedFile
	 * @covers ::publishVerified
	 */
	public function testPublishVerifiedFileFromExtractedFile(): void {
		$manager = $this->getRequestManager( id: 1 );
		$backend = $this->getServiceContainer()->getRepoGroup()->getLocalRepo()->getBackend();

		$unverified = $manager->getUnverifiedFilePath();
		$this->assertStatusGood( $backend->prepare( [ 'dir' => FileBackend::parentStoragePath( $unverified ) ] ) );
		$this->assertStatusGood( $backend->quickCreate( [ 'dst' => $unverified, 'content' => 'archive' ] ) );

		$extracted = $this->getNewTempFile();
		file_put_contents( $extracted, 'extracted' );

		$this->assertStatusGood( $manager->publishExtractedFile( $extracted ) );
		$this->assertFalse( $backend->fileExists( [ 'src' => $unverified ] ) );
		$this->assertSame( 'extracted', $backend->getFileContents( [ 'src' => $manager->getFilePath() ] ) );
		$this->assertFileExists( $extracted );
	}

	/**
	 * @covers ::setHistory
	 */
	public function testSetHistory(): void {
		$manager = $this->getRequestManager( id: 1 );
		$manager->setHistory( RequestHistory::Full );
		$this->assertSame( RequestHistory::Full, $manager->getHistory() );
		$this->assertSame(
			RequestHistory::Full,
			$this->getRequestManager( id: 1 )->getHistory()
		);

		$manager->setHistory( null );
		$this->assertNull( $this->getRequestManager( id: 1 )->getHistory() );
	}

	/**
	 * @covers ::getSkippedImages
	 * @covers ::setSkippedImages
	 */
	public function testSkippedImages(): void {
		$manager = $this->getRequestManager( id: 1 );
		$this->assertSame( [ 'total' => 0, 'files' => [] ], $manager->getSkippedImages() );

		$files = [
			new SkippedFile( 'a.png', 'requestimport-images-skip-extension', [] ),
			new SkippedFile( 'b.png', 'filetype-mime-mismatch', [ 'png', 'image/jpeg' ] ),
		];

		$manager->setSkippedImages( $files, 0, 2 );
		$this->assertEquals( [ 'total' => 2, 'files' => $files ], $manager->getSkippedImages() );
		$this->assertEquals(
			[ 'total' => 2, 'files' => $files ],
			$this->getRequestManager( id: 1 )->getSkippedImages()
		);

		$manager->setSkippedImages( [], 0, 0 );
		$this->assertSame( [ 'total' => 0, 'files' => [] ], $this->getRequestManager( id: 1 )->getSkippedImages() );
	}

	/**
	 * @covers ::getSkippedImages
	 * @covers ::setSkippedImages
	 */
	public function testOnlyTheFirstSkippedImagesAreKept(): void {
		$files = [];
		for ( $i = 0; $i < 600; $i++ ) {
			$files[] = new SkippedFile( "$i.png", 'requestimport-images-skip-extension', [] );
		}

		$manager = $this->getRequestManager( id: 1 );
		$manager->setSkippedImages( $files, 0, 600 );

		$stored = $this->getRequestManager( id: 1 )->getSkippedImages();
		$this->assertSame( 600, $stored['total'] );
		$this->assertCount( 500, $stored['files'] );
		$this->assertSame( '0.png', $stored['files'][0]->name );
	}

	/**
	 * @covers ::getHeldImageCount
	 * @covers ::setSkippedImages
	 */
	public function testHeldImageCount(): void {
		$manager = $this->getRequestManager( id: 1 );
		$this->assertSame( 0, $manager->getHeldImageCount() );

		$files = [ new SkippedFile( 'a.gif', 'requestimport-images-skip-extension', [ 'gif' ] ) ];
		$manager->setSkippedImages( $files, 1, 1 );
		$this->assertSame( 1, $this->getRequestManager( id: 1 )->getHeldImageCount() );

		$manager->setSkippedImages( [], 0, 0 );
		$this->assertSame( 0, $this->getRequestManager( id: 1 )->getHeldImageCount() );
	}

	/**
	 * @covers ::getHeldImageNames
	 * @covers ::publishHeldImages
	 * @covers ::releaseHeldImages
	 * @covers ::deleteHeldImages
	 */
	public function testHeldImagesCanBeReleasedAndDeleted(): void {
		$manager = $this->getRequestManager( $this->addImagesRequest( linkedID: 1 ) );
		$directory = $this->getNewTempDirectory();
		file_put_contents( "$directory/a.gif", 'a' );
		file_put_contents( "$directory/b.gif", 'b' );

		$this->assertStatusGood( $manager->publishHeldImages( [
			'a.gif' => "$directory/a.gif",
			'b.gif' => "$directory/b.gif",
		] ) );

		$this->assertSame( [ 'a.gif', 'b.gif' ], $manager->getHeldImageNames() );

		$copies = $manager->getHeldImageCopies( [ 'a.gif', 'b.gif', 'missing.gif' ] );
		$this->assertSame( [ 'a.gif', 'b.gif' ], array_keys( $copies ) );
		$this->assertSame( 'a', file_get_contents( $copies['a.gif']->getPath() ) );

		$this->assertStatusGood( $manager->releaseHeldImages( [ 'a.gif' ] ) );
		$this->assertSame( [ 'b.gif' ], $manager->getHeldImageNames() );

		$stored = $manager->getImageObjectNames();
		$this->assertCount( 1, $stored );
		$this->assertStringEndsWith( '/a.gif', $stored[0] );

		$this->assertTrue( $manager->deleteAllHeldImages() );
		$this->assertSame( [], $manager->getHeldImageNames() );
	}

	/**
	 * @covers ::getRequirements
	 * @covers ::setRequirements
	 */
	public function testRequirements(): void {
		$manager = $this->getRequestManager( id: 1 );
		$this->assertSame( [], $manager->getRequirements() );

		$requirements = [ 'PortableInfobox' => false, 'WikibaseRepository' => true ];
		$manager->setRequirements( $requirements );
		$this->assertSame( $requirements, $manager->getRequirements() );
		$this->assertSame( $requirements, $this->getRequestManager( id: 1 )->getRequirements() );

		$manager->setRequirements( [] );
		$this->assertSame( [], $this->getRequestManager( id: 1 )->getRequirements() );
	}

	/**
	 * @covers ::getRequirementProblems
	 * @covers ::getBlockingRequirementProblems
	 */
	public function testNoRequirementProblemsWithoutRequirements(): void {
		$manager = $this->getRequestManager( id: 1 );
		$this->assertSame( [], $manager->getRequirementProblems() );
		$this->assertSame( [], $manager->getBlockingRequirementProblems() );
	}

	/**
	 * @covers ::getRequirementProblems
	 */
	public function testRequirementProblemsAreUnknownWithoutHook(): void {
		$this->setRemoteGroups( null );
		// Other extensions can handle this hook.
		$this->clearHook( 'ImportDumpGetTargetState' );

		$manager = $this->getRequestManager( id: 1 );
		$manager->setRequirements( [ 'WikibaseRepository' => true ] );

		$this->assertEquals(
			[ new RequirementProblem( 'WikibaseRepository', RequirementProblemKind::Unknown, '', false ) ],
			$manager->getRequirementProblems()
		);

		$this->assertSame( [], $manager->getBlockingRequirementProblems() );
	}

	/**
	 * @param string[] $rights
	 * @param ?string[] $groups
	 * @covers ::requesterHasTargetAccess
	 * @dataProvider provideTargetAccess
	 */
	public function testRequesterHasTargetAccess( array $rights, ?array $groups, bool $expected ): void {
		$this->setTargetState( [], [], $rights, $groups );

		$this->assertSame( $expected, $this->getRequestManager( id: 1 )->requesterHasTargetAccess() );
	}

	public static function provideTargetAccess(): array {
		return [
			'can read' => [ [ 'read', 'edit' ], [ 'sysop' ], true ],
			'cannot read' => [ [ 'edit' ], [ 'sysop' ], false ],
			'no rights known' => [ [], [ 'sysop' ], false ],
			'no account on the target' => [ [ 'read' ], null, false ],
		];
	}

	/**
	 * @covers ::getRequirementProblems
	 */
	public function testHookGetsTheGroupsOfTheRequester(): void {
		$this->setRemoteGroups( [ 'sysop', 'bureaucrat' ] );
		$this->overrideConfigValue( ConfigNames::ExtensionRequirements, [
			'PortableInfobox' => [ 'models' => [], 'patterns' => [], 'settings' => [] ],
		] );

		$received = null;
		$this->setTemporaryHook( 'ImportDumpGetTargetState',
			static function (
				// @phan-suppress-next-line PhanUnusedClosureParameter
				string $target,
				array $requesterGroups,
				?array &$extensions,
				array &$settings,
				array &$rights
			) use ( &$received ): void {
				$received = $requesterGroups;
			}
		);

		$manager = $this->getRequestManager( id: 1 );
		$manager->setRequirements( [ 'PortableInfobox' => true ] );
		$manager->getRequirementProblems();

		$this->assertSame( [ 'sysop', 'bureaucrat' ], $received );
	}

	/** @covers \Miraheze\ImportDump\Notifications\AttentionNotifier::notify */
	public function testRequesterIsToldWhenTheImportCannotStart(): void {
		$captured = [];
		$service = $this->createMock( NotificationService::class );
		$service->method( 'notify' )->willReturnCallback(
			static function ( Notification $notification, RecipientSet $recipients ) use ( &$captured ): void {
				$captured[] = [ $notification, $recipients ];
			}
		);

		$this->setService( 'NotificationService', $service );
		$this->setTargetState( [], [], [ 'read' ], [ 'user' ] );

		$manager = $this->getRequestManager( id: 1 );
		$manager->setRequirements( [ 'PortableInfobox' => true ] );
		$this->notifyRequester( $manager );

		'@phan-var list<array{Notification, RecipientSet}> $captured';
		$this->assertCount( 1, $captured );
		[ $notification, $recipients ] = $captured[0];

		$this->assertInstanceOf( RequestNotification::class, $notification );
		$this->assertSame( 'requestimport-attention', $notification->getType() );
		$this->assertSame( 'RequestImport Status Update', $notification->getAgent()->getName() );
		$this->assertSame( [ $manager->getRequester()->getName() ], array_map(
			static fn ( UserIdentity $user ): string => $user->getName(),
			$recipients->getRecipients()
		) );

		$properties = $notification->getProperties();
		$this->assertSame( 1, $properties['request-id'] );
		$this->assertStringContainsString( 'RequestImportQueue/1', $properties['request-url'] );
		$this->assertSame( [ 'request-id', 'request-url' ], array_keys( $properties ) );
	}

	/**
	 * @param ?string[] $extensions
	 * @param string[] $rights
	 * @param ?string[] $groups
	 * @param array<string, bool> $requirements
	 * @covers \Miraheze\ImportDump\Notifications\AttentionNotifier::notify
	 * @dataProvider provideNoRequirementNotification
	 */
	public function testRequesterIsNotToldOtherwise(
		?array $extensions,
		array $rights,
		?array $groups,
		array $requirements
	): void {
		$service = $this->createMock( NotificationService::class );
		$service->expects( $this->never() )->method( 'notify' );
		$this->setService( 'NotificationService', $service );
		$this->setTargetState( $extensions, [], $rights, $groups );

		$manager = $this->getRequestManager( id: 1 );
		$manager->setRequirements( $requirements );
		$this->notifyRequester( $manager );
	}

	public static function provideNoRequirementNotification(): array {
		return [
			'requester cannot read the target' => [ [], [ 'edit' ], [ 'user' ], [ 'PortableInfobox' => true ] ],
			'requester has no account on the target' => [ [], [ 'read' ], null, [ 'PortableInfobox' => true ] ],
			'nothing is known about the target' => [ null, [ 'read' ], [ 'user' ], [ 'PortableInfobox' => true ] ],
			'the dump may need it' => [ [], [ 'read' ], [ 'user' ], [ 'PortableInfobox' => false ] ],
			'it is enabled' => [ [ 'PortableInfobox' ], [ 'read' ], [ 'user' ], [ 'PortableInfobox' => true ] ],
			'the dump needs nothing' => [ [], [ 'read' ], [ 'user' ], [] ],
		];
	}

	/** @covers \Miraheze\ImportDump\Notifications\AttentionNotifier::notify */
	public function testRequesterIsToldAboutPartialHistory(): void {
		$captured = [];
		$service = $this->createMock( NotificationService::class );
		$service->method( 'notify' )->willReturnCallback(
			static function ( Notification $notification, RecipientSet $recipients ) use ( &$captured ): void {
				$captured[] = [ $notification, $recipients ];
			}
		);

		$this->setService( 'NotificationService', $service );

		$manager = $this->getRequestManager( id: 1 );
		$manager->setHistory( RequestHistory::Partial );
		$this->notifyRequester( $manager );

		'@phan-var list<array{Notification, RecipientSet}> $captured';
		$this->assertCount( 1, $captured );
		[ $notification, $recipients ] = $captured[0];

		$this->assertSame( 'requestimport-attention', $notification->getType() );
		$this->assertSame( [ $manager->getRequester()->getName() ], array_map(
			static fn ( UserIdentity $user ): string => $user->getName(),
			$recipients->getRecipients()
		) );
	}

	/**
	 * @covers \Miraheze\ImportDump\Notifications\AttentionNotifier::notify
	 * @dataProvider provideHistoryWithoutProblem
	 */
	public function testRequesterIsNotToldAboutOtherHistory( ?RequestHistory $history ): void {
		$service = $this->createMock( NotificationService::class );
		$service->expects( $this->never() )->method( 'notify' );
		$this->setService( 'NotificationService', $service );
		$this->setTargetState( [], [], [ 'edit' ], [ 'user' ] );

		$manager = $this->getRequestManager( id: 1 );
		$manager->setHistory( $history );
		$this->notifyRequester( $manager );
	}

	public static function provideHistoryWithoutProblem(): array {
		return [
			'full' => [ RequestHistory::Full ],
			'unknown' => [ RequestHistory::Unknown ],
			'not checked' => [ null ],
		];
	}

	/**
	 * @param string[] $rights
	 * @param ?string[] $groups
	 * @param ?string[] $expected
	 * @covers ::getMissingTargetRights
	 * @covers ::getRequiredTargetRights
	 * @dataProvider provideMissingTargetRights
	 */
	public function testMissingTargetRights( array $rights, ?array $groups, ?array $expected ): void {
		$this->setTargetState( [], [], $rights, $groups );

		$this->assertSame( $expected, $this->getRequestManager( id: 1 )->getMissingTargetRights() );
	}

	public static function provideMissingTargetRights(): array {
		return [
			'has it' => [ [ 'read', 'import' ], [ 'sysop' ], [] ],
			'does not have it' => [ [ 'read', 'edit' ], [ 'sysop' ], [ 'import' ] ],
			'no rights given' => [ [], [ 'sysop' ], null ],
			'no account on the target' => [ [ 'read', 'import' ], null, null ],
		];
	}

	/**
	 * @covers ::getMissingTargetRights
	 * @covers ::getRequiredTargetRights
	 */
	public function testRequiredRightsDependOnTheTypeOfRequest(): void {
		// Add this first. setTargetState() mocks services that creating the test user needs.
		$imagesID = $this->addImagesRequest( linkedID: 1 );

		$this->overrideConfigValue( ConfigNames::RequiredRights, [
			'images' => [ 'upload', 'reupload' ],
			'xml' => [ 'import' ],
		] );

		$this->setTargetState( [], [], [ 'read', 'upload' ], [ 'sysop' ] );

		$images = $this->getRequestManager( $imagesID );
		$this->assertSame( [ 'upload', 'reupload' ], $images->getRequiredTargetRights() );
		$this->assertSame( [ 'reupload' ], $images->getMissingTargetRights() );

		$xml = $this->getRequestManager( id: 1 );
		$this->assertSame( [ 'import' ], $xml->getRequiredTargetRights() );
		$this->assertSame( [ 'import' ], $xml->getMissingTargetRights() );
	}

	/**
	 * @covers ::getMissingTargetRights
	 * @covers ::getRequiredTargetRights
	 */
	public function testNothingIsRequiredForAnUnlistedType(): void {
		$imagesID = $this->addImagesRequest( linkedID: 1 );

		$this->overrideConfigValue( ConfigNames::RequiredRights, [ 'images' => 'upload' ] );
		$this->setTargetState( [], [], [ 'read' ], [ 'sysop' ] );

		$xml = $this->getRequestManager( id: 1 );
		$this->assertSame( [], $xml->getRequiredTargetRights() );
		$this->assertSame( [], $xml->getMissingTargetRights() );

		$images = $this->getRequestManager( $imagesID );
		$this->assertSame( [ 'upload' ], $images->getRequiredTargetRights() );
	}

	private function notifyRequester( RequestManager $manager ): void {
		$notifier = $this->getServiceContainer()->getService( 'RequestImport.AttentionNotifier' );
		'@phan-var AttentionNotifier $notifier';
		$notifier->notify( $manager );
	}

	/** @param ?string[] $groups The groups of the requester on the target, or null for no account there. */
	private function setRemoteGroups( ?array $groups ): void {
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
	}

	/**
	 * @param ?string[] $extensions
	 * @param array<string, bool> $settings
	 * @param string[] $rights
	 * @param ?string[] $groups
	 */
	private function setTargetState(
		?array $extensions,
		array $settings,
		array $rights,
		?array $groups
	): void {
		$this->setRemoteGroups( $groups );

		$this->overrideConfigValue( ConfigNames::ExtensionRequirements, [
			'PortableInfobox' => [ 'models' => [], 'patterns' => [], 'settings' => [] ],
			'WikibaseRepository' => [ 'models' => [], 'patterns' => [], 'settings' => [ 'allowEntityImport' ] ],
		] );

		$this->setTemporaryHook( 'ImportDumpGetTargetState',
			static function (
				// @phan-suppress-next-line PhanUnusedClosureParameter
				string $target,
				// @phan-suppress-next-line PhanUnusedClosureParameter
				array $requesterGroups,
				?array &$enabled,
				array &$enabledSettings,
				array &$grantedRights
			) use ( $extensions, $settings, $rights ): void {
				$enabled = $extensions;
				$enabledSettings = $settings;
				$grantedRights = $rights;
			}
		);
	}

	/**
	 * PortableInfobox is only found by its text here, and WikibaseRepository by a content model.
	 *
	 * @param ?string[] $extensions
	 * @param array<string, bool> $settings
	 * @param list<RequirementProblem> $expected
	 * @covers ::getRequirementProblems
	 * @dataProvider provideRequirementProblems
	 */
	public function testRequirementProblems( ?array $extensions, array $settings, array $expected ): void {
		$this->setTargetState( $extensions, $settings, rights: [], groups: null );

		$manager = $this->getRequestManager( id: 1 );
		$manager->setRequirements( [ 'PortableInfobox' => false, 'WikibaseRepository' => true ] );

		$this->assertEquals( $expected, $manager->getRequirementProblems() );
	}

	public static function provideRequirementProblems(): Generator {
		yield 'everything is enabled' => [
			[ 'PortableInfobox', 'WikibaseRepository' ],
			[ 'allowEntityImport' => true ],
			[],
		];

		yield 'certain extension is missing' => [
			[ 'PortableInfobox' ],
			[],
			[ new RequirementProblem( 'WikibaseRepository', RequirementProblemKind::Missing, '', true ) ],
		];

		yield 'uncertain extension is missing' => [
			[ 'WikibaseRepository' ],
			[ 'allowEntityImport' => true ],
			[ new RequirementProblem( 'PortableInfobox', RequirementProblemKind::Missing, '', false ) ],
		];

		yield 'certain setting is off' => [
			[ 'PortableInfobox', 'WikibaseRepository' ],
			[ 'allowEntityImport' => false ],
			[ new RequirementProblem(
				'WikibaseRepository',
				RequirementProblemKind::Setting,
				'allowEntityImport',
				true
			) ],
		];

		yield 'setting is not known' => [
			[ 'PortableInfobox', 'WikibaseRepository' ],
			[],
			[ new RequirementProblem(
				'WikibaseRepository',
				RequirementProblemKind::SettingUnknown,
				'allowEntityImport',
				false
			) ],
		];

		yield 'nothing is enabled' => [
			[],
			[],
			[
				new RequirementProblem( 'PortableInfobox', RequirementProblemKind::Missing, '', false ),
				new RequirementProblem( 'WikibaseRepository', RequirementProblemKind::Missing, '', true ),
			],
		];

		yield 'hook does not know' => [
			null,
			[],
			[
				new RequirementProblem( 'PortableInfobox', RequirementProblemKind::Unknown, '', false ),
				new RequirementProblem( 'WikibaseRepository', RequirementProblemKind::Unknown, '', false ),
			],
		];
	}

	/**
	 * @covers ::getBlockingRequirementProblems
	 */
	public function testOnlyCertainProblemsBlock(): void {
		$this->setTargetState( [], [], rights: [], groups: null );

		$manager = $this->getRequestManager( id: 1 );
		$manager->setRequirements( [ 'PortableInfobox' => false, 'WikibaseRepository' => true ] );

		$this->assertEquals(
			[ new RequirementProblem( 'WikibaseRepository', RequirementProblemKind::Missing, '', true ) ],
			$manager->getBlockingRequirementProblems()
		);
	}

	/**
	 * @covers ::getVerificationProgress
	 */
	public function testGetVerificationProgress(): void {
		$manager = $this->getRequestManager( id: 1 );
		$progress = $this->getServiceContainer()->getService( 'RequestImport.VerificationProgress' );
		'@phan-var \Miraheze\ImportDump\Progress\VerificationProgress $progress';

		$this->assertNull( $manager->getVerificationProgress() );

		$progress->set( 1, 'checking', 40 );
		$this->assertSame( [ 'stage' => 'checking', 'percent' => 40 ], $manager->getVerificationProgress() );
	}

	/**
	 * @covers ::getImportPages
	 * @covers ::getImportPercent
	 * @covers ::setImportPercent
	 */
	public function testImportPercentBelongsToTheCurrentStage(): void {
		$manager = $this->getRequestManager( id: 1 );
		$manager->setStage( RequestStage::Import );
		$this->assertNull( $manager->getImportPercent() );

		$this->assertNull( $manager->getImportPages() );

		$manager->setImportPercent( 40, done: null, total: null );
		$this->assertSame( 40, $manager->getImportPercent() );
		$this->assertNull( $manager->getImportPages() );

		$manager->setImportPercent( 40, 400, 1000 );
		$this->assertSame( [ 'done' => 400, 'total' => 1000 ], $manager->getImportPages() );

		$manager->setStage( RequestStage::SiteStats );
		$this->assertNull( $manager->getImportPercent() );
		$this->assertNull( $manager->getImportPages() );

		$manager->setImportPercent( 100, done: null, total: null );
		$this->assertSame( 100, $manager->getImportPercent() );
	}

	/**
	 * @covers ::retryVerification
	 */
	public function testRetryVerification(): void {
		$manager = $this->getRequestManager( id: 1 );
		$progress = $this->getServiceContainer()->getService( 'RequestImport.VerificationProgress' );
		'@phan-var \Miraheze\ImportDump\Progress\VerificationProgress $progress';

		$progress->set( 1, 'checking', 40 );

		$manager->retryVerification();

		$this->assertNull( $manager->getVerificationProgress() );

		$queue = $this->getServiceContainer()->getJobQueueGroup()->get( VerifyDumpJob::JOB_NAME );
		$this->assertSame( 1, $queue->getSize() );

		$job = $queue->pop();
		$this->assertInstanceOf( VerifyDumpJob::class, $job );
		$this->assertSame( 1, $job->getParams()['requestid'] );
	}

	/**
	 * @covers ::setLocked
	 * @covers ::setOverwrite
	 * @covers ::setSkipDupes
	 * @covers ::shouldSkipDupes
	 * @covers ::shouldOverwrite
	 * @covers ::setPrivate
	 * @covers ::setReason
	 * @covers ::setSource
	 * @covers ::setTarget
	 */
	public function testSettersUpdateTheLoadedRequest(): void {
		$manager = $this->getRequestManager( id: 1 );

		$manager->setLocked( true );
		$this->assertTrue( $manager->isLocked() );

		$manager->setPrivate( true );
		$this->assertTrue( $manager->isPrivate() );

		$this->assertFalse( $manager->shouldOverwrite() );
		$manager->setOverwrite( true );
		$this->assertTrue( $manager->shouldOverwrite() );

		$this->assertTrue( $manager->shouldSkipDupes() );
		$manager->setSkipDupes( false );
		$this->assertFalse( $manager->shouldSkipDupes() );

		$manager->setReason( 'A new reason' );
		$this->assertSame( 'A new reason', $manager->getReason() );

		$manager->setSource( 'https://example.com' );
		$this->assertSame( 'https://example.com', $manager->getSource() );

		$manager->setTarget( 'otherwiki' );
		$this->assertSame( 'otherwiki', $manager->getTarget() );

		$stored = $this->getRequestManager( id: 1 );
		$this->assertTrue( $stored->isLocked() );
		$this->assertTrue( $stored->shouldOverwrite() );
		$this->assertFalse( $stored->shouldSkipDupes() );
		$this->assertSame( 'A new reason', $stored->getReason() );
		$this->assertSame( 'https://example.com', $stored->getSource() );
		$this->assertSame( 'otherwiki', $stored->getTarget() );

		$manager->setLocked( false );
		$this->assertFalse( $manager->isLocked() );
	}

	/**
	 * @covers ::isViewableBy
	 */
	public function testIsViewableBy(): void {
		$anon = new SimpleAuthority( new UserIdentityValue( 0, '127.0.0.1' ), [] );
		$other = new SimpleAuthority( new UserIdentityValue( 9001, 'Other' ), [] );
		$handler = new SimpleAuthority(
			new UserIdentityValue( 9002, 'Handler' ),
			[ 'view-private-import-requests' ]
		);

		$manager = $this->getRequestManager( id: 1 );
		$this->assertTrue( $manager->isViewableBy( $anon ) );

		$manager->setPrivate( true );

		$manager = $this->getRequestManager( id: 1 );
		$this->assertFalse( $manager->isViewableBy( $anon ) );
		$this->assertFalse( $manager->isViewableBy( $other ) );
		$this->assertTrue( $manager->isViewableBy( $manager->getRequester() ) );
		$this->assertTrue( $manager->isViewableBy( $handler ) );
	}
}
