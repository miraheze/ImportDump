<?php

declare( strict_types = 1 );

namespace Miraheze\ImportDump\Tests\Api;

use MediaWiki\MainConfigNames;
use MediaWiki\Permissions\SimpleAuthority;
use MediaWiki\Tests\Api\ApiTestCase;
use MediaWiki\User\UserIdentityValue;
use MediaWiki\WikiMap\WikiMap;
use Miraheze\ImportDump\Progress\VerificationProgress;
use Miraheze\ImportDump\Request\RequestStage;
use Miraheze\ImportDump\Request\RequestStatus;
use Miraheze\ImportDump\Tests\IsolatesManageWikiTrait;
use Wikimedia\Timestamp\ConvertibleTimestamp;

/**
 * @group API
 * @group ImportDump
 * @group Database
 * @group medium
 * @covers \Miraheze\ImportDump\Api\ApiRequestImportProgress
 */
class ApiRequestImportProgressTest extends ApiTestCase {

	use IsolatesManageWikiTrait;

	protected function setUp(): void {
		parent::setUp();
		$this->isolateFromManageWiki();

		$this->overrideConfigValues( [
			MainConfigNames::VirtualDomainsMapping => [
				'virtual-requestimport' => [ 'db' => WikiMap::getCurrentWikiId() ],
			],
			MainConfigNames::LocalDatabases => [ 'requestimporttest' ],
		] );
	}

	private function newRequest( RequestStatus $status, int $private ): int {
		ConvertibleTimestamp::setFakeTime( ConvertibleTimestamp::now() );

		$this->getDb()->newInsertQueryBuilder()
			->insertInto( 'import_requests' )
			->row( [
				'request_source' => 'https://requestimporttest.com',
				'request_target' => 'requestimporttest',
				'request_reason' => 'test',
				'request_status' => $status->value,
				'request_private' => $private,
				'request_actor' => $this->getTestUser()->getUser()->getActorId(),
				'request_timestamp' => $this->getDb()->timestamp(),
			] )
			->caller( __METHOD__ )
			->execute();

		return $this->getDb()->insertId();
	}

	private function getProgress(): VerificationProgress {
		$progress = $this->getServiceContainer()->getService( 'RequestImport.VerificationProgress' );
		'@phan-var VerificationProgress $progress';
		return $progress;
	}

	public function testWaiting(): void {
		$id = $this->newRequest( RequestStatus::Verifying, 0 );

		[ $result ] = $this->doApiRequest( [ 'action' => 'requestimportprogress', 'requestid' => $id ] );
		$this->assertSame(
			[ 'status' => RequestStatus::Verifying->value, 'stage' => 'waiting', 'percent' => null ],
			$result['requestimportprogress']
		);
	}

	public function testProgress(): void {
		$id = $this->newRequest( RequestStatus::Verifying, 0 );
		$this->getProgress()->set( $id, 'extracting', 42 );

		[ $result ] = $this->doApiRequest( [ 'action' => 'requestimportprogress', 'requestid' => $id ] );
		$this->assertSame(
			[ 'status' => RequestStatus::Verifying->value, 'stage' => 'extracting', 'percent' => 42 ],
			$result['requestimportprogress']
		);
	}

	public function testImportWaitingToStart(): void {
		$id = $this->newRequest( RequestStatus::Starting, 0 );

		[ $result ] = $this->doApiRequest( [ 'action' => 'requestimportprogress', 'requestid' => $id ] );
		$this->assertSame(
			[
				'status' => RequestStatus::Starting->value,
				'stage' => '',
				'step' => 0,
				'steps' => 8,
				'percent' => null,
				'done' => null,
				'total' => null,
			],
			$result['requestimportprogress']
		);
	}

	public function testImportProgress(): void {
		$id = $this->newRequest( RequestStatus::InProgress, 0 );

		$manager = $this->getServiceContainer()->getService( 'RequestImport.RequestManager' );
		'@phan-var \Miraheze\ImportDump\Request\RequestManager $manager';
		$manager->loadFromID( $id );
		$manager->setStage( RequestStage::TextIndex );

		[ $result ] = $this->doApiRequest( [ 'action' => 'requestimportprogress', 'requestid' => $id ] );
		$this->assertSame(
			[
				'status' => RequestStatus::InProgress->value,
				'stage' => 'textindex',
				'step' => 4,
				'steps' => 8,
				'percent' => null,
				'done' => null,
				'total' => null,
			],
			$result['requestimportprogress']
		);

		$manager->setImportPercent( 55, done: null, total: null );

		[ $result ] = $this->doApiRequest( [ 'action' => 'requestimportprogress', 'requestid' => $id ] );
		$this->assertSame( 55, $result['requestimportprogress']['percent'] );
		$this->assertNull( $result['requestimportprogress']['done'] );

		$manager->setImportPercent( 42, 1200, 2850 );

		[ $result ] = $this->doApiRequest( [ 'action' => 'requestimportprogress', 'requestid' => $id ] );
		$this->assertSame( 1200, $result['requestimportprogress']['done'] );
		$this->assertSame( 2850, $result['requestimportprogress']['total'] );
	}

	public function testFinished(): void {
		$id = $this->newRequest( RequestStatus::Pending, 0 );

		[ $result ] = $this->doApiRequest( [ 'action' => 'requestimportprogress', 'requestid' => $id ] );
		$this->assertSame( RequestStatus::Pending->value, $result['requestimportprogress']['status'] );
	}

	public function testUnknownRequest(): void {
		$this->expectApiErrorCode( 'notfound' );
		$this->doApiRequest( [ 'action' => 'requestimportprogress', 'requestid' => 12345 ] );
	}

	public function testPrivateRequestHiddenFromOthers(): void {
		$id = $this->newRequest( RequestStatus::Verifying, 1 );

		$this->expectApiErrorCode( 'permissiondenied' );
		$this->doApiRequest(
			[ 'action' => 'requestimportprogress', 'requestid' => $id ],
			null,
			false,
			new SimpleAuthority( new UserIdentityValue( 0, '127.0.0.1' ), [] )
		);
	}

	public function testPrivateRequestVisibleToRequester(): void {
		$id = $this->newRequest( RequestStatus::Verifying, 1 );

		[ $result ] = $this->doApiRequest(
			[ 'action' => 'requestimportprogress', 'requestid' => $id ],
			null,
			false,
			$this->getTestUser()->getAuthority()
		);

		$this->assertSame( RequestStatus::Verifying->value, $result['requestimportprogress']['status'] );
	}

	public function testPrivateRequestVisibleToThoseWithTheRight(): void {
		$id = $this->newRequest( RequestStatus::Verifying, 1 );

		[ $result ] = $this->doApiRequest(
			[ 'action' => 'requestimportprogress', 'requestid' => $id ],
			null,
			false,
			new SimpleAuthority(
				new UserIdentityValue( 9002, 'Handler' ),
				[ 'view-private-import-requests' ]
			)
		);

		$this->assertSame( RequestStatus::Verifying->value, $result['requestimportprogress']['status'] );
	}
}
