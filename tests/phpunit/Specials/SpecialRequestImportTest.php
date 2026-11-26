<?php

declare( strict_types = 1 );

namespace Miraheze\ImportDump\Tests\Specials;

use Generator;
use MediaWiki\Context\DerivativeContext;
use MediaWiki\Exception\UserNotLoggedIn;
use MediaWiki\MainConfigNames;
use MediaWiki\Message\Message;
use MediaWiki\Notification\Notification;
use MediaWiki\Notification\NotificationService;
use MediaWiki\Notification\RecipientSet;
use MediaWiki\Request\FauxRequest;
use MediaWiki\SpecialPage\SpecialPage;
use MediaWiki\Status\Status;
use MediaWiki\Tests\Specials\SpecialPageTestBase;
use MediaWiki\Upload\UploadBase;
use MediaWiki\Upload\UploadStash;
use MediaWiki\User\User;
use MediaWiki\User\UserIdentity;
use MediaWiki\WikiMap\WikiMap;
use Miraheze\ImportDump\Api\ApiRequestImportUpload;
use Miraheze\ImportDump\ConfigNames;
use Miraheze\ImportDump\Jobs\VerifyDumpJob;
use Miraheze\ImportDump\Notifications\RequestNotification;
use Miraheze\ImportDump\Request\RequestManager;
use Miraheze\ImportDump\Request\RequestStatus;
use Miraheze\ImportDump\Specials\SpecialRequestImport;
use Miraheze\ImportDump\Tests\Upload\ZipFixture;
use Miraheze\ImportDump\Upload\UploadedParts;
use Miraheze\ManageWiki\Helpers\Factories\ModuleFactory;
use Wikimedia\Rdbms\SelectQueryBuilder;
use Wikimedia\TestingAccessWrapper;
use Wikimedia\Timestamp\ConvertibleTimestamp;
use Wikimedia\Timestamp\TimestampFormat;
use function array_map;
use function base64_decode;
use function basename;
use function crc32;
use function file_exists;
use function file_put_contents;
use function filesize;
use function gzencode;
use function is_string;
use function unlink;
use const UPLOAD_ERR_OK;

/**
 * @group ImportDump
 * @group Database
 * @group medium
 * @coversDefaultClass \Miraheze\ImportDump\Specials\SpecialRequestImport
 */
class SpecialRequestImportTest extends SpecialPageTestBase {

	private const string DUMP = '<?xml version="1.0"?>' .
		'<mediawiki xmlns="http://www.mediawiki.org/xml/export-0.11/" version="0.11">' .
		'<page><title>A</title><ns>0</ns><id>1</id><revision><id>10</id><text>a</text></revision></page>' .
		'</mediawiki>';

	private SpecialRequestImport $specialRequestImport;

	/** @inheritDoc */
	protected function newSpecialPage(): SpecialRequestImport {
		return $this->newSpecialPageWithNotifications(
			$this->getServiceContainer()->getNotificationService()
		);
	}

	private function newSpecialPageWithNotifications( NotificationService $notifications ): SpecialRequestImport {
		$services = $this->getServiceContainer();
		return new SpecialRequestImport(
			$services->getConnectionProvider(),
			$services->getService( 'RequestImport.AttentionNotifier' ),
			$services->getService( 'RequestImport.DumpProcessor' ),
			$services->getService( 'RequestImport.RequestManager' ),
			$services->getJobQueueGroup(),
			$services->getMimeAnalyzer(),
			$notifications,
			$services->getRepoGroup(),
			$services->getUploadVerification(),
			$services->getUserFactory(),
			$this->createMock( ModuleFactory::class )
		);
	}

	protected function setUp(): void {
		parent::setUp();

		$this->overrideConfigValue( MainConfigNames::VirtualDomainsMapping, [
			'virtual-requestimport' => [ 'db' => WikiMap::getCurrentWikiId() ],
		] );

		$this->specialRequestImport = $this->newSpecialPage();
	}

	protected function tearDown(): void {
		if ( file_exists( __DIR__ . '/testfile.xml' ) ) {
			unlink( __DIR__ . '/testfile.xml' );
		}

		parent::tearDown();
	}

	/**
	 * @covers ::__construct
	 */
	public function testConstructor(): void {
		$this->assertInstanceOf( SpecialRequestImport::class, $this->specialRequestImport );
	}

	/**
	 * @covers ::execute
	 */
	public function testExecute(): void {
		$performer = $this->getTestUser()->getAuthority();
		[ $html, ] = $this->executeSpecialPage( '', null, 'qqx', $performer );
		$this->assertStringContainsString( '(requestimport-text)', $html );
	}

	/**
	 * @covers ::execute
	 */
	public function testExecuteNotLoggedIn(): void {
		$this->expectException( UserNotLoggedIn::class );
		$this->executeSpecialPage();
	}

