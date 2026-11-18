<?php

namespace Miraheze\ImportDump\Tests\Jobs;

use MediaWiki\MainConfigNames;
use MediaWiki\Notification\Notification;
use MediaWiki\Notification\NotificationService;
use MediaWiki\Notification\RecipientSet;
use MediaWiki\WikiMap\WikiMap;
use MediaWikiIntegrationTestCase;
use Miraheze\ImportDump\ConfigNames;
use Miraheze\ImportDump\Jobs\ImportDumpNotifyJob;
use Miraheze\ImportDump\Notifications\RequestNotification;
use Miraheze\ImportDump\RequestManager;
use Miraheze\ImportDump\RequestStatus;
use Wikimedia\Timestamp\ConvertibleTimestamp;

/**
 * @group ImportDump
 * @group Database
 * @group medium
 * @covers \Miraheze\ImportDump\Jobs\ImportDumpNotifyJob
 */
class ImportDumpNotifyJobTest extends MediaWikiIntegrationTestCase
	implements RequestStatus {

	/** @var list<array{Notification, RecipientSet}> */
	private array $captured = [];

	protected function setUp(): void {
		parent::setUp();

		$this->overrideConfigValue( MainConfigNames::VirtualDomainsMapping, [
			'virtual-importdump' => [ 'db' => WikiMap::getCurrentWikiId() ],
			'virtual-interwiki' => [ 'db' => WikiMap::getCurrentWikiId() ],
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
			->getPrimaryDatabase( 'virtual-importdump' );

		$dbw->newInsertQueryBuilder()
			->insertInto( 'import_requests' )
			->row( [
				'request_source' => 'https://requestimporttest.com',
				'request_target' => WikiMap::getCurrentWikiId(),
				'request_reason' => 'test',
				'request_status' => self::STATUS_INPROGRESS,
				'request_actor' => $this->getTestUser()->getUser()->getActorId(),
				'request_timestamp' => $dbw->timestamp( ConvertibleTimestamp::now() ),
			] )
			->caller( __METHOD__ )
			->execute();

		return $dbw->insertId();
	}

	private function runJob( int $requestID, string $status, string $error ): void {
		$job = $this->getServiceContainer()->getJobFactory()->newJob( ImportDumpNotifyJob::JOB_NAME, [
			'requestid' => $requestID,
			'username' => $this->getTestUser()->getUser()->getName(),
			'status' => $status,
			'joberror' => $error,
		] );

		$this->assertInstanceOf( ImportDumpNotifyJob::class, $job );
		$this->assertTrue( $job->run() );
	}

	/** @return string[] */
	private function getTypes(): array {
		return array_map(
			static fn ( array $pair ): string => $pair[0]->getType(),
			$this->captured
		);
	}

	private function getRequestManager( int $id ): RequestManager {
		$manager = $this->getServiceContainer()->getService( 'ImportDumpRequestManager' );
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
		$this->runJob( $id, self::STATUS_FAILED, 'The import broke' );

		$this->assertSame(
			[ 'importdump-import-failed', 'importdump-request-status-update' ],
			$this->getTypes()
		);

		[ $notification, $recipients ] = $this->captured[0];
		$this->assertInstanceOf( RequestNotification::class, $notification );
		$this->assertSame( 'ImportDump Status Update', $notification->getAgent()->getName() );
		$this->assertSame( [ $sysop->getName() ], array_map(
			static fn ( $user ): string => $user->getName(),
			$recipients->getRecipients()
		) );

		$properties = $notification->getProperties();
		$this->assertSame( $id, $properties['request-id'] );
		$this->assertSame( 'The import broke', $properties['reason'] );
		$this->assertStringContainsString( "RequestImportQueue/$id", $properties['request-url'] );

		$this->assertSame( self::STATUS_FAILED, $this->getRequestManager( $id )->getStatus() );
	}

	public function testFailedImportWithNobodyToNotify(): void {
		$this->overrideConfigValue( ConfigNames::UsersNotifiedOnFailedImports, [] );

		$id = $this->addRequest();
		$this->runJob( $id, self::STATUS_FAILED, 'The import broke' );

		$this->assertNotContains( 'importdump-import-failed', $this->getTypes() );
		$this->assertSame( self::STATUS_FAILED, $this->getRequestManager( $id )->getStatus() );
	}

	public function testCompletedImportTellsTheRequester(): void {
		$id = $this->addRequest();
		$this->runJob( $id, self::STATUS_COMPLETE, '' );

		$this->assertSame( [ 'importdump-request-status-update' ], $this->getTypes() );

		[ $notification, $recipients ] = $this->captured[0];
		$this->assertSame( [ $this->getTestUser()->getUser()->getName() ], array_map(
			static fn ( $user ): string => $user->getName(),
			$recipients->getRecipients()
		) );

		$this->assertSame( self::STATUS_COMPLETE, $this->getRequestManager( $id )->getStatus() );
	}
}
