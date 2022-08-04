<?php

namespace Miraheze\ImportDump\Tests;

use MediaWikiIntegrationTestCase;
use Miraheze\ImportDump\ImportDumpRequestManager;
use ReflectionClass;
use Wikimedia\Timestamp\ConvertibleTimestamp;

/**
 * @group ImportDump
 * @group Database
 * @group Medium
 * @coversDefaultClass \Miraheze\ImportDump\ImportDumpRequestManager
 */
class ImportDumpRequestManagerTest extends MediaWikiIntegrationTestCase {
	protected function setUp(): void {
		parent::setUp();

		$this->tablesUsed = [
			'importdump_requests',
		];

		ConvertibleTimestamp::setFakeTime( ConvertibleTimestamp::now() );

		$this->db->insert(
			'importdump_requests',
			[
				'request_source' => 'https://importdumptest.com',
				'request_target' => 'importdumptest',
				'request_reason' => 'test',
				'request_status' => 'pending',
				'request_actor' => $this->getTestUser()->getUser()->getActorId(),
				'request_timestamp' => $this->db->timestamp(),
			],
			__METHOD__,
			[ 'IGNORE' ]
		);
	}

	private function mockImportDumpRequestManager() {
		$mock = $this->createMock( ImportDumpRequestManager::class );
		$mock->expects( $this->once() )->method( 'fromID' )->with( 1 );

		$this->setService( 'ImportDumpRequestManager', $mock );

		return $mock;
	}

	/**
	 * @covers ::fromID
	 */
	public function testFromID() {
		$reflectedClass = new ReflectionClass( $this->mockImportDumpRequestManager() );
		$reflection = $reflectedClass->getProperty( 'ID' );
		$reflection->setAccessible( true );

		$ID = $reflection->getValue( $this->mockImportDumpRequestManager() );

		$this->assertEquals( $ID, 1 );
	}
}
