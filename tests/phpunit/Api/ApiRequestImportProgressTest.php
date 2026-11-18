<?php

namespace Miraheze\ImportDump\Tests\Api;

use MediaWiki\MainConfigNames;
use MediaWiki\Tests\Api\ApiTestCase;
use MediaWiki\WikiMap\WikiMap;
use Miraheze\ImportDump\RequestStatus;
use Miraheze\ImportDump\VerificationProgress;
use Wikimedia\Timestamp\ConvertibleTimestamp;

/**
 * @group API
 * @group ImportDump
 * @group Database
 * @group medium
 * @covers \Miraheze\ImportDump\Api\ApiRequestImportProgress
 */
class ApiRequestImportProgressTest extends ApiTestCase
	implements RequestStatus {

	protected function setUp(): void {
		parent::setUp();

		$this->overrideConfigValues( [
			MainConfigNames::VirtualDomainsMapping => [
				'virtual-importdump' => [ 'db' => WikiMap::getCurrentWikiId() ],
			],
			MainConfigNames::LocalDatabases => [ 'requestimporttest' ],
		] );
	}

	private function newRequest( string $status, int $private ): int {
		ConvertibleTimestamp::setFakeTime( ConvertibleTimestamp::now() );

		$this->getDb()->newInsertQueryBuilder()
			->insertInto( 'import_requests' )
			->row( [
				'request_source' => 'https://requestimporttest.com',
				'request_target' => 'requestimporttest',
				'request_reason' => 'test',
				'request_status' => $status,
				'request_private' => $private,
				'request_actor' => $this->getTestUser()->getUser()->getActorId(),
				'request_timestamp' => $this->getDb()->timestamp(),
			] )
			->caller( __METHOD__ )
			->execute();

		return $this->getDb()->insertId();
	}

	private function getProgress(): VerificationProgress {
		$progress = $this->getServiceContainer()->getService( 'ImportDumpVerificationProgress' );
		'@phan-var VerificationProgress $progress';
		return $progress;
	}

	public function testWaiting(): void {
		$id = $this->newRequest( self::STATUS_VERIFYING, 0 );

		[ $result ] = $this->doApiRequest( [ 'action' => 'requestimportprogress', 'requestid' => $id ] );
		$this->assertSame(
			[ 'status' => self::STATUS_VERIFYING, 'stage' => 'waiting', 'percent' => null ],
			$result['requestimportprogress']
		);
	}

	public function testProgress(): void {
		$id = $this->newRequest( self::STATUS_VERIFYING, 0 );
		$this->getProgress()->set( $id, 'extracting', 42 );

		[ $result ] = $this->doApiRequest( [ 'action' => 'requestimportprogress', 'requestid' => $id ] );
		$this->assertSame(
			[ 'status' => self::STATUS_VERIFYING, 'stage' => 'extracting', 'percent' => 42 ],
			$result['requestimportprogress']
		);
	}

	public function testFinished(): void {
		$id = $this->newRequest( self::STATUS_PENDING, 0 );

		[ $result ] = $this->doApiRequest( [ 'action' => 'requestimportprogress', 'requestid' => $id ] );
		$this->assertSame( self::STATUS_PENDING, $result['requestimportprogress']['status'] );
	}

	public function testUnknownRequest(): void {
		$this->expectApiErrorCode( 'notfound' );
		$this->doApiRequest( [ 'action' => 'requestimportprogress', 'requestid' => 12345 ] );
	}

	public function testPrivateRequestHiddenFromOthers(): void {
		$id = $this->newRequest( self::STATUS_VERIFYING, 1 );

		$this->expectApiErrorCode( 'permissiondenied' );
		$this->doApiRequest(
			[ 'action' => 'requestimportprogress', 'requestid' => $id ],
			null,
			false,
			$this->mockAnonNullAuthority()
		);
	}

	public function testPrivateRequestVisibleToRequester(): void {
		$id = $this->newRequest( self::STATUS_VERIFYING, 1 );

		[ $result ] = $this->doApiRequest(
			[ 'action' => 'requestimportprogress', 'requestid' => $id ],
			null,
			false,
			$this->getTestUser()->getAuthority()
		);

		$this->assertSame( self::STATUS_VERIFYING, $result['requestimportprogress']['status'] );
	}

	public function testPrivateRequestVisibleToThoseWithTheRight(): void {
		$id = $this->newRequest( self::STATUS_VERIFYING, 1 );

		[ $result ] = $this->doApiRequest(
			[ 'action' => 'requestimportprogress', 'requestid' => $id ],
			null,
			false,
			$this->mockRegisteredAuthorityWithPermissions( [ 'view-private-import-requests' ] )
		);

		$this->assertSame( self::STATUS_VERIFYING, $result['requestimportprogress']['status'] );
	}
}
