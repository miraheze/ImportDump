<?php

namespace Miraheze\ImportDump\Tests;

use MediaWikiIntegrationTestCase;
use Miraheze\ImportDump\ImportDumpRequestManager;
use Miraheze\ImportDump\ImportDumpStatus;
use ReflectionClass;
use Wikimedia\Timestamp\ConvertibleTimestamp;

/**
 * @group ImportDump
 * @group Database
 * @group Medium
 * @coversDefaultClass \Miraheze\ImportDump\ImportDumpRequestManager
 */
class ImportDumpRequestManagerTest extends MediaWikiIntegrationTestCase
	implements ImportDumpStatus {

	public function addDBData() {
		$this->setMwGlobals( 'wgVirtualDomainsMapping', [
			'virtual-importdump' => [ 'db' => 'wikidb' ],
		] );

		ConvertibleTimestamp::setFakeTime( ConvertibleTimestamp::now() );

		$connectionProvider = $this->getServiceContainer()->getConnectionProvider();
		$dbw = $connectionProvider->getPrimaryDatabase( 'virtual-importdump' );

		$dbw->newInsertQueryBuilder()
			->insertInto( 'import_requests' )
			->ignore()
			->row( [
				'request_source' => 'https://importdumptest.com',
				'request_target' => 'importdumptest',
				'request_reason' => 'test',
				'request_status' => self::STATUS_PENDING,
				'request_actor' => $this->getTestUser()->getUser()->getActorId(),
				'request_timestamp' => $this->db->timestamp(),
			] )
			->caller( __METHOD__ )
			->execute();
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
