<?php

namespace Miraheze\ImportDump\Tests\Specials;

use Generator;
use MediaWiki\Context\DerivativeContext;
use MediaWiki\Exception\UserNotLoggedIn;
use MediaWiki\MainConfigNames;
use MediaWiki\Request\FauxRequest;
use MediaWiki\SpecialPage\SpecialPage;
use MediaWiki\Status\Status;
use MediaWiki\Upload\UploadStash;
use MediaWiki\User\User;
use MediaWiki\WikiMap\WikiMap;
use Miraheze\ImportDump\ConfigNames;
use Miraheze\ImportDump\Specials\SpecialRequestImport;
use Miraheze\ManageWiki\Helpers\Factories\ModuleFactory;
use SpecialPageTestBase;
use Wikimedia\TestingAccessWrapper;
use Wikimedia\Timestamp\ConvertibleTimestamp;

/**
 * @group ImportDump
 * @group Database
 * @group medium
 * @coversDefaultClass \Miraheze\ImportDump\Specials\SpecialRequestImport
 */
class SpecialRequestImportTest extends SpecialPageTestBase {

	private SpecialRequestImport $specialRequestImport;

	/** @inheritDoc */
	protected function newSpecialPage(): SpecialRequestImport {
		$services = $this->getServiceContainer();
		return new SpecialRequestImport(
			$services->getConnectionProvider(),
			$services->getExtensionRegistry(),
			$services->getMimeAnalyzer(),
			$services->getRepoGroup(),
			$services->getUploadVerification(),
			$services->getUserFactory(),
			$this->createMock( ModuleFactory::class )
		);
	}

	protected function setUp(): void {
		parent::setUp();

		$this->overrideConfigValue( MainConfigNames::VirtualDomainsMapping, [
			'virtual-importdump' => [ 'db' => WikiMap::getCurrentWikiId() ],
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
		ConvertibleTimestamp::setFakeTime( ConvertibleTimestamp::now() );

		if ( $formData['UploadFile'] ) {
			// Create a test file
			file_put_contents( $formData['UploadFile'], $extraData['testfile'] ?? '<test>content</test>' );
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
		} else {
			$this->assertStatusError( $expectedError, $status );
		}

		if ( $extraData['duplicate'] ) {
			$status = $specialRequestImport->onSubmit( $formData );
			$this->assertInstanceOf( Status::class, $status );
			$this->assertStatusError( 'importdump-duplicate-request', $status );
		}
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
		$this->assertNull( $vars['maxUploadSize'] );
		$this->assertArrayHasKey( 'maxPhpUploadSize', $vars );
		$this->assertArrayHasKey( 'maxDirectUploadSize', $vars );
		$this->assertArrayHasKey( 'minChunkSize', $vars );
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

	private function stashXml( User $user ): string {
		$path = $this->getNewTempFile();
		file_put_contents( $path, "<?xml version=\"1.0\"?>\n<mediawiki></mediawiki>" );

		$repo = $this->getServiceContainer()->getRepoGroup()->getLocalRepo();
		return ( new UploadStash( $repo, $user ) )->stashFile( $path, 'file' )->getFileKey();
	}

	private function submitStashKey( User $user, string $fileKey ): Status {
		ConvertibleTimestamp::setFakeTime( (int)ConvertibleTimestamp::now( TS_UNIX ) + 86400 );

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
		yield 'invalid database' => [ 'invalidwiki', 'importdump-invalid-target' ];
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
		$this->assertSame( 'importdump', $result );
	}

	/**
	 * @covers ::getRestriction
	 */
	public function testGetRestriction(): void {
		$this->assertSame( 'request-import', $this->specialRequestImport->getRestriction() );
	}
}
