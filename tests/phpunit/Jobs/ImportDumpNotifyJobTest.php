<?php

declare( strict_types = 1 );

namespace Miraheze\ImportDump\Tests\Jobs;

use MediaWiki\MainConfigNames;
use MediaWiki\Notification\Notification;
use MediaWiki\Notification\NotificationService;
use MediaWiki\Notification\RecipientSet;
use MediaWiki\User\UserIdentity;
use MediaWiki\WikiMap\WikiMap;
use MediaWikiIntegrationTestCase;
use Miraheze\ImportDump\ConfigNames;
use Miraheze\ImportDump\Jobs\ImportDumpNotifyJob;
use Miraheze\ImportDump\Notifications\RequestNotification;
use Miraheze\ImportDump\Request\RequestManager;
use Miraheze\ImportDump\Request\RequestStatus;
use Miraheze\ImportDump\Tests\IsolatesManageWikiTrait;
use Wikimedia\Timestamp\ConvertibleTimestamp;
use function array_map;

/**
 * @group ImportDump
 * @group Database
 * @group medium
 * @covers \Miraheze\ImportDump\Jobs\ImportDumpNotifyJob
 */
class ImportDumpNotifyJobTest extends MediaWikiIntegrationTestCase {

	use IsolatesManageWikiTrait;

	/** @var list<array{Notification, RecipientSet}> */
	private array $captured = [];

	protected function setUp(): void {
		parent::setUp();
		$this->isolateFromManageWiki();

		$this->overrideConfigValues( [
			MainConfigNames::LocalDatabases => [ WikiMap::getCurrentWikiId() ],
			MainConfigNames::VirtualDomainsMapping => [
				'virtual-interwiki' => [ 'db' => WikiMap::getCurrentWikiId() ],
				'virtual-requestimport' => [ 'db' => WikiMap::getCurrentWikiId() ],
			],
		] );

		$this->captured = [];
		$service = $this->createMock( NotificationService::class );
		$service->method( 'notify' )->willReturnCallback(
			function ( Notification $notification, RecipientSet $recipients ): void {
				$this->captured[] = [ $notification, $recipients ];
			}
		);

		$this->setService( 'NotificationService', $service );
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
				'request_status' => RequestStatus::InProgress->value,
				'request_actor' => $this->getTestUser()->getUser()->getActorId(),
				'request_timestamp' => $dbw->timestamp( ConvertibleTimestamp::now() ),
			] )
			->caller( __METHOD__ )
			->execute();

		return $dbw->insertId();
	}

	private function runJob( int $requestID, RequestStatus $status, string $error, string $username ): void {
		$job = $this->getServiceContainer()->getJobFactory()->newJob( ImportDumpNotifyJob::JOB_NAME, [
			'requestid' => $requestID,
			'username' => $username,
			'status' => $status->value,
			'joberror' => $error,
		] );

		$this->assertInstanceOf( ImportDumpNotifyJob::class, $job );
		$this->assertTrue( $job->run() );
	}

	/** @return string[] */
	private function getTypes(): array {
		$types = [];
		foreach ( $this->captured as [ $notification ] ) {
			$types[] = $notification->getType();
		}

		return $types;
	}

	private function getRequestManager( int $id ): RequestManager {
		$manager = $this->getServiceContainer()->getService( 'RequestImport.RequestManager' );
		'@phan-var RequestManager $manager';
		$manager->loadFromID( $id );
		return $manager;
	}

	public function testFailedImportNotifiesTheConfiguredUsers(): void {
		$this->setGroupPermissions( 'sysop', 'handle-import-requests', true );
		$sysop = $this->getTestSysop()->getUser();
		$this->overrideConfigValue( ConfigNames::UsersNotifiedOnFailedImports, [
			$sysop->getName(),
			$this->getTestUser()->getUser()->getName(),
		] );

		$id = $this->addRequest();
		$this->runJob( $id, RequestStatus::Failed, 'The import broke', $this->getTestUser()->getUser()->getName() );

		$this->assertSame(
			[ 'requestimport-import-failed', 'requestimport-request-status-update' ],
			$this->getTypes()
		);

		[ $notification, $recipients ] = $this->captured[0];
		$this->assertInstanceOf( RequestNotification::class, $notification );
		$this->assertSame( 'RequestImport Status Update', $notification->getAgent()->getName() );
		$this->assertSame( [ $sysop->getName() ], array_map(
			static fn ( UserIdentity $user ): string => $user->getName(),
			$recipients->getRecipients()
		) );

		$properties = $notification->getProperties();
		$this->assertSame( $id, $properties['request-id'] );
		$this->assertSame( 'The import broke', $properties['reason'] );
		$this->assertStringContainsString( "RequestImportQueue/$id", $properties['request-url'] );

		$this->assertSame( RequestStatus::Failed, $this->getRequestManager( $id )->getStatus() );
	}

	public function testFailedImportWithNobodyToNotify(): void {
		$this->overrideConfigValue( ConfigNames::UsersNotifiedOnFailedImports, [] );

		$id = $this->addRequest();
		$this->runJob( $id, RequestStatus::Failed, 'The import broke', $this->getTestUser()->getUser()->getName() );

		$this->assertNotContains( 'requestimport-import-failed', $this->getTypes() );
		$this->assertSame( RequestStatus::Failed, $this->getRequestManager( $id )->getStatus() );
	}

	public function testCompletedImportTellsTheRequester(): void {
		$id = $this->addRequest();
		$this->runJob( $id, RequestStatus::Complete, '', $this->getTestUser()->getUser()->getName() );

		$this->assertSame( [ 'requestimport-request-status-update' ], $this->getTypes() );

		[ , $recipients ] = $this->captured[0];
		$this->assertSame( [ $this->getTestUser()->getUser()->getName() ], array_map(
			static fn ( UserIdentity $user ): string => $user->getName(),
			$recipients->getRecipients()
		) );

		$this->assertSame( RequestStatus::Complete, $this->getRequestManager( $id )->getStatus() );
	}

	public function testStartedImportIsCommentedBySystemUserAndNamesTheActor(): void {
		$actor = $this->getTestSysop()->getUser();

		$id = $this->addRequest();
		$this->runJob( $id, RequestStatus::InProgress, '', $actor->getName() );

		$comments = $this->getRequestManager( $id )->getComments();
		$this->assertCount( 1, $comments );
		$this->assertSame( 'RequestImport Status Update', $comments[0]['user']->getName() );
		$this->assertStringContainsString( $actor->getName(), $comments[0]['comment'] );

		$this->assertSame( [ 'requestimport-request-status-update' ], $this->getTypes() );

		[ $notification, $recipients ] = $this->captured[0];
		$this->assertInstanceOf( RequestNotification::class, $notification );
		$this->assertSame( $actor->getName(), $notification->getAgent()->getName() );
		$this->assertSame( [ $this->getTestUser()->getUser()->getName() ], array_map(
			static fn ( UserIdentity $user ): string => $user->getName(),
			$recipients->getRecipients()
		) );
	}

	public function testStartedImportByTheRequesterNotifiesNobody(): void {
		$id = $this->addRequest();
		$this->runJob( $id, RequestStatus::InProgress, '', $this->getTestUser()->getUser()->getName() );

		$this->assertSame( [], $this->captured );
		$this->assertCount( 1, $this->getRequestManager( $id )->getComments() );
	}
}
