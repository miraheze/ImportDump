<?php

namespace Miraheze\ImportDump\Tests;

use Generator;
use MediaWiki\Context\DerivativeContext;
use MediaWiki\Context\RequestContext;
use MediaWiki\MainConfigNames;
use MediaWiki\Request\FauxRequest;
use MediaWiki\Request\WebRequest;
use MediaWiki\SpecialPage\SpecialPage;
use MediaWiki\Status\Status;
use MediaWiki\User\User;
use MediaWiki\WikiMap\WikiMap;
use Miraheze\CreateWiki\Hooks\CreateWikiHookRunner;
use Miraheze\ImportDump\Specials\SpecialRequestImport;
use SpecialPageTestBase;
use UserNotLoggedIn;
use Wikimedia\TestingAccessWrapper;
use Wikimedia\Timestamp\ConvertibleTimestamp;

/**
 * @group ImportDump
 * @group Database
 * @group Medium
 * @coversDefaultClass \Miraheze\ImportDump\Specials\SpecialRequestImport
 */
class SpecialRequestImportTest extends SpecialPageTestBase {

	private SpecialRequestImport $specialRequestImport;

	/**
	 * @inheritDoc
	 */
	protected function newSpecialPage() {
		$services = $this->getServiceContainer();
		return new SpecialRequestImport(
			$services->getConnectionProvider(),
			$services->getMimeAnalyzer(),
			$services->getPermissionManager(),
			$services->getRepoGroup(),
			$services->getUserFactory(),
			$this->createMock( CreateWikiHookRunner::class )
		);
	}

	protected function setUp(): void {
		parent::setUp();

		$this->setMwGlobals( MainConfigNames::VirtualDomainsMapping, [
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
	public function testConstructor() {
		$this->assertInstanceOf( SpecialRequestImport::class, $this->specialRequestImport );
	}

	/**
	 * @covers ::execute
	 */
	public function testExecute() {
		$performer = $this->getTestUser()->getAuthority();
		[ $html, ] = $this->executeSpecialPage( '', null, 'qqx', $performer );
		$this->assertStringContainsString( '(requestimport-text)', $html );
	}

	/**
	 * @covers ::execute
	 */
	public function testExecuteNotLoggedIn() {
		$this->expectException( UserNotLoggedIn::class );
		$this->executeSpecialPage();
	}

	/**
	 * @dataProvider onSubmitDataProvider
	 * @covers ::onSubmit
	 */
	public function testOnSubmit( array $formData, array $extraData, ?string $expectedError ) {
		ConvertibleTimestamp::setFakeTime( ConvertibleTimestamp::now() );

		if ( $formData['UploadFile'] ) {
			// Create a test file
			file_put_contents( $formData['UploadFile'], $extraData['testfile'] ?? '<test>content</test>' );
		}

		$context = new DerivativeContext( $this->specialRequestImport->getContext() );
		$user = $this->getMutableTestUser()->getUser();

		$context->setUser( $user );

		if ( $extraData['session'] ) {
			$this->setSessionUser( $user, $user->getRequest() );
		}

		$request = new FauxRequest(
			[ 'wpEditToken' => $user->getEditToken() ],
			true
		);

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

	/**
	 * Data provider for testOnSubmit
	 *
	 * @return Generator
	 */
	public function onSubmitDataProvider(): Generator {
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
				'session' => true,
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
				'session' => true,
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
				'session' => true,
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
				'session' => true,
				'testfile' => 'content',
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
				'session' => false,
			],
			'sessionfailure',
		];
	}

	/**
	 * @dataProvider isValidDatabaseDataProvider
	 * @covers ::isValidDatabase
	 */
	public function testIsValidDatabase( string $target, $expected ) {
		$result = $this->specialRequestImport->isValidDatabase( $target );
		if ( is_string( $expected ) ) {
			$this->assertSame( $expected, $result->getKey() );
		} else {
			$this->assertSame( $expected, $result );
		}
	}

	/**
	 * Data provider for testIsValidDatabase
	 *
	 * @return Generator
	 */
	public function isValidDatabaseDataProvider(): Generator {
		yield 'valid database' => [ 'wikidb', true ];
		yield 'invalid database' => [ 'invalidwiki', 'importdump-invalid-target' ];
	}

	/**
	 * @dataProvider isValidReasonDataProvider
	 * @covers ::isValidReason
	 */
	public function testIsValidReason( string $reason, $expected ) {
		$result = $this->specialRequestImport->isValidReason( $reason );
		if ( is_string( $expected ) ) {
			$this->assertSame( $expected, $result->getKey() );
		} else {
			$this->assertSame( $expected, $result );
		}
	}

	/**
	 * Data provider for testIsValidReason
	 *
	 * @return Generator
	 */
	public function isValidReasonDataProvider(): Generator {
		yield 'valid reason' => [ 'Test reason', true ];
		yield 'invalid reason' => [ ' ', 'htmlform-required' ];
	}

	/**
	 * @covers ::getFormFields
	 */
	public function testGetFormFields() {
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
	public function testCheckPermissions() {
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
	public function testGetLogType() {
		$specialRequestImport = TestingAccessWrapper::newFromObject( $this->specialRequestImport );
		$result = $specialRequestImport->getLogType( 'testwiki' );
		$this->assertSame( 'importdump', $result );
	}

	/**
	 * Set a session user so we have a proper edit token in session
	 *
	 * @param User $user
	 * @param WebRequest $request
	 */
	private function setSessionUser( User $user, WebRequest $request ) {
		RequestContext::getMain()->setUser( $user );
		RequestContext::getMain()->setRequest( $request );
		TestingAccessWrapper::newFromObject( $user )->mRequest = $request;
		$request->getSession()->setUser( $user );
	}
}