	/**
	 * @covers ::onSubmit
	 * @dataProvider onSubmitDataProvider
	 */
	public function testOnSubmit( array $formData, array $extraData, ?string $expectedError ): void {
		// The stored file is named after the timestamp, and the files of earlier data sets are
		// still there, so every data set needs a timestamp of its own.
		ConvertibleTimestamp::setFakeTime( 1700000000 + crc32( (string)$this->dataName() ) % 1000000 );

		if ( $formData['UploadFile'] ) {
			// Create a test file
			file_put_contents( $formData['UploadFile'], $extraData['testfile'] ?? self::DUMP );
		}

		$context = new DerivativeContext( $this->specialRequestImport->getContext() );
		$user = $this->getMutableTestUser()->getUser();
		$context->setUser( $user );

		$data = [];
		if ( $extraData['token'] ) {
			$data = [ 'wpEditToken' => $context->getCsrfTokenSet()->getToken()->toString() ];
		}

		$request = new FauxRequest( $data, true );
		$request->setUpload( 'wpUploadFile', [
			'name' => basename( $formData['UploadFile'] ),
			'type' => $extraData['mime-type'],
			'tmp_name' => $formData['UploadFile'],
			'error' => UPLOAD_ERR_OK,
			'size' => filesize( $formData['UploadFile'] ),
		] );

		$context->setRequest( $request );

		$specialRequestImport = TestingAccessWrapper::newFromObject( $this->specialRequestImport );
		$specialRequestImport->setContext( $context );

		$status = $specialRequestImport->onSubmit( $formData );
		$this->assertInstanceOf( Status::class, $status );
		if ( !$expectedError ) {
			$this->assertStatusGood( $status );
			$this->assertLatestRequest( $extraData['status'] ?? RequestStatus::Pending );
		} else {
			$this->assertStatusError( $expectedError, $status );
		}

		if ( $extraData['duplicate'] ) {
			$status = $specialRequestImport->onSubmit( $formData );
			$this->assertInstanceOf( Status::class, $status );
			$this->assertStatusError( 'requestimport-duplicate-request', $status );
		}
	}

	/**
	 * @param array<string, string> $formData
	 * @param array<string, string> $uploads Form field name, such as wpUploadImages, to the contents.
	 */
	private function submitWithUploads( array $formData, array $uploads ): Status {
		$context = new DerivativeContext( $this->specialRequestImport->getContext() );
		$user = $this->getMutableTestUser()->getUser();
		$context->setUser( $user );

		$request = new FauxRequest(
			[ 'wpEditToken' => $context->getCsrfTokenSet()->getToken()->toString() ],
			true
		);

		foreach ( $uploads as $field => $contents ) {
			$path = $this->getNewTempFile();
			file_put_contents( $path, $contents );
			$request->setUpload( $field, [
				'name' => basename( $path ),
				'type' => 'application/octet-stream',
				'tmp_name' => $path,
				'error' => UPLOAD_ERR_OK,
				'size' => filesize( $path ),
			] );
		}

		$context->setRequest( $request );
		$specialRequestImport = TestingAccessWrapper::newFromObject( $this->specialRequestImport );
		$specialRequestImport->setContext( $context );

		return $specialRequestImport->onSubmit( $formData + [
			'source' => 'http://example.com',
			'target' => 'wikidb',
			'reason' => 'Test reason ' . $this->dataName() . $this->getName(),
			'UploadSourceType' => 'File',
			'UploadFile' => '',
		] );
	}

	private static function imagesZip(): string {
		return ZipFixture::build( [ 'a.png' => (string)base64_decode(
			'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR4' .
			'2mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg=='
		) ] );
	}

	/**
	 * @covers ::onSubmit
	 */
	public function testImagesOnlyRequestStandsAlone(): void {
		ConvertibleTimestamp::setFakeTime( 1700000100 );
		$this->overrideConfigValue( ConfigNames::EnableImageImports, true );

		$status = $this->submitWithUploads( [], [ 'wpUploadImages' => self::imagesZip() ] );
		$this->assertStatusGood( $status );

		$row = $this->getDb()->newSelectQueryBuilder()
			->select( [ 'request_type', 'request_status', 'request_linked_id' ] )
			->from( 'import_requests' )
			->orderBy( 'request_id', SelectQueryBuilder::SORT_DESC )
			->caller( __METHOD__ )
			->fetchRow();

		$this->assertSame( 'images', $row->request_type );
		$this->assertSame( RequestStatus::Verifying->value, $row->request_status );
		$this->assertNull( $row->request_linked_id );
	}

	/**
	 * @covers ::onSubmit
	 */
	public function testImagesWithXmlMakeLinkedRequests(): void {
		ConvertibleTimestamp::setFakeTime( 1700000200 );
		$this->overrideConfigValue( ConfigNames::EnableImageImports, true );

		$status = $this->submitWithUploads( [], [
			'wpUploadFile' => self::DUMP,
			'wpUploadImages' => self::imagesZip(),
		] );
		$this->assertStatusGood( $status );

		$rows = $this->getDb()->newSelectQueryBuilder()
			->select( [ 'request_id', 'request_type', 'request_linked_id' ] )
			->from( 'import_requests' )
			->orderBy( 'request_id', SelectQueryBuilder::SORT_DESC )
			->limit( 2 )
			->caller( __METHOD__ )
			->fetchResultSet();

		$images = $rows->fetchObject();
		$xml = $rows->fetchObject();
		$this->assertSame( 'images', $images->request_type );
		$this->assertSame( 'xml', $xml->request_type );
		$this->assertSame( (int)$xml->request_id, (int)$images->request_linked_id );
		$this->assertNull( $xml->request_linked_id );
	}

	/**
	 * @covers ::onSubmit
	 */
	public function testNothingUploaded(): void {
		$this->overrideConfigValue( ConfigNames::EnableImageImports, true );
		$status = $this->submitWithUploads( [], [] );
		$this->assertStatusError( 'requestimport-upload-nothing', $status );
	}

	/**
	 * @covers ::onSubmit
	 */
	public function testImagesThatAreNotAnArchiveAreRejected(): void {
		$this->overrideConfigValue( ConfigNames::EnableImageImports, true );
		$status = $this->submitWithUploads( [], [ 'wpUploadImages' => 'plain text' ] );
		$this->assertStatusError( 'requestimport-images-not-archive', $status );
	}

