<?php

declare( strict_types = 1 );

namespace Miraheze\ImportDump\Tests\Jobs;

use Generator;
use MediaWiki\FileRepo\LocalRepo;
use MediaWiki\FileRepo\RepoGroup;
use MediaWiki\MainConfigNames;
use MediaWiki\WikiMap\WikiMap;
use MediaWikiIntegrationTestCase;
use Miraheze\ImportDump\ConfigNames;
use Miraheze\ImportDump\Jobs\VerifyDumpJob;
use Miraheze\ImportDump\Request\RequestHistory;
use Miraheze\ImportDump\Request\RequestManager;
use Miraheze\ImportDump\Request\RequestStatus;
use Miraheze\ImportDump\Request\RequestType;
use Miraheze\ImportDump\Tests\IsolatesManageWikiTrait;
use Miraheze\ImportDump\Tests\Upload\TarFixture;
use Miraheze\ImportDump\Tests\Upload\ZipFixture;
use Wikimedia\FileBackend\FileBackend;
use Wikimedia\FileBackend\FSFileBackend;
use Wikimedia\Mime\MimeAnalyzer;
use Wikimedia\ObjectCache\WANObjectCache;
use Wikimedia\Timestamp\ConvertibleTimestamp;
use function base64_decode;
use function gzencode;
use function str_replace;
use function strlen;
use function substr_count;

/**
 * @group ImportDump
 * @group Database
 * @group medium
 * @covers \Miraheze\ImportDump\Jobs\VerifyDumpJob
 */
class VerifyDumpJobTest extends MediaWikiIntegrationTestCase {

	use IsolatesManageWikiTrait;

	private const string DUMP = '<mediawiki xmlns="http://www.mediawiki.org/xml/export-0.11/" version="0.11">' .
		'<page><title>A</title><ns>0</ns><id>1</id>' .
		'<revision><id>10</id><text>a</text></revision>' .
		'<revision><id>11</id><parentid>10</parentid><text>b</text></revision>' .
		'</page></mediawiki>';

	private const string PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR4' .
		'2mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

