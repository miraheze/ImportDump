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

		$this->tablesUsed[] = 'importdump_requests';
	}

	public function addDBData() {
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

	private function getImportDumpRequestManager(): ImportDumpRequestManager {
		$services = $this->getServiceContainer();
		$manager = $services->getService( 'ImportDumpRequestManager' );

		$manager->fromID( 1 );

		return $manager;
	}

	/**
	 * @covers ::__construct
	 * @covers ::fromID
	 */
	public function testFromID() {
		$manager = $this->getImportDumpRequestManager();

		$reflectedClass = new ReflectionClass( $manager );
		$reflection = $reflectedClass->getProperty( 'ID' );
		$reflection->setAccessible( true );

		$ID = $reflection->getValue( $manager );

		$this->assertSame( 1, $ID );
	}

	/**
	 * @covers ::exists
	 */
	public function testExists() {
		$manager = $this->getImportDumpRequestManager();

		$this->assertTrue( $manager->exists() );
	}
}
