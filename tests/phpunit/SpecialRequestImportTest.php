<?php

namespace Miraheze\ImportDump\Tests;

use Generator;
use MediaWiki\Context\DerivativeContext;
use MediaWiki\Context\RequestContext;
use MediaWiki\Request\FauxRequest;
use MediaWiki\Request\WebRequest;
use MediaWiki\SpecialPage\SpecialPage;
use MediaWiki\Status\Status;
use MediaWiki\User\User;
use MediaWiki\WikiMap\WikiMap;
use MediaWikiIntegrationTestCase;
use Miraheze\CreateWiki\Hooks\CreateWikiHookRunner;
use Miraheze\ImportDump\Specials\SpecialRequestImport;
use UserNotLoggedIn;
use Wikimedia\TestingAccessWrapper;

/**
 * @group ImportDump
 * @group Database
 * @group Medium
 * @coversDefaultClass \Miraheze\ImportDump\Specials\SpecialRequestImport
 */
class SpecialRequestImportTest extends MediaWikiIntegrationTestCase {

	private SpecialRequestImport $specialRequestImport;

	protected function setUp(): void {
		parent::setUp();

		$this->setMwGlobals( 'wgVirtualDomainsMapping', [
			'virtual-importdump' => [ 'db' => WikiMap::getCurrentWikiId() ],
		] );

		$this->specialRequestImport = new SpecialRequestImport(
			$this->getServiceContainer()->getConnectionProvider(),
			$this->getServiceContainer()->getMimeAnalyzer(),
			$this->getServiceContainer()->getPermissionManager(),
			$this->getServiceContainer()->getRepoGroup(),
			$this->getServiceContainer()->getUserFactory(),
			$this->createMock( CreateWikiHookRunner::class )
		);
	}

	protected function tearDown(): void {
		if ( file_exists( __DIR__ . '/testfile1.xml' ) ) {
			unlink( __DIR__ . '/testfile1.xml' );
		}

		if ( file_exists( __DIR__ . '/testfile2.xml' ) ) {
			unlink( __DIR__ . '/testfile2.xml' );
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
		$user = $this->getTestUser()->getUser();
		$testContext = new DerivativeContext( $this->specialRequestImport->getContext() );

		$testContext->setUser( $user );
		$testContext->setTitle( SpecialPage::getTitleFor( 'RequestImport' ) );

		$specialRequestImport = TestingAccessWrapper::newFromObject( $this->specialRequestImport );
		$specialRequestImport->setContext( $testContext );

		$this->assertNull( $specialRequestImport->execute( '' ) );
	}

	/**
	 * @covers ::execute
	 */
	public function testExecuteLoggedOut() {
		$this->expectException( UserNotLoggedIn::class );
		$specialRequestImport = TestingAccessWrapper::newFromObject( $this->specialRequestImport );
		$specialRequestImport->execute( '' );
	}

	/**
	 * @dataProvider onSubmitDataProvider
	 * @covers ::onSubmit
	 */
	public function testOnSubmit( array $formData, array $extraData, ?string $expectedError ) {
		if ( $formData['UploadFile'] ) {
			// Create a test file
			file_put_contents( $formData['UploadFile'], '<test>content</test>' );
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
				'UploadFile' => __DIR__ . '/testfile1.xml',
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
				'UploadFile' => __DIR__ . '/testfile1.xml',
			],
			[
				'mime-type' => 'application/xml',
				'duplicate' => true,
				'session' => true,
			],
			null,
		];

		yield 'mime mismatch' => [
			[
				'source' => 'http://example.com',
				'target' => 'wikidb',
				'reason' => 'Test reason',
				'UploadSourceType' => 'File',
				'UploadFile' => __DIR__ . '/testfile2.xml',
			],
			[
				'mime-type' => 'text/plain',
				'duplicate' => false,
				'session' => true,
			],
			'filetype-mime-mismatch',
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
	 * @return array
	 */
	public function isValidDatabaseDataProvider(): array {
		return [
			'valid database' => [ 'wikidb', true ],
			'invalid database' => [ 'invalidwiki', 'importdump-invalid-target' ],
		];
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
	 * @return array
	 */
	public function isValidReasonDataProvider(): array {
		return [
			'valid reason' => [ 'Test reason', true ],
			'invalid reason' => [ ' ', 'htmlform-required' ],
		];
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
	}

	/**
	 * @covers ::checkPermissions
	 */
	public function testCheckPermissions() {
		$user = $this->getTestUser()->getUser();
		$testContext = new DerivativeContext( $this->specialRequestImport->getContext() );

		$testContext->setUser( $user );
		$testContext->setTitle( SpecialPage::getTitleFor( 'RequestImport' ) );

		$specialRequestImport = TestingAccessWrapper::newFromObject( $this->specialRequestImport );
		$specialRequestImport->setContext( $testContext );
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

	private function setSessionUser( User $user, WebRequest $request ) {
		RequestContext::getMain()->setUser( $user );
		RequestContext::getMain()->setRequest( $request );
		TestingAccessWrapper::newFromObject( $user )->mRequest = $request;
		$request->getSession()->setUser( $user );
	}
}
