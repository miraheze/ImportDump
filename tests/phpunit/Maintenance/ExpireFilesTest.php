<?php

declare( strict_types = 1 );

namespace Miraheze\ImportDump\Tests\Maintenance;

use MediaWiki\FileRepo\LocalRepo;
use MediaWiki\FileRepo\RepoGroup;
use MediaWiki\MainConfigNames;
use MediaWiki\Maintenance\MaintenanceFatalError;
use MediaWiki\Tests\Maintenance\MaintenanceBaseTestCase;
use MediaWiki\WikiMap\WikiMap;
use Miraheze\ImportDump\ConfigNames;
use Miraheze\ImportDump\Maintenance\ExpireFiles;
use Miraheze\ImportDump\Request\RequestManager;
use Miraheze\ImportDump\Request\RequestStatus;
use Miraheze\ImportDump\Request\RequestType;
use Wikimedia\FileBackend\FileBackend;
use Wikimedia\FileBackend\FSFileBackend;
use Wikimedia\Mime\MimeAnalyzer;
use Wikimedia\ObjectCache\WANObjectCache;
use Wikimedia\TestingAccessWrapper;
use Wikimedia\Timestamp\ConvertibleTimestamp;
use Wikimedia\Timestamp\TimestampFormat;

/**
 * @group ImportDump
 * @group Database
 * @group medium
 * @covers \Miraheze\ImportDump\Maintenance\ExpireFiles
 */
class ExpireFilesTest extends MaintenanceBaseTestCase {

	/** @inheritDoc */
	protected function getMaintenanceClass(): string {
		return ExpireFiles::class;
	}

	protected function setUp(): void {
		parent::setUp();

		$this->setService( 'RepoGroup', new RepoGroup(
			[
				'class' => LocalRepo::class,
				'name' => 'temp',
				'backend' => new FSFileBackend( [
					'name' => 'temp-backend',
					'wikiId' => WikiMap::getCurrentWikiId(),
					'basePath' => $this->getNewTempDirectory(),
				] ),
			],
			[],
			WANObjectCache::newEmpty(),
			$this->createMock( MimeAnalyzer::class )
		) );

		$this->overrideConfigValues( [
			MainConfigNames::VirtualDomainsMapping => [
				'virtual-requestimport' => [ 'db' => WikiMap::getCurrentWikiId() ],
			],
			ConfigNames::FileExpiryDays => [
				RequestStatus::Complete->value => 30,
				RequestStatus::Declined->value => 30,
				RequestStatus::Pending->value => 1,
			],
		] );
	}

	private function getRequestManager( int $id ): RequestManager {
		$manager = $this->getServiceContainer()->getService( 'RequestImport.RequestManager' );
		'@phan-var RequestManager $manager';
		$manager->loadFromID( $id );
		return $manager;
	}

	private function addRequest( RequestStatus $status, int $ageInDays, RequestType $type ): int {
		$dbw = $this->getServiceContainer()->getConnectionProvider()
			->getPrimaryDatabase( 'virtual-requestimport' );

		$dbw->newInsertQueryBuilder()
			->insertInto( 'import_requests' )
			->row( [
				'request_source' => 'https://requestimporttest.com',
				'request_target' => 'requestimporttest',
				'request_reason' => "test {$status->value} $ageInDays",
				'request_status' => $status->value,
				'request_type' => $type->value,
				'request_actor' => $this->getTestUser()->getUser()->getActorId(),
				'request_timestamp' => $dbw->timestamp(
					(int)ConvertibleTimestamp::now( TimestampFormat::UNIX ) - $ageInDays * 86400
				),
			] )
			->caller( __METHOD__ )
			->execute();

		$id = $dbw->insertId();

		$backend = $this->getServiceContainer()->getRepoGroup()->getLocalRepo()->getBackend();
		$path = $this->getRequestManager( $id )->getFilePath();

		if ( $type === RequestType::Images ) {
			$path .= '/a.png';
		}

		$this->assertStatusGood( $backend->prepare( [
			'dir' => FileBackend::parentStoragePath( $path ),
		] ) );

		$this->assertStatusGood( $backend->quickCreate( [
			'dst' => $path,
			'content' => 'test',
			'overwrite' => true,
		] ) );

		return $id;
	}

