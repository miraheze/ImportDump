<?php

namespace Miraheze\ImportDump\Tests;

use MediaWiki\Context\DerivativeContext;
use MediaWiki\Request\FauxRequest;
use MediaWiki\SpecialPage\SpecialPage;
use MediaWiki\Status\Status;
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
		@unlink( __DIR__ . '/testfile.xml' );
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
	public function testOnSubmit( array $data, bool $expectedSuccess ) {
		if ( $data['UploadFile'] ) {
			// Create a test file
			file_put_contents( $data['UploadFile'], '<test>content</test>' );
		}

		$context = new DerivativeContext( $this->specialRequestImport->getContext() );
		$user = $this->getTestUser()->getUser();

		$context->setUser( $user );

		$request = new FauxRequest(
			[
				'wpEditToken' => $user->getEditToken(),
			],
			true
		);

		$request->setUpload( 'wpUploadFile', [
			'name' => basename( $data['UploadFile'] ),
			'type' => 'application/xml',
			'tmp_name' => $data['UploadFile'],
			'error' => UPLOAD_ERR_OK,
			'size' => filesize( $data['UploadFile'] ),
		] );

		$context->setRequest( $request );

		$specialRequestImport = TestingAccessWrapper::newFromObject( $this->specialRequestImport );
		$specialRequestImport->setContext( $context );

		$status = $specialRequestImport->onSubmit( $data );
		$this->assertInstanceOf( Status::class, $status );
		if ( $expectedSuccess ) {
			$this->assertStatusGood( $status );
		} else {
			$this->assertStatusError( 'empty-file', $status );
		}
	}

	/**
	 * Data provider for testOnSubmit
	 *
	 * @return array
	 */
	public function onSubmitDataProvider(): array {
		return [
			'valid data' => [
				[
					'source' => 'http://example.com',
					'target' => 'wikidb',
					'reason' => 'Test reason',
					'UploadSourceType' => 'File',
					'UploadFile' => __DIR__ . '/testfile.xml',
				],
				true,
			],
			'invalid data' => [
				[
					'source' => '',
					'target' => '',
					'reason' => '',
					'UploadSourceType' => 'File',
					'UploadFile' => '',
				],
				false,
			],
		];
	}

	/**
	 * @covers ::onSubmit
	 */
	public function testOnSubmitDuplicate() {
		$data = [
			'source' => 'http://example.com',
			'target' => 'wikidb',
			'reason' => 'Test reason',
			'UploadSourceType' => 'File',
			'UploadFile' => __DIR__ . '/testfile.xml',
		];

		// Create a test file
		file_put_contents( __DIR__ . '/testfile.xml', '<test>content</test>' );

		$context = new DerivativeContext( $this->specialRequestImport->getContext() );
		$user = $this->getTestUser()->getUser();

		$context->setUser( $user );

		$request = new FauxRequest(
			[
				'wpEditToken' => $user->getEditToken(),
			],
			true
		);

		$request->setUpload( 'wpUploadFile', [
			'name' => basename( $data['UploadFile'] ),
			'type' => 'application/xml',
			'tmp_name' => $data['UploadFile'],
			'error' => UPLOAD_ERR_OK,
			'size' => filesize( $data['UploadFile'] ),
		] );

		$specialRequestImport = TestingAccessWrapper::newFromObject( $this->specialRequestImport );
		$specialRequestImport->setContext( $context );

		// First submission should succeed
		$status = $specialRequestImport->onSubmit( $data );
		$this->assertInstanceOf( Status::class, $status );
		$this->assertStatusGood( $status );

		// Second identical submission should fail
		$status = $specialRequestImport->onSubmit( $data );
		$this->assertInstanceOf( Status::class, $status );
		$this->assertStatusError( 'importdump-duplicate-request', $status );
	}

	/**
	 * @dataProvider isValidDatabaseDataProvider
	 * @covers ::isValidDatabase
	 */
	public function testIsValidDatabase( ?string $target, $expected ) {
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
	public function testIsValidReason( ?string $reason, $expected ) {
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
			'invalid reason' => [ '', 'htmlform-required' ],
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
}