	private function assertLatestRequest( RequestStatus $expectedStatus ): void {
		$row = $this->getDb()->newSelectQueryBuilder()
			->select( [ 'request_status', 'request_history' ] )
			->from( 'import_requests' )
			->orderBy( 'request_id', SelectQueryBuilder::SORT_DESC )
			->caller( __METHOD__ )
			->fetchRow();

		$this->assertSame( $expectedStatus->value, $row->request_status );
		$this->assertNull( $row->request_history );

		$queued = $this->getServiceContainer()->getJobQueueGroup()->get( VerifyDumpJob::JOB_NAME )->getSize();
		$this->assertSame( $expectedStatus === RequestStatus::Verifying ? 1 : 0, $queued );
	}

	public static function onSubmitDataProvider(): Generator {
		yield 'valid data' => [
			[
				'source' => 'http://example.com',
				'target' => 'wikidb',
				'reason' => 'Test reason',
				'UploadSourceType' => 'File',
				'UploadFile' => __DIR__ . '/testfile.xml',
			],
			[
				'mime-type' => 'application/xml',
				'duplicate' => false,
				'token' => true,
			],
			null,
		];

		yield 'zip archive' => [
			[
				'source' => 'http://example.com',
				'target' => 'wikidb',
				'reason' => 'Test reason',
				'UploadSourceType' => 'File',
				'UploadFile' => __DIR__ . '/testfile.xml',
			],
			[
				'mime-type' => 'application/zip',
				'duplicate' => false,
				'testfile' => ZipFixture::build( [ 'dump.xml' => self::DUMP ] ),
				'status' => RequestStatus::Verifying,
				'token' => true,
			],
			null,
		];

		yield 'gzip archive' => [
			[
				'source' => 'http://example.com',
				'target' => 'wikidb',
				'reason' => 'Test reason',
				'UploadSourceType' => 'File',
				'UploadFile' => __DIR__ . '/testfile.xml',
			],
			[
				'mime-type' => 'application/gzip',
				'duplicate' => false,
				'testfile' => gzencode( self::DUMP ),
				'status' => RequestStatus::Verifying,
				'token' => true,
			],
			null,
		];

		yield 'duplicate data' => [
			[
				'source' => 'http://example.com',
				'target' => 'wikidb',
				'reason' => 'Test reason',
				'UploadSourceType' => 'File',
				'UploadFile' => __DIR__ . '/testfile.xml',
			],
			[
				'mime-type' => 'application/xml',
				'duplicate' => true,
				'token' => true,
			],
			null,
		];

		yield 'empty file' => [
			[
				'source' => '',
				'target' => '',
				'reason' => '',
				'UploadSourceType' => 'File',
				'UploadFile' => '',
			],
			[
				'mime-type' => 'application/xml',
				'duplicate' => false,
				'token' => true,
			],
			'empty-file',
		];

		yield 'mime mismatch' => [
			[
				'source' => 'http://example.com',
				'target' => 'wikidb',
				'reason' => 'Test reason',
				'UploadSourceType' => 'File',
				'UploadFile' => __DIR__ . '/testfile.xml',
			],
			[
				'mime-type' => 'text/plain',
				'duplicate' => false,
				'testfile' => 'content',
				'token' => true,
			],
			'filetype-mime-mismatch',
		];

		yield 'not an export' => [
			[
				'source' => 'http://example.com',
				'target' => 'wikidb',
				'reason' => 'Test reason',
				'UploadSourceType' => 'File',
				'UploadFile' => __DIR__ . '/testfile.xml',
			],
			[
				'mime-type' => 'application/xml',
				'duplicate' => false,
				'testfile' => '<?xml version="1.0"?><other/>',
				'token' => true,
			],
			'requestimport-xml-not-dump',
		];

		yield 'session failure' => [
			[
				'source' => '',
				'target' => '',
				'reason' => '',
				'UploadSourceType' => 'File',
				'UploadFile' => '',
			],
			[
				'mime-type' => 'application/xml',
				'duplicate' => false,
				'token' => false,
			],
			'sessionfailure',
		];
	}

	/**
	 * @covers ::getFormFields
	 */
	public function testGetFormFieldsChunkedUploads(): void {
		$specialRequestImport = TestingAccessWrapper::newFromObject( $this->specialRequestImport );
		$this->assertArrayNotHasKey( 'UploadFileKey', $specialRequestImport->getFormFields() );

		$this->overrideConfigValue( ConfigNames::EnableChunkedUploads, true );

		$formFields = $specialRequestImport->getFormFields();
		$this->assertArrayHasKey( 'UploadFileKey', $formFields );
		$this->assertSame( 'hidden', $formFields['UploadFileKey']['type'] );
		$this->assertArrayNotHasKey( 'UploadImagesFileKey', $formFields );

		$this->overrideConfigValue( ConfigNames::EnableImageImports, true );

		$formFields = $specialRequestImport->getFormFields();
		$this->assertArrayHasKey( 'UploadImagesFileKey', $formFields );
		$this->assertSame( 'hidden', $formFields['UploadImagesFileKey']['type'] );
	}

	/**
	 * @covers ::getFormFields
	 */
	public function testFileFieldsAcceptWhatWindowsCallsAZip(): void {
		$this->overrideConfigValue( ConfigNames::EnableImageImports, true );

		$specialRequestImport = TestingAccessWrapper::newFromObject( $this->specialRequestImport );
		$formFields = $specialRequestImport->getFormFields();

		$this->assertContains( 'application/x-zip-compressed', $formFields['UploadFile']['accept'] );
		$this->assertContains( 'application/x-zip-compressed', $formFields['UploadImages']['accept'] );
	}

