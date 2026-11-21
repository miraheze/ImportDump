<?php

namespace Miraheze\ImportDump\Tests\Jobs;

use Generator;
use MediaWiki\FileRepo\LocalRepo;
use MediaWiki\FileRepo\RepoGroup;
use MediaWiki\MainConfigNames;
use MediaWiki\WikiMap\WikiMap;
use MediaWikiIntegrationTestCase;
use Miraheze\ImportDump\ConfigNames;
use Miraheze\ImportDump\Jobs\VerifyDumpJob;
use Miraheze\ImportDump\RequestHistory;
use Miraheze\ImportDump\RequestManager;
use Miraheze\ImportDump\RequestStatus;
use Miraheze\ImportDump\Tests\Upload\TarFixture;
use Miraheze\ImportDump\Tests\Upload\ZipFixture;
use Wikimedia\FileBackend\FileBackend;
use Wikimedia\FileBackend\FSFileBackend;
use Wikimedia\Mime\MimeAnalyzer;
use Wikimedia\Timestamp\ConvertibleTimestamp;

/**
 * @group ImportDump
 * @group Database
 * @group medium
 * @covers \Miraheze\ImportDump\Jobs\VerifyDumpJob
 */
class VerifyDumpJobTest extends MediaWikiIntegrationTestCase
	implements RequestHistory, RequestStatus {

	private const string DUMP = '<mediawiki xmlns="http://www.mediawiki.org/xml/export-0.11/" version="0.11">' .
		'<page><title>A</title><ns>0</ns><id>1</id>' .
		'<revision><id>10</id><text>a</text></revision>' .
		'<revision><id>11</id><parentid>10</parentid><text>b</text></revision>' .
		'</page></mediawiki>';

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
			$this->getServiceContainer()->getMainWANObjectCache(),
			$this->createMock( MimeAnalyzer::class )
		) );

		$this->overrideConfigValues( [
			MainConfigNames::VirtualDomainsMapping => [
				'virtual-importdump' => [ 'db' => WikiMap::getCurrentWikiId() ],
			],
			MainConfigNames::LocalDatabases => [ 'requestimporttest' ],
			ConfigNames::EnableHistoryDetection => true,
			ConfigNames::ExtensionRequirements => [
				'WikibaseRepository' => [
					'models' => [ 'wikibase-item', 'wikibase-property' ],
					'patterns' => [],
					'settings' => [ 'allowEntityImport' ],
				],
			],
		] );
	}

	private function getManager( int $id ): RequestManager {
		$manager = $this->getServiceContainer()->getService( 'ImportDumpRequestManager' );
		'@phan-var RequestManager $manager';
		$manager->loadFromID( $id );
		return $manager;
	}

	/** Makes a request that waits for verification, with the given upload stored for it. */
	private function newRequest( string $status, string $upload ): RequestManager {
		ConvertibleTimestamp::setFakeTime( ConvertibleTimestamp::now() );

		$dbw = $this->getDb();
		$dbw->newInsertQueryBuilder()
			->insertInto( 'import_requests' )
			->row( [
				'request_source' => 'https://requestimporttest.com',
				'request_target' => 'requestimporttest',
				'request_reason' => 'test',
				'request_status' => $status,
				'request_actor' => $this->getTestUser()->getUser()->getActorId(),
				'request_timestamp' => $dbw->timestamp(),
			] )
			->caller( __METHOD__ )
			->execute();

		$manager = $this->getManager( $dbw->insertId() );
		$backend = $this->getServiceContainer()->getRepoGroup()->getLocalRepo()->getBackend();
		$path = $manager->getUnverifiedFilePath();

		$this->assertStatusGood( $backend->prepare( [ 'dir' => FileBackend::parentStoragePath( $path ) ] ) );
		$this->assertStatusGood( $backend->quickCreate( [ 'dst' => $path, 'content' => $upload ] ) );

		return $manager;
	}

	private function runJob( RequestManager $manager ): void {
		$services = $this->getServiceContainer();
		$job = new VerifyDumpJob(
			[ 'requestid' => $manager->getID() ],
			$services->getFormatterFactory(),
			$services->getService( 'ImportDumpDumpProcessor' ),
			$manager,
			$services->getService( 'ImportDumpVerificationProgress' )
		);

		$this->assertTrue( $job->run() );
	}

	private function fileContents( string $path ): string|false {
		return $this->getServiceContainer()->getRepoGroup()->getLocalRepo()->getBackend()
			->getFileContents( [ 'src' => $path ] );
	}

	public function testRequirementsAreRecorded(): void {
		$dump = str_replace(
			'<revision><id>11</id>',
			'<revision><id>11</id><model>wikibase-item</model>',
			self::DUMP
		);

		$manager = $this->newRequest( self::STATUS_VERIFYING, $dump );
		$this->runJob( $manager );

		$manager = $this->getManager( $manager->getID() );
		$this->assertSame( self::STATUS_PENDING, $manager->getStatus() );
		$this->assertSame( [ 'WikibaseRepository' => true ], $manager->getRequirements() );
	}

	public function testNoRequirementsAreRecordedForPlainDump(): void {
		$manager = $this->newRequest( self::STATUS_VERIFYING, self::DUMP );
		$this->runJob( $manager );

		$this->assertSame( [], $this->getManager( $manager->getID() )->getRequirements() );
	}

	/** @dataProvider provideValidUploads */
	public function testValidUpload( callable $upload ): void {
		$manager = $this->newRequest( self::STATUS_VERIFYING, $upload() );
		$this->runJob( $manager );

		$manager = $this->getManager( $manager->getID() );
		$this->assertSame( self::STATUS_PENDING, $manager->getStatus() );
		$this->assertSame( self::HISTORY_FULL, $manager->getHistory() );
		$this->assertSame( self::DUMP, $this->fileContents( $manager->getFilePath() ) );
		$this->assertFalse( $this->fileContents( $manager->getUnverifiedFilePath() ) );
		$this->assertNull( $manager->getVerificationProgress() );
	}

	public static function provideValidUploads(): Generator {
		yield 'xml' => [ static fn (): string => self::DUMP ];
		yield 'zip' => [ static fn (): string => ZipFixture::build( [ 'dump.xml' => self::DUMP ] ) ];
		yield 'gzip' => [ static fn (): string => gzencode( self::DUMP ) ];
		yield 'tar' => [ static fn (): string => TarFixture::build( [ 'dump.xml' => self::DUMP ] ) ];
		yield 'tar.gz' => [ static fn (): string => gzencode( TarFixture::build( [ 'dump.xml' => self::DUMP ] ) ) ];
	}

	public function testValidUploadWithoutHistoryDetection(): void {
		$this->overrideConfigValue( ConfigNames::EnableHistoryDetection, false );

		$manager = $this->newRequest( self::STATUS_VERIFYING, self::DUMP );
		$this->runJob( $manager );

		$manager = $this->getManager( $manager->getID() );
		$this->assertSame( self::STATUS_PENDING, $manager->getStatus() );
		$this->assertNull( $manager->getHistory() );
	}

	/** @dataProvider provideRejectedUploads */
	public function testRejectedUpload( callable $upload ): void {
		$manager = $this->newRequest( self::STATUS_VERIFYING, $upload() );
		$this->runJob( $manager );

		$manager = $this->getManager( $manager->getID() );
		$this->assertSame( self::STATUS_DECLINED, $manager->getStatus() );
		$this->assertNull( $manager->getHistory() );
		$this->assertFalse( $manager->fileExists() );
		$this->assertFalse( $this->fileContents( $manager->getUnverifiedFilePath() ) );

		$comments = $manager->getComments();
		$this->assertCount( 1, $comments );
		$this->assertSame( 'ImportDump Status Update', $comments[0]['user']->getName() );
	}

	public static function provideRejectedUploads(): Generator {
		yield 'not a dump' => [ static fn (): string => '<html/>' ];
		yield 'zip with two files' => [
			static fn (): string => ZipFixture::build( [ 'a.xml' => self::DUMP, 'b.xml' => self::DUMP ] ),
		];

		yield 'zip with an unsafe name' => [
			static fn (): string => ZipFixture::build( [ '../dump.xml' => self::DUMP ] ),
		];

		yield 'gzip of something else' => [ static fn (): string => gzencode( 'content' ) ];
	}

	public function testMissingFile(): void {
		$manager = $this->newRequest( self::STATUS_VERIFYING, self::DUMP );
		$manager->deleteUnverifiedFile();

		$this->runJob( $manager );

		$this->assertSame( self::STATUS_DECLINED, $this->getManager( $manager->getID() )->getStatus() );
	}

	/** @dataProvider provideOtherStatuses */
	public function testOtherStatusesAreLeftAlone( string $status ): void {
		$manager = $this->newRequest( $status, self::DUMP );
		$this->runJob( $manager );

		$manager = $this->getManager( $manager->getID() );
		$this->assertSame( $status, $manager->getStatus() );
		$this->assertSame( self::DUMP, $this->fileContents( $manager->getUnverifiedFilePath() ) );
		$this->assertFalse( $manager->fileExists() );
	}

	public static function provideOtherStatuses(): Generator {
		yield 'pending' => [ self::STATUS_PENDING ];
		yield 'declined' => [ self::STATUS_DECLINED ];
	}
}
