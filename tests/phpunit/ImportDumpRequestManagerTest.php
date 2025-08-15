<?php

namespace Miraheze\ImportDump\Tests;

use MediaWiki\MainConfigNames;
use MediaWikiIntegrationTestCase;
use Miraheze\ImportDump\ImportDumpRequestManager;
use Miraheze\ImportDump\ImportDumpStatus;
use Wikimedia\TestingAccessWrapper;
use Wikimedia\Timestamp\ConvertibleTimestamp;

/**
 * @group ImportDump
 * @group Database
 * @group Medium
 * @coversDefaultClass \Miraheze\ImportDump\ImportDumpRequestManager
 */
class ImportDumpRequestManagerTest extends MediaWikiIntegrationTestCase
	implements ImportDumpStatus {

	public function addDBDataOnce(): void {
		$this->setMwGlobals( MainConfigNames::VirtualDomainsMapping, [
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

	private function getRequestManager(): ImportDumpRequestManager {
		$services = $this->getServiceContainer();
		$manager = $services->getService( 'ImportDumpRequestManager' );

		$manager->loadFromID( 1 );
		return $manager;
	}

	/**
	 * @covers ::__construct
	 * @covers ::loadFromID
	 */
	public function testFromID() {
		$manager = TestingAccessWrapper::newFromObject(
			$this->getRequestManager()
		);

		$this->assertSame( 1, $manager->ID );
	}

	/**
	 * @covers ::exists
	 */
	public function testExists() {
		$manager = $this->getRequestManager();

		$this->assertTrue( $manager->exists() );
	}

	/**
	 * @covers ::addComment
	 * @covers ::getComments
	 */
	public function testAddComment() {
		$manager = $this->getRequestManager();
		$this->assertArrayEquals( [], $manager->getComments() );

		$manager->addComment( 'Test', $this->getTestUser()->getUser() );
		$this->assertNotSame( [], $manager->getComments() );
	}
}