	/**
	 * @covers ::execute
	 * @covers ::useChunkedUploads
	 */
	public function testExecuteChunkedUploads(): void {
		$performer = $this->getTestUser()->getAuthority();

		[ $html, ] = $this->executeSpecialPage( '', null, 'qqx', $performer );
		$this->assertStringNotContainsString( 'wpUploadFileKey', $html );

		$this->overrideConfigValue( ConfigNames::EnableChunkedUploads, true );

		[ $html, ] = $this->executeSpecialPage( '', null, 'qqx', $performer );
		$this->assertStringContainsString( 'wpUploadFileKey', $html );
	}

	/**
	 * @covers ::addChunkedUploadModule
	 */
	public function testAddChunkedUploadModule(): void {
		$specialRequestImport = TestingAccessWrapper::newFromObject( $this->specialRequestImport );
		$specialRequestImport->addChunkedUploadModule();

		$out = $this->specialRequestImport->getOutput();
		$this->assertContains( 'ext.importdump.chunkedupload', $out->getModules() );

		$vars = $out->getJsConfigVars()['wgRequestImportChunkedUpload'];
		$this->assertSame( 'xml', $vars['type'] );
		$this->assertFalse( $vars['images'] );
		$this->assertNull( $vars['maxUploadSize'] );
		$this->assertArrayHasKey( 'maxPhpUploadSize', $vars );
		$this->assertArrayHasKey( 'maxDirectUploadSize', $vars );
		$this->assertArrayHasKey( 'minChunkSize', $vars );
	}

	/**
	 * @covers ::addChunkedUploadModule
	 */
	public function testAddChunkedUploadModuleWithImages(): void {
		$this->overrideConfigValue( ConfigNames::EnableImageImports, true );

		$specialRequestImport = TestingAccessWrapper::newFromObject( $this->specialRequestImport );
		$specialRequestImport->addChunkedUploadModule();

		$vars = $this->specialRequestImport->getOutput()
			->getJsConfigVars()['wgRequestImportChunkedUpload'];

		$this->assertTrue( $vars['images'] );
	}

	/**
	 * @covers ::addChunkedUploadModule
	 */
	public function testAddChunkedUploadModuleWithLimit(): void {
		$this->overrideConfigValues( [
			ConfigNames::MaxUploadSize => 123456,
			MainConfigNames::MaxUploadSize => 1000,
		] );

		$specialRequestImport = TestingAccessWrapper::newFromObject( $this->specialRequestImport );
		$specialRequestImport->addChunkedUploadModule();

		$vars = $this->specialRequestImport->getOutput()
			->getJsConfigVars()['wgRequestImportChunkedUpload'];

		$this->assertSame( 123456, $vars['maxUploadSize'] );
		$this->assertLessThanOrEqual( 1000, $vars['maxDirectUploadSize'] );
	}

	/**
	 * @covers ::addChunkedUploadModule
	 */
	public function testChunkedUploadThresholdLowersTheDirectLimit(): void {
		$this->overrideConfigValues( [
			ConfigNames::ChunkedUploadThreshold => 500,
			MainConfigNames::MaxUploadSize => 1000,
		] );

		$specialRequestImport = TestingAccessWrapper::newFromObject( $this->specialRequestImport );
		$specialRequestImport->addChunkedUploadModule();

		$vars = $this->specialRequestImport->getOutput()
			->getJsConfigVars()['wgRequestImportChunkedUpload'];

		$this->assertSame( 500, $vars['maxDirectUploadSize'] );
	}

	/**
	 * @covers ::addChunkedUploadModule
	 */
	public function testChunkedUploadThresholdCannotRaiseTheDirectLimit(): void {
		$this->overrideConfigValues( [
			ConfigNames::ChunkedUploadThreshold => 5000,
			MainConfigNames::MaxUploadSize => 1000,
		] );

		$specialRequestImport = TestingAccessWrapper::newFromObject( $this->specialRequestImport );
		$specialRequestImport->addChunkedUploadModule();

		$vars = $this->specialRequestImport->getOutput()
			->getJsConfigVars()['wgRequestImportChunkedUpload'];

		$this->assertLessThanOrEqual( 1000, $vars['maxDirectUploadSize'] );
	}

	private function stashXml( User $user ): string {
		return $this->stashContent( $user, self::DUMP );
	}

	private function stashContent( User $user, string $content ): string {
		$path = $this->getNewTempFile();
		file_put_contents( $path, $content );

		$repo = $this->getServiceContainer()->getRepoGroup()->getLocalRepo();
		return new UploadStash( $repo, $user )->stashFile( $path, 'file' )->getFileKey();
	}

	private function submitStashKeys( User $user, array $keys ): Status {
		return $this->submitStashKeysTo( $this->specialRequestImport, $user, $keys );
	}

	private function submitStashKeysTo( SpecialRequestImport $page, User $user, array $keys ): Status {
		$context = new DerivativeContext( $this->specialRequestImport->getContext() );
		$context->setUser( $user );
		$context->setRequest( new FauxRequest( [
			'wpEditToken' => $context->getCsrfTokenSet()->getToken()->toString(),
		], true ) );

		$specialRequestImport = TestingAccessWrapper::newFromObject( $page );
		$specialRequestImport->setContext( $context );

		return $specialRequestImport->onSubmit( $keys + [
			'source' => 'http://example.com',
			'target' => 'wikidb',
			'reason' => 'Stash reason ' . $this->dataName() . $this->getName(),
			'UploadFile' => '',
			'UploadFileKey' => '',
			'UploadImagesFileKey' => '',
		] );
	}