	private function requestFileExists( int $id ): bool {
		return $this->getRequestManager( $id )->fileExists();
	}

	/**
	 * @return array<string, int>
	 */
	private function addRequests(): array {
		return [
			'oldComplete' => $this->addRequest( RequestStatus::Complete, 40, RequestType::Xml ),
			'recentComplete' => $this->addRequest( RequestStatus::Complete, 10, RequestType::Xml ),
			'oldDeclined' => $this->addRequest( RequestStatus::Declined, 35, RequestType::Xml ),
			'oldFailed' => $this->addRequest( RequestStatus::Failed, 41, RequestType::Xml ),
			'oldPending' => $this->addRequest( RequestStatus::Pending, 400, RequestType::Xml ),
		];
	}

	public function testExpiresOldFiles(): void {
		$ids = $this->addRequests();

		$this->expectOutputRegex( '/Deleted 2 file\(s\)\. 0 failure\(s\)\./' );
		$this->maintenance->execute();

		$this->assertFalse( $this->requestFileExists( $ids['oldComplete'] ) );
		$this->assertFalse( $this->requestFileExists( $ids['oldDeclined'] ) );
		$this->assertTrue( $this->requestFileExists( $ids['recentComplete'] ) );
		$this->assertTrue( $this->requestFileExists( $ids['oldFailed'] ) );
		$this->assertTrue( $this->requestFileExists( $ids['oldPending'] ) );
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
			$this->assertTrue( $this->requestFileExists( $id ) );
		}
	}

	public function testBatchSize(): void {
		$maintenance = $this->maintenance;
		'@phan-var TestingAccessWrapper $maintenance';
		$this->assertSame( 100, $maintenance->getBatchSize() );

		$ids = $this->addRequests();

		$maintenance->setBatchSize( 1 );
		$this->assertSame( 1, $maintenance->getBatchSize() );

		$this->expectOutputRegex( '/Deleted 2 file\(s\)\./' );
		$this->maintenance->execute();

		$this->assertFalse( $this->requestFileExists( $ids['oldComplete'] ) );
		$this->assertFalse( $this->requestFileExists( $ids['oldDeclined'] ) );
	}

	public function testNothingConfigured(): void {
		$this->overrideConfigValue( ConfigNames::FileExpiryDays, [] );
		$ids = $this->addRequests();

		$this->expectOutputRegex( '/Nothing to do/' );
		$this->maintenance->execute();

		foreach ( $ids as $id ) {
			$this->assertTrue( $this->requestFileExists( $id ) );
		}
	}

	public function testOnlyTheGivenTypeExpires(): void {
		$xml = $this->addRequest( RequestStatus::Complete, 40, RequestType::Xml );
		$images = $this->addRequest( RequestStatus::Complete, 40, RequestType::Images );

		$this->maintenance->loadWithArgv( [ '--type', 'images' ] );

		$this->expectOutputRegex( '/Deleted 1 file\(s\)\. 0 failure\(s\)\./' );
		$this->maintenance->execute();

		$this->assertFalse( $this->requestFileExists( $images ) );
		$this->assertTrue( $this->requestFileExists( $xml ) );
	}

	public function testUnknownTypeIsRejected(): void {
		$this->maintenance->loadWithArgv( [ '--type', 'audio' ] );

		$this->expectException( MaintenanceFatalError::class );
		$this->maintenance->execute();
	}

	public function testMissingFilesAreIgnored(): void {
		$id = $this->addRequest( RequestStatus::Complete, 40, RequestType::Xml );
		$this->assertTrue( $this->getRequestManager( $id )->deleteFile() );

		$this->expectOutputRegex( '/Deleted 0 file\(s\)\. 0 failure\(s\)\./' );
		$this->maintenance->execute();
	}
}
