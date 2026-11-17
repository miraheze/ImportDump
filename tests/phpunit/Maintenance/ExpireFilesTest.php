<?php

namespace Miraheze\ImportDump\Tests\Maintenance;

use MediaWiki\MainConfigNames;
use MediaWiki\Tests\Maintenance\MaintenanceBaseTestCase;
use MediaWiki\WikiMap\WikiMap;
use Miraheze\ImportDump\ConfigNames;
use Miraheze\ImportDump\Maintenance\ExpireFiles;
use Miraheze\ImportDump\RequestManager;
use Miraheze\ImportDump\RequestStatus;
use Wikimedia\Timestamp\ConvertibleTimestamp;

/**
 * @group ImportDump
 * @group Database
 * @group medium
 * @covers \Miraheze\ImportDump\Maintenance\ExpireFiles
 */
class ExpireFilesTest extends MaintenanceBaseTestCase
	implements RequestStatus {

	/** @inheritDoc */
	protected function getMaintenanceClass(): string {
		return ExpireFiles::class;
	}

	protected function setUp(): void {
		parent::setUp();

		$this->overrideConfigValues( [
			MainConfigNames::VirtualDomainsMapping => [
				'virtual-importdump' => [ 'db' => WikiMap::getCurrentWikiId() ],
			],
			ConfigNames::FileExpiryDays => [
				self::STATUS_COMPLETE => 30,
				self::STATUS_DECLINED => 30,
				self::STATUS_PENDING => 1,
			],
		] );
	}

	private function getRequestManager( int $id ): RequestManager {
		$manager = $this->getServiceContainer()->getService( 'ImportDumpRequestManager' );
		'@phan-var RequestManager $manager';
		$manager->loadFromID( $id );
		return $manager;
	}

	private function addRequest( string $status, int $ageInDays ): int {
		$dbw = $this->getServiceContainer()->getConnectionProvider()
			->getPrimaryDatabase( 'virtual-importdump' );

		$dbw->newInsertQueryBuilder()
			->insertInto( 'import_requests' )
			->row( [
				'request_source' => 'https://requestimporttest.com',
				'request_target' => 'requestimporttest',
				'request_reason' => "test $status $ageInDays",
				'request_status' => $status,
				'request_actor' => $this->getTestUser()->getUser()->getActorId(),
				'request_timestamp' => $dbw->timestamp(
					(int)ConvertibleTimestamp::now( TS_UNIX ) - $ageInDays * 86400
				),
			] )
			->caller( __METHOD__ )
			->execute();

		$id = $dbw->insertId();

		$backend = $this->getServiceContainer()->getRepoGroup()->getLocalRepo()->getBackend();
		$status = $backend->quickCreate( [
			'dst' => $this->getRequestManager( $id )->getFilePath(),
			'content' => 'test',
			'overwrite' => true,
		] );

		$this->assertStatusGood( $status );
		return $id;
	}

	private function fileExists( int $id ): bool {
		return $this->getRequestManager( $id )->fileExists();
	}

	/**
	 * @return array<string, int>
	 */
	private function addRequests(): array {
		return [
			'oldComplete' => $this->addRequest( self::STATUS_COMPLETE, 40 ),
			'recentComplete' => $this->addRequest( self::STATUS_COMPLETE, 10 ),
			'oldDeclined' => $this->addRequest( self::STATUS_DECLINED, 35 ),
			'oldFailed' => $this->addRequest( self::STATUS_FAILED, 41 ),
			'oldPending' => $this->addRequest( self::STATUS_PENDING, 400 ),
		];
	}

	public function testExpiresOldFiles(): void {
		$ids = $this->addRequests();

		$this->expectOutputRegex( '/Deleted 2 file\(s\)\. 0 failure\(s\)\./' );
		$this->maintenance->execute();

		$this->assertFalse( $this->fileExists( $ids['oldComplete'] ) );
		$this->assertFalse( $this->fileExists( $ids['oldDeclined'] ) );
		$this->assertTrue( $this->fileExists( $ids['recentComplete'] ) );
		$this->assertTrue( $this->fileExists( $ids['oldFailed'] ) );
		$this->assertTrue( $this->fileExists( $ids['oldPending'] ) );
	}

	public function testSkipsStatusesThatCannotExpire(): void {
		$this->addRequests();

		$this->expectOutputRegex( "/Skipping 'pending'/" );
		$this->maintenance->execute();
	}

	public function testDryRun(): void {
		$ids = $this->addRequests();

		$this->maintenance->loadWithArgv( [ '--dry-run' ] );

		$this->expectOutputRegex( '/Would delete 2 file\(s\)\./' );
		$this->maintenance->execute();

		foreach ( $ids as $id ) {
			$this->assertTrue( $this->fileExists( $id ) );
		}
	}

	public function testBatchSize(): void {
		$this->assertSame( 100, $this->maintenance->getBatchSize() );

		$ids = $this->addRequests();

		$this->maintenance->setBatchSize( 1 );
		$this->assertSame( 1, $this->maintenance->getBatchSize() );

		$this->expectOutputRegex( '/Deleted 2 file\(s\)\./' );
		$this->maintenance->execute();

		$this->assertFalse( $this->fileExists( $ids['oldComplete'] ) );
		$this->assertFalse( $this->fileExists( $ids['oldDeclined'] ) );
	}

	public function testNothingConfigured(): void {
		$this->overrideConfigValue( ConfigNames::FileExpiryDays, [] );
		$ids = $this->addRequests();

		$this->expectOutputRegex( '/Nothing to do/' );
		$this->maintenance->execute();

		foreach ( $ids as $id ) {
			$this->assertTrue( $this->fileExists( $id ) );
		}
	}

	public function testMissingFilesAreIgnored(): void {
		$id = $this->addRequest( self::STATUS_COMPLETE, 40 );
		$this->assertTrue( $this->getRequestManager( $id )->deleteFile() );

		$this->expectOutputRegex( '/Deleted 0 file\(s\)\. 0 failure\(s\)\./' );
		$this->maintenance->execute();
	}
}