	/**
	 * @covers ::onSubmit
	 */
	public function testImagesOnlyFromStash(): void {
		ConvertibleTimestamp::setFakeTime( 1700000300 );
		$this->overrideConfigValues( [
			ConfigNames::EnableChunkedUploads => true,
			ConfigNames::EnableImageImports => true,
		] );

		$user = $this->getMutableTestUser()->getUser();
		$status = $this->submitStashKeys( $user, [
			'UploadImagesFileKey' => $this->stashContent( $user, self::imagesZip() ),
		] );
		$this->assertStatusGood( $status );

		$row = $this->getDb()->newSelectQueryBuilder()
			->select( [ 'request_type', 'request_status', 'request_linked_id' ] )
			->from( 'import_requests' )
			->orderBy( 'request_id', SelectQueryBuilder::SORT_DESC )
			->caller( __METHOD__ )
			->fetchRow();

		$this->assertSame( 'images', $row->request_type );
		$this->assertSame( RequestStatus::Verifying->value, $row->request_status );
		$this->assertNull( $row->request_linked_id );
	}

	/**
	 * @covers ::onSubmit
	 */
	public function testImagesAndXmlFromStash(): void {
		ConvertibleTimestamp::setFakeTime( 1700000400 );
		$this->overrideConfigValues( [
			ConfigNames::EnableChunkedUploads => true,
			ConfigNames::EnableImageImports => true,
		] );

		$user = $this->getMutableTestUser()->getUser();
		$status = $this->submitStashKeys( $user, [
			'UploadFileKey' => $this->stashXml( $user ),
			'UploadImagesFileKey' => $this->stashContent( $user, self::imagesZip() ),
		] );
		$this->assertStatusGood( $status );

		$rows = $this->getDb()->newSelectQueryBuilder()
			->select( [ 'request_id', 'request_type', 'request_linked_id' ] )
			->from( 'import_requests' )
			->orderBy( 'request_id', SelectQueryBuilder::SORT_DESC )
			->limit( 2 )
			->caller( __METHOD__ )
			->fetchResultSet();

		$images = $rows->fetchObject();
		$xml = $rows->fetchObject();
		$this->assertSame( 'images', $images->request_type );
		$this->assertSame( 'xml', $xml->request_type );
		$this->assertSame( (int)$xml->request_id, (int)$images->request_linked_id );
	}

	/**
	 * @covers ::onSubmit
	 * @covers ::getImagesUpload
	 */
	public function testImagesUploadedInPartsAreNotJoinedWhenSubmitted(): void {
		ConvertibleTimestamp::setFakeTime( 1700000500 );
		$this->overrideConfigValues( [
			ConfigNames::EnableChunkedUploads => true,
			ConfigNames::EnableImageImports => true,
		] );

		$user = $this->getMutableTestUser()->getUser();
		$key = $this->stashContent( $user, 'part' );
		UploadBase::setSessionStatus( $user, $key, [
			'result' => 'Success',
			'stage' => ApiRequestImportUpload::STAGE_PARTS,
			'filekey' => $key,
			'status' => Status::newGood(),
		] );

		$this->assertStatusGood( $this->submitStashKeys( $user, [ 'UploadImagesFileKey' => $key ] ) );

		$row = $this->getDb()->newSelectQueryBuilder()
			->select( [ 'request_id', 'request_type', 'request_status' ] )
			->from( 'import_requests' )
			->orderBy( 'request_id', SelectQueryBuilder::SORT_DESC )
			->caller( __METHOD__ )
			->fetchRow();

		$this->assertSame( 'images', $row->request_type );
		$this->assertSame( RequestStatus::Verifying->value, $row->request_status );

		$manager = $this->getServiceContainer()->getService( 'RequestImport.RequestManager' );
		'@phan-var RequestManager $manager';
		$manager->loadFromID( (int)$row->request_id );

		$reference = $manager->getPartsReference();
		$this->assertSame( $key, $reference['filekey'] ?? null );
		$this->assertSame( $user->getId(), $reference['user'] ?? null );
		$this->assertFalse( $manager->fileExists() );
	}

	/**
	 * @covers ::getImagesUpload
	 */
	public function testImagesThatAreStillBeingUploadedInPartsAreRefused(): void {
		$this->overrideConfigValues( [
			ConfigNames::EnableChunkedUploads => true,
			ConfigNames::EnableImageImports => true,
		] );

		$user = $this->getMutableTestUser()->getUser();
		$key = $this->stashContent( $user, 'part' );
		UploadBase::setSessionStatus( $user, $key, [
			'result' => 'Continue',
			'stage' => 'uploading',
			'status' => Status::newGood(),
		] );

		$this->assertStatusError(
			'requestimport-images-parts-incomplete',
			$this->submitStashKeys( $user, [ 'UploadImagesFileKey' => $key ] )
		);
	}

	/**
	 * @covers ::onSubmit
	 * @covers ::setPartsReference
	 */
	public function testXmlUploadedInPartsIsNotJoinedWhenSubmitted(): void {
		ConvertibleTimestamp::setFakeTime( 1700000600 );
		$this->overrideConfigValue( ConfigNames::EnableChunkedUploads, true );

		$user = $this->getMutableTestUser()->getUser();
		$key = $this->stashContent( $user, 'part' );
		UploadBase::setSessionStatus( $user, $key, [
			'result' => 'Success',
			'stage' => ApiRequestImportUpload::STAGE_PARTS,
			'filekey' => $key,
			'status' => Status::newGood(),
		] );

		$this->assertStatusGood( $this->submitStashKeys( $user, [ 'UploadFileKey' => $key ] ) );

		$row = $this->getDb()->newSelectQueryBuilder()
			->select( [ 'request_id', 'request_type', 'request_status' ] )
			->from( 'import_requests' )
			->orderBy( 'request_id', SelectQueryBuilder::SORT_DESC )
			->caller( __METHOD__ )
			->fetchRow();

		$this->assertSame( 'xml', $row->request_type );
		$this->assertSame( RequestStatus::Verifying->value, $row->request_status );

		$manager = $this->getServiceContainer()->getService( 'RequestImport.RequestManager' );
		'@phan-var RequestManager $manager';
		$manager->loadFromID( (int)$row->request_id );

		$reference = $manager->getPartsReference();
		$this->assertSame( $key, $reference['filekey'] ?? null );
		$this->assertSame( $user->getId(), $reference['user'] ?? null );
		$this->assertFalse( $manager->fileExists() );
	}