	protected function setUp(): void {
		parent::setUp();
		$this->isolateFromManageWiki();

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
	private function newRequest( RequestStatus $status, string $upload ): RequestManager {
		return $this->newTypedRequest( $status, $upload, RequestType::Xml );
	}

	private function newTypedRequest( RequestStatus $status, string $upload, RequestType $type ): RequestManager {
		ConvertibleTimestamp::setFakeTime( ConvertibleTimestamp::now() );

		$dbw = $this->getDb();
		$dbw->newInsertQueryBuilder()
			->insertInto( 'import_requests' )
			->row( [
				'request_source' => 'https://requestimporttest.com',
				'request_target' => 'requestimporttest',
				'request_reason' => 'test',
				'request_status' => $status->value,
				'request_actor' => $this->getTestUser()->getUser()->getActorId(),
				'request_timestamp' => $dbw->timestamp(),
				'request_type' => $type->value,
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
			$services->getService( 'ImportDumpAttentionNotifier' ),
			$services->getService( 'ImportDumpDumpProcessor' ),
			$services->getService( 'ImportDumpImageProcessor' ),
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

		$manager = $this->newRequest( RequestStatus::Verifying, $dump );
		$this->runJob( $manager );

		$manager = $this->getManager( $manager->getID() );
		$this->assertSame( RequestStatus::Pending, $manager->getStatus() );
		$this->assertSame( [ 'WikibaseRepository' => true ], $manager->getRequirements() );
	}

	public function testNoRequirementsAreRecordedForPlainDump(): void {
		$manager = $this->newRequest( RequestStatus::Verifying, self::DUMP );
		$this->runJob( $manager );

		$this->assertSame( [], $this->getManager( $manager->getID() )->getRequirements() );
	}

	/** @dataProvider provideValidUploads */
	public function testValidUpload( callable $upload ): void {
		$manager = $this->newRequest( RequestStatus::Verifying, $upload() );
		$this->runJob( $manager );

		$manager = $this->getManager( $manager->getID() );
		$this->assertSame( RequestStatus::Pending, $manager->getStatus() );
		$this->assertSame( RequestHistory::Full, $manager->getHistory() );
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

	public function testZipWithSeveralXmlFilesIsMerged(): void {
		$manager = $this->newRequest( RequestStatus::Verifying, ZipFixture::build( [
			'a.xml' => self::DUMP,
			'b.xml' => str_replace( '<title>A</title>', '<title>B</title>', self::DUMP ),
		] ) );

		$this->runJob( $manager );

		$manager = $this->getManager( $manager->getID() );
		$this->assertSame( RequestStatus::Pending, $manager->getStatus() );
		$this->assertTrue( $manager->fileExists() );
		$this->assertFalse( $this->fileContents( $manager->getUnverifiedFilePath() ) );

		$merged = (string)$this->fileContents( $manager->getFilePath() );
		$this->assertSame( 2, substr_count( $merged, '</page>' ) );
		$this->assertSame( 1, substr_count( $merged, '<mediawiki' ) );
	}

	public function testValidUploadWithoutHistoryDetection(): void {
		$this->overrideConfigValue( ConfigNames::EnableHistoryDetection, false );

		$manager = $this->newRequest( RequestStatus::Verifying, self::DUMP );
		$this->runJob( $manager );

		$manager = $this->getManager( $manager->getID() );
		$this->assertSame( RequestStatus::Pending, $manager->getStatus() );
		$this->assertNull( $manager->getHistory() );
	}

	/** @dataProvider provideRejectedUploads */
	public function testRejectedUpload( callable $upload ): void {
		$manager = $this->newRequest( RequestStatus::Verifying, $upload() );
		$this->runJob( $manager );

		$manager = $this->getManager( $manager->getID() );
		$this->assertSame( RequestStatus::Declined, $manager->getStatus() );
		$this->assertNull( $manager->getHistory() );
		$this->assertFalse( $manager->fileExists() );
		$this->assertFalse( $this->fileContents( $manager->getUnverifiedFilePath() ) );

		$comments = $manager->getComments();
		$this->assertCount( 1, $comments );
		$this->assertSame( 'RequestImport Status Update', $comments[0]['user']->getName() );
	}

	public static function provideRejectedUploads(): Generator {
		yield 'not a dump' => [ static fn (): string => '<html/>' ];
		yield 'zip with an xml file and another file' => [
			static fn (): string => ZipFixture::build( [ 'a.xml' => self::DUMP, 'b.txt' => 'text' ] ),
		];

		yield 'zip with an unsafe name' => [
			static fn (): string => ZipFixture::build( [ '../dump.xml' => self::DUMP ] ),
		];

		yield 'gzip of something else' => [ static fn (): string => gzencode( 'content' ) ];
	}

	public function testMissingFile(): void {
		$manager = $this->newRequest( RequestStatus::Verifying, self::DUMP );
		$manager->deleteUnverifiedFile();

		$this->runJob( $manager );

		$this->assertSame( RequestStatus::Declined, $this->getManager( $manager->getID() )->getStatus() );
	}

	public function testImagesAreStored(): void {
		$manager = $this->newTypedRequest( RequestStatus::Verifying, ZipFixture::build( [
			'a.png' => self::png(),
			'folder/b.png' => self::png(),
		] ), RequestType::Images );

		$this->runJob( $manager );

		$manager = $this->getManager( $manager->getID() );
		$this->assertSame( RequestStatus::Pending, $manager->getStatus() );
		$this->assertSame( 2, $manager->getFileCount() );
		$this->assertSame( 2 * strlen( self::png() ), $manager->getFileSize() );
		$this->assertCount( 2, $manager->getImageObjectNames() );
		$this->assertFalse( $this->fileContents( $manager->getUnverifiedFilePath() ) );
		$this->assertNull( $manager->getVerificationProgress() );
	}

	public function testImagesAreCheckedAgainstTheExtensionsOfTheTarget(): void {
		$wikiId = WikiMap::getCurrentWikiId();
		$this->overrideConfigValue( MainConfigNames::LocalDatabases, [ $wikiId ] );

		$received = null;
		$this->setTemporaryHook( 'ImportDumpGetTargetState',
			static function (
				string $target,
				// @phan-suppress-next-line PhanUnusedClosureParameter
				array $requesterGroups,
				?array &$extensions,
				array &$settings,
				array &$rights
			) use ( &$received ): void {
				$received = $target;
				$settings['fileExtensions'] = [ 'jpg' ];
			}
		);

		$manager = $this->newTypedRequest( RequestStatus::Verifying, ZipFixture::build( [
			'a.png' => self::png(),
		] ), RequestType::Images );

		$this->getDb()->newUpdateQueryBuilder()
			->update( 'import_requests' )
			->set( [ 'request_target' => $wikiId ] )
			->where( [ 'request_id' => $manager->getID() ] )
			->caller( __METHOD__ )
			->execute();

		$this->runJob( $this->getManager( $manager->getID() ) );

		$this->assertSame( $wikiId, $received );
		$this->assertSame( RequestStatus::Declined, $this->getManager( $manager->getID() )->getStatus() );
	}

	public function testRejectedImages(): void {
		$manager = $this->newTypedRequest( RequestStatus::Verifying, 'not an archive', RequestType::Images );
		$this->runJob( $manager );

		$manager = $this->getManager( $manager->getID() );
		$this->assertSame( RequestStatus::Declined, $manager->getStatus() );
		$this->assertFalse( $manager->fileExists() );
		$this->assertFalse( $this->fileContents( $manager->getUnverifiedFilePath() ) );
	}

	public function testMissingImagesFile(): void {
		$manager = $this->newTypedRequest( RequestStatus::Verifying, 'content', RequestType::Images );
		$manager->deleteUnverifiedFile();

		$this->runJob( $manager );

		$this->assertSame( RequestStatus::Declined, $this->getManager( $manager->getID() )->getStatus() );
	}

	private static function png(): string {
		return (string)base64_decode( self::PNG );
	}

	/** @dataProvider provideOtherStatuses */
	public function testOtherStatusesAreLeftAlone( RequestStatus $status ): void {
		$manager = $this->newRequest( $status, self::DUMP );
		$this->runJob( $manager );

		$manager = $this->getManager( $manager->getID() );
		$this->assertSame( $status, $manager->getStatus() );
		$this->assertSame( self::DUMP, $this->fileContents( $manager->getUnverifiedFilePath() ) );
		$this->assertFalse( $manager->fileExists() );
	}

	public static function provideOtherStatuses(): Generator {
		yield 'pending' => [ RequestStatus::Pending ];
		yield 'declined' => [ RequestStatus::Declined ];
	}
}
