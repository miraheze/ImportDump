<?php

declare( strict_types = 1 );

namespace Miraheze\ImportDump\Tests\Api;

use MediaWiki\MainConfigNames;
use MediaWiki\Permissions\SimpleAuthority;
use MediaWiki\Tests\Api\ApiTestCase;
use MediaWiki\User\UserIdentityValue;
use MediaWiki\WikiMap\WikiMap;
use Miraheze\ImportDump\Request\RequestManager;
use Miraheze\ImportDump\Request\RequestStatus;
use Miraheze\ImportDump\Tests\IsolatesManageWikiTrait;
use Wikimedia\Timestamp\ConvertibleTimestamp;

/**
 * @group API
 * @group ImportDump
 * @group Database
 * @group medium
 * @covers \Miraheze\ImportDump\Api\ApiRequestImportOutput
 */
class ApiRequestImportOutputTest extends ApiTestCase {

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

	private function getManager( int $id ): RequestManager {
		$manager = $this->getServiceContainer()->getService( 'RequestImport.RequestManager' );
		'@phan-var RequestManager $manager';
		$manager->loadFromID( $id );
		return $manager;
	}

	private function getHandler(): SimpleAuthority {
		return new SimpleAuthority(
			new UserIdentityValue( 9002, 'Handler' ),
			[ 'handle-import-requests', 'view-private-import-requests' ]
		);
	}

	public function testOutputOfARunningImport(): void {
		$id = $this->newRequest( RequestStatus::InProgress, 0 );
		$this->getManager( $id )->appendImportOutput( 1, "Importing a.png...done.\n" );

		[ $result ] = $this->doApiRequest(
			[ 'action' => 'requestimportoutput', 'requestid' => $id ],
			null,
			false,
			$this->getHandler()
		);

		$this->assertSame(
			[
				'running' => true,
				'parts' => [],
				'live' => [ 'part' => 1, 'text' => "Importing a.png...done.\n" ],
			],
			$result['requestimportoutput']
		);
	}

	public function testStoredParts(): void {
		$id = $this->newRequest( RequestStatus::Complete, 0 );
		$manager = $this->getManager( $id );
		$this->assertStatusGood( $manager->storeOutputPart( 0, "a\nb\n" ) );
		$this->assertStatusGood( $manager->storeOutputPart( 1, 'c' ) );

		[ $result ] = $this->doApiRequest(
			[ 'action' => 'requestimportoutput', 'requestid' => $id ],
			null,
			false,
			$this->getHandler()
		);

		$this->assertSame(
			[ 'running' => false, 'parts' => [ [ 'part' => 0, 'lines' => 2 ], [ 'part' => 1, 'lines' => 1 ] ] ],
			$result['requestimportoutput']
		);

		[ $result ] = $this->doApiRequest(
			[ 'action' => 'requestimportoutput', 'requestid' => $id, 'part' => 1 ],
			null,
			false,
			$this->getHandler()
		);

		$this->assertSame( [ 'part' => 1, 'text' => 'c' ], $result['requestimportoutput'] );
	}

	public function testPartThatWasNotStored(): void {
		$id = $this->newRequest( RequestStatus::Complete, 0 );

		[ $result ] = $this->doApiRequest(
			[ 'action' => 'requestimportoutput', 'requestid' => $id, 'part' => 3 ],
			null,
			false,
			$this->getHandler()
		);

		$this->assertSame( [ 'part' => 3, 'text' => '' ], $result['requestimportoutput'] );
	}

	public function testUnknownRequest(): void {
		$this->expectApiErrorCode( 'notfound' );
		$this->doApiRequest(
			[ 'action' => 'requestimportoutput', 'requestid' => 12345 ],
			null,
			false,
			$this->getHandler()
		);
	}

	public function testRequesterCannotSeeIt(): void {
		$id = $this->newRequest( RequestStatus::InProgress, 0 );
		$this->getManager( $id )->appendImportOutput( 0, 'text' );

		$this->expectApiErrorCode( 'permissiondenied' );
		$this->doApiRequest(
			[ 'action' => 'requestimportoutput', 'requestid' => $id ],
			null,
			false,
			$this->getTestUser()->getAuthority()
		);
	}
}