	private function newPageWhereThePartsCannotBeNoted(): SpecialRequestImport {
		$manager = $this->createMock( RequestManager::class );
		$manager->method( 'setPartsReference' )
			->willReturn( Status::newFatal( 'requestimport-verify-missing' ) );

		$services = $this->getServiceContainer();
		return new SpecialRequestImport(
			$services->getConnectionProvider(),
			$services->getService( 'RequestImport.AttentionNotifier' ),
			$services->getService( 'RequestImport.DumpProcessor' ),
			$manager,
			$services->getJobQueueGroup(),
			$services->getMimeAnalyzer(),
			$services->getNotificationService(),
			$services->getRepoGroup(),
			$services->getUploadVerification(),
			$services->getUserFactory(),
			$this->createMock( ModuleFactory::class )
		);
	}

	private function countRequests(): int {
		return (int)$this->getDb()->newSelectQueryBuilder()
			->select( 'COUNT(*)' )
			->from( 'import_requests' )
			->caller( __METHOD__ )
			->fetchField();
	}

	/**
	 * @covers ::onSubmit
	 * @covers ::setPartsReference
	 */
	public function testXmlRequestIsRemovedWhenItsPartsCannotBeNoted(): void {
		$this->overrideConfigValue( ConfigNames::EnableChunkedUploads, true );

		$user = $this->getMutableTestUser()->getUser();
		$key = $this->stashContent( $user, 'part' );
		UploadBase::setSessionStatus( $user, $key, [
			'result' => 'Success',
			'stage' => ApiRequestImportUpload::STAGE_PARTS,
			'filekey' => $key,
			'status' => Status::newGood(),
		] );

		$before = $this->countRequests();
		$status = $this->submitStashKeysTo(
			$this->newPageWhereThePartsCannotBeNoted(),
			$user,
			[ 'UploadFileKey' => $key ]
		);

		$this->assertStatusError( 'requestimport-verify-missing', $status );
		$this->assertSame( $before, $this->countRequests() );
	}

	/**
	 * @covers ::onSubmit
	 * @covers ::setPartsReference
	 */
	public function testImagesRequestIsRemovedWhenItsPartsCannotBeNoted(): void {
		$this->overrideConfigValues( [
			ConfigNames::EnableChunkedUploads => true,
			ConfigNames::EnableImageImports => true,
		] );

		$user = $this->getMutableTestUser()->getUser();
		$key = $this->stashContent( $user, 'part' );
		UploadBase::setSessionStatus( $user, $key, [
			'result' => 'Success',
			'stage' => ApiRequestImportUpload::STAGE_PARTS,
			'filekey' => $key,
			'status' => Status::newGood(),
		] );

		$before = $this->countRequests();
		$status = $this->submitStashKeysTo(
			$this->newPageWhereThePartsCannotBeNoted(),
			$user,
			[ 'UploadImagesFileKey' => $key ]
		);

		$this->assertStatusError( 'requestimport-verify-missing', $status );
		$this->assertSame( $before, $this->countRequests() );
	}

	/**
	 * @covers ::setPartsReference
	 */
	public function testPartsCannotBeNotedForARequestThatWasNotAdded(): void {
		$page = TestingAccessWrapper::newFromObject( $this->specialRequestImport );
		$this->assertStatusError(
			'requestimport-duplicate-request',
			$page->setPartsReference( 0, new UploadedParts( 'abc.xml' ) )
		);
	}

	/**
	 * @covers ::onSubmit
	 */
	public function testXmlThatIsStillBeingUploadedInPartsIsRefused(): void {
		$this->overrideConfigValue( ConfigNames::EnableChunkedUploads, true );

		$user = $this->getMutableTestUser()->getUser();
		$key = $this->stashContent( $user, 'part' );
		UploadBase::setSessionStatus( $user, $key, [
			'result' => 'Continue',
			'stage' => 'uploading',
			'status' => Status::newGood(),
		] );

		$this->assertStatusError(
			'requestimport-xml-parts-incomplete',
			$this->submitStashKeys( $user, [ 'UploadFileKey' => $key ] )
		);
	}

	/**
	 * @covers ::onSubmit
	 */
	public function testImagesFromStashInvalidKey(): void {
		$this->overrideConfigValues( [
			ConfigNames::EnableChunkedUploads => true,
			ConfigNames::EnableImageImports => true,
		] );

		$status = $this->submitStashKeys(
			$this->getMutableTestUser()->getUser(),
			[ 'UploadImagesFileKey' => 'invalid' ]
		);
		$this->assertStatusError( 'apierror-invalid-file-key', $status );
	}

	/**
	 * @covers ::onSubmit
	 */
	public function testImagesFromStashMissingKey(): void {
		$this->overrideConfigValues( [
			ConfigNames::EnableChunkedUploads => true,
			ConfigNames::EnableImageImports => true,
		] );

		$status = $this->submitStashKeys(
			$this->getMutableTestUser()->getUser(),
			[ 'UploadImagesFileKey' => 'doesnotexist.archive' ]
		);
		$this->assertStatusError( 'uploadstash-exception', $status );
	}

	/**
	 * @covers ::onSubmit
	 */
	public function testImagesFromStashThatAreNotAnArchive(): void {
		$this->overrideConfigValues( [
			ConfigNames::EnableChunkedUploads => true,
			ConfigNames::EnableImageImports => true,
		] );

		$user = $this->getMutableTestUser()->getUser();
		$status = $this->submitStashKeys( $user, [
			'UploadImagesFileKey' => $this->stashContent( $user, 'plain text' ),
		] );
		$this->assertStatusError( 'requestimport-images-not-archive', $status );
	}

	/**
	 * @covers ::onSubmit
	 */
	public function testImagesKeyIsIgnoredWithoutChunkedUploads(): void {
		$this->overrideConfigValue( ConfigNames::EnableImageImports, true );

		$user = $this->getMutableTestUser()->getUser();
		$status = $this->submitStashKeys( $user, [
			'UploadImagesFileKey' => $this->stashContent( $user, self::imagesZip() ),
		] );
		$this->assertStatusError( 'requestimport-upload-nothing', $status );
	}

	private function submitStashKey( User $user, string $fileKey ): Status {
		ConvertibleTimestamp::setFakeTime( (int)ConvertibleTimestamp::now( TimestampFormat::UNIX ) + 86400 );

		$context = new DerivativeContext( $this->specialRequestImport->getContext() );
		$context->setUser( $user );
		$context->setRequest( new FauxRequest( [
			'wpEditToken' => $context->getCsrfTokenSet()->getToken()->toString(),
		], true ) );

		$specialRequestImport = TestingAccessWrapper::newFromObject( $this->specialRequestImport );
		$specialRequestImport->setContext( $context );

		return $specialRequestImport->onSubmit( [
			'source' => 'http://example.com',
			'target' => 'wikidb',
			'reason' => 'Stash reason',
			'UploadFile' => '',
			'UploadFileKey' => $fileKey,
		] );
	}

	/**
	 * @covers ::onSubmit
	 */
	public function testOnSubmitFromStash(): void {
		$this->overrideConfigValue( ConfigNames::EnableChunkedUploads, true );

		$user = $this->getMutableTestUser()->getUser();
		$status = $this->submitStashKey( $user, $this->stashXml( $user ) );
		$this->assertStatusGood( $status );
	}

	/**
	 * @covers ::onSubmit
	 */
	public function testOnSubmitFromStashInvalidKey(): void {
		$this->overrideConfigValue( ConfigNames::EnableChunkedUploads, true );

		$user = $this->getMutableTestUser()->getUser();
		$status = $this->submitStashKey( $user, 'invalid' );
		$this->assertStatusError( 'apierror-invalid-file-key', $status );
	}

	/**
	 * @covers ::onSubmit
	 */
	public function testOnSubmitFromStashMissingKey(): void {
		$this->overrideConfigValue( ConfigNames::EnableChunkedUploads, true );

		$user = $this->getMutableTestUser()->getUser();
		$status = $this->submitStashKey( $user, 'doesnotexist.xml' );
		$this->assertStatusError( 'uploadstash-exception', $status );
	}

	/**
	 * @covers ::onSubmit
	 */
	public function testOnSubmitFromStashWrongOwner(): void {
		$this->overrideConfigValue( ConfigNames::EnableChunkedUploads, true );

		$owner = $this->getMutableTestUser()->getUser();
		$fileKey = $this->stashXml( $owner );

		$other = $this->getTestSysop()->getUser();
		$status = $this->submitStashKey( $other, $fileKey );
		$this->assertStatusError( 'uploadstash-exception', $status );
	}

	/**
	 * @covers ::isValidDatabase
	 * @dataProvider isValidDatabaseDataProvider
	 */
	public function testIsValidDatabase( string $target, string|true $expected ): void {
		if ( $target === 'validwiki' ) {
			$target = $this->getConfVar( MainConfigNames::LocalDatabases )[0];
		}

		$result = $this->specialRequestImport->isValidDatabase( $target );
		if ( is_string( $expected ) ) {
			$this->assertSame( $expected, $result->getKey() );
		} else {
			$this->assertSame( $expected, $result );
		}
	}

	public static function isValidDatabaseDataProvider(): Generator {
		yield 'valid database' => [ 'validwiki', true ];
		yield 'invalid database' => [ 'invalidwiki', 'requestimport-invalid-target' ];
	}

	/**
	 * @covers ::isValidReason
	 * @dataProvider isValidReasonDataProvider
	 */
	public function testIsValidReason( string $reason, string|true $expected ): void {
		$result = $this->specialRequestImport->isValidReason( $reason );
		if ( is_string( $expected ) ) {
			$this->assertSame( $expected, $result->getKey() );
		} else {
			$this->assertSame( $expected, $result );
		}
	}

	public static function isValidReasonDataProvider(): Generator {
		yield 'valid reason' => [ 'Test reason', true ];
		yield 'invalid reason' => [ ' ', 'htmlform-required' ];
	}

	/**
	 * @covers ::getFormFields
	 */
	public function testGetFormFields(): void {
		$specialRequestImport = TestingAccessWrapper::newFromObject( $this->specialRequestImport );
		$formFields = $specialRequestImport->getFormFields();

		$this->assertIsArray( $formFields );
		$this->assertArrayHasKey( 'source', $formFields );
		$this->assertArrayHasKey( 'target', $formFields );
		$this->assertArrayHasKey( 'reason', $formFields );
		$this->assertArrayHasKey( 'UploadFile', $formFields );

		$this->assertArrayNotHasKey( 'UploadSourceType', $formFields );
		$this->assertArrayNotHasKey( 'UploadFileURL', $formFields );

		$this->overrideConfigValues( [
			MainConfigNames::EnableUploads => true,
			MainConfigNames::AllowCopyUploads => true,
		] );

		// We still shouldn't have them as we don't have upload_by_url permission yet
		$this->assertArrayNotHasKey( 'UploadSourceType', $formFields );
		$this->assertArrayNotHasKey( 'UploadFileURL', $formFields );

		$this->setGroupPermissions( 'user', 'upload_by_url', true );

		$context = new DerivativeContext( $specialRequestImport->getContext() );
		$user = $this->getTestUser()->getUser();

		$context->setUser( $user );
		$context->setTitle( SpecialPage::getTitleFor( 'RequestImport' ) );

		$specialRequestImport->setContext( $context );

		$formFields = $specialRequestImport->getFormFields();

		// We should now have them
		$this->assertArrayHasKey( 'UploadSourceType', $formFields );
		$this->assertArrayHasKey( 'UploadFileURL', $formFields );
	}

	/**
	 * @return list<Message>
	 */
	private function getUploadHelp(): array {
		$specialRequestImport = TestingAccessWrapper::newFromObject( $this->specialRequestImport );
		$help = $specialRequestImport->getFormFields()['UploadFile']['help-messages'];
		$this->assertContainsOnlyInstancesOf( Message::class, $help );

		return $help;
	}

	/**
	 * @covers ::getUploadHelp
	 */
	public function testUploadHelpListsTheArchiveTypesThatAreSetUp(): void {
		$help = $this->getUploadHelp();

		$this->assertSame( 'requestimport-help-upload-xml', $help[0]->getKey() );
		$this->assertSame( 'requestimport-help-upload-xml-types', $help[1]->getKey() );
		$this->assertSame( [
			'ZIP, TAR, GZIP and BZIP2',
			'.tar.gz, .tgz and .tar.bz2',
		], $help[1]->getParams() );
	}

	/**
	 * @covers ::getUploadHelp
	 */
	public function testUploadHelpOnlyMentionsHistoryWhenItIsChecked(): void {
		$keys = static fn ( array $help ): array => array_map(
			static fn ( Message $message ): string => $message->getKey(),
			$help
		);

		$this->overrideConfigValue( ConfigNames::EnableHistoryDetection, false );
		$this->assertNotContains( 'requestimport-help-upload-xml-history', $keys( $this->getUploadHelp() ) );

		$this->overrideConfigValue( ConfigNames::EnableHistoryDetection, true );
		$this->assertContains( 'requestimport-help-upload-xml-history', $keys( $this->getUploadHelp() ) );
	}

	/**
	 * @covers ::checkPermissions
	 */
	public function testCheckPermissions(): void {
		$user = $this->getTestUser()->getUser();
		$context = new DerivativeContext( $this->specialRequestImport->getContext() );

		$context->setUser( $user );
		$context->setTitle( SpecialPage::getTitleFor( 'RequestImport' ) );

		$specialRequestImport = TestingAccessWrapper::newFromObject( $this->specialRequestImport );
		$specialRequestImport->setContext( $context );
		$this->assertNull( $specialRequestImport->checkPermissions() );
	}

	/**
	 * @covers ::getLogType
	 */
	public function testGetLogType(): void {
		$result = $this->specialRequestImport->getLogType( 'testwiki' );
		$this->assertSame( 'requestimport', $result );
	}

	/**
	 * @covers ::getRestriction
	 */
	public function testGetRestriction(): void {
		$this->assertSame( 'request-import', $this->specialRequestImport->getRestriction() );
	}

	/**
	 * @covers ::sendNotifications
	 */
	public function testSendNotificationsOnlyReachesThoseWhoMayHandleRequests(): void {
		$this->setGroupPermissions( 'sysop', 'handle-import-requests', true );
		$this->overrideConfigValue( ConfigNames::UsersNotifiedOnAllRequests, [
			$this->getTestSysop()->getUser()->getName(),
			$this->getTestUser()->getUser()->getName(),
			'Nobody that exists',
		] );

		$captured = [];
		$service = $this->createMock( NotificationService::class );
		$service->method( 'notify' )->willReturnCallback(
			static function ( Notification $notification, RecipientSet $recipients ) use ( &$captured ): void {
				$captured[] = [ $notification, $recipients ];
			}
		);

		$requester = $this->getMutableTestUser()->getUser();
		$this->newSpecialPageWithNotifications( $service )
			->sendNotifications( 'A reason', '12', 'wikidb', $requester );

		'@phan-var list<array{Notification, RecipientSet}> $captured';
		$this->assertCount( 1, $captured );
		[ $notification, $recipients ] = $captured[0];

		$this->assertInstanceOf( RequestNotification::class, $notification );
		$this->assertSame( 'requestimport-new-request', $notification->getType() );
		$this->assertSame( $requester->getName(), $notification->getAgent()->getName() );
		$this->assertSame( [ $this->getTestSysop()->getUser()->getName() ], array_map(
			static fn ( UserIdentity $user ): string => $user->getName(),
			$recipients->getRecipients()
		) );

		$properties = $notification->getProperties();
		$this->assertSame( '12', $properties['request-id'] );
		$this->assertSame( 'A reason', $properties['reason'] );
		$this->assertSame( $requester->getName(), $properties['requester'] );
		$this->assertSame( 'wikidb', $properties['target'] );
		$this->assertStringContainsString( 'RequestImportQueue/12', $properties['request-url'] );
	}

	/**
	 * @covers ::sendNotifications
	 */
	public function testSendNotificationsWithNobodyToNotify(): void {
		$this->overrideConfigValue( ConfigNames::UsersNotifiedOnAllRequests, [
			$this->getTestUser()->getUser()->getName(),
		] );

		$service = $this->createMock( NotificationService::class );
		$service->expects( $this->never() )->method( 'notify' );

		$this->newSpecialPageWithNotifications( $service )
			->sendNotifications( 'A reason', '12', 'wikidb', $this->getMutableTestUser()->getUser() );
	}
}
