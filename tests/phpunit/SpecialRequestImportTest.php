<?php

namespace Miraheze\ImportDump\Tests;

use MediaWiki\Context\DerivativeContext;
use MediaWiki\Message\Message;
use MediaWiki\Permissions\PermissionManager;
use MediaWiki\SpecialPage\SpecialPage;
use MediaWiki\Status\Status;
use MediaWiki\User\UserFactory;
use MediaWikiIntegrationTestCase;
use MimeAnalyzer;
use Miraheze\CreateWiki\Hooks\CreateWikiHookRunner;
use Miraheze\ImportDump\Specials\SpecialRequestImport;
use RepoGroup;
use UploadBase;
use UploadStash;
use UserNotLoggedIn;
use Wikimedia\Rdbms\IConnectionProvider;
use Wikimedia\TestingAccessWrapper;

/**
 * @coversDefaultClass \Miraheze\ImportDump\Specials\SpecialRequestImport
 * @group ImportDump
 * @group Database
 * @group Medium
 */
class SpecialRequestImportTest extends MediaWikiIntegrationTestCase {

	private SpecialRequestImport $specialRequestImport;

	protected function setUp(): void {
		parent::setUp();

		$connectionProvider = $this->createMock( IConnectionProvider::class );
		$mimeAnalyzer = $this->createMock( MimeAnalyzer::class );
		$permissionManager = $this->createMock( PermissionManager::class );
		$repoGroup = $this->createMock( RepoGroup::class );
		$userFactory = $this->createMock( UserFactory::class );
		$createWikiHookRunner = $this->createMock( CreateWikiHookRunner::class );

		$this->specialRequestImport = new SpecialRequestImport(
			$connectionProvider,
			$mimeAnalyzer,
			$permissionManager,
			$repoGroup,
			$userFactory,
			$createWikiHookRunner
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
	 * @covers ::onSubmit
	 * @dataProvider onSubmitDataProvider
	 */
	public function testOnSubmit( array $data, bool $expectedSuccess ) {
		// Create a test file
		file_put_contents( __DIR__ . '/testfile.xml', '<test>content</test>' );

		$status = $this->specialRequestImport->onSubmit( $data );
		$this->assertInstanceOf( Status::class, $status );
		if ( $expectedSuccess ) {
			$this->assertStatusGood( $status );
		} else {
			$this->assertStatusNotGood( $status );
		}
	}

	public function onSubmitDataProvider(): array {
		return [
			'valid data' => [
				[
					'source' => 'http://example.com',
					'target' => 'wikidb',
					'reason' => 'Test reason',
					'UploadSourceType' => 'File',
					'UploadFile' => __DIR__ . '/testfile.xml'
				],
				true
			],
			'invalid data' => [
				[
					'source' => '',
					'target' => '',
					'reason' => '',
					'UploadSourceType' => 'File',
					'UploadFile' => ''
				],
				false
			]
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
			'UploadFile' => __DIR__ . '/testfile.xml'
		];

		// Create a test file
		file_put_contents( __DIR__ . '/testfile.xml', '<test>content</test>' );

		// First submission should succeed
		$status = $this->specialRequestImport->onSubmit( $data );
		$this->assertInstanceOf( Status::class, $status );
		$this->assertStatusGood( $status );

		// Second identical submission should fail
		$status = $this->specialRequestImport->onSubmit( $data );
		$this->assertInstanceOf( Status::class, $status );
		$this->assertStatusError( 'importdump-duplicate-request', $status );
	}

	/**
	 * @covers ::isValidDatabase
	 * @dataProvider isValidDatabaseDataProvider
	 */
	public function testIsValidDatabase( ?string $target, $expected ) {
		$result = $this->specialRequestImport->isValidDatabase( $target );
		if ( $expected instanceof Message ) {
			$this->assertInstanceOf( Message::class, $result );
		} else {
			$this->assertSame( $expected, $result );
		}
	}

	public function isValidDatabaseDataProvider(): array {
		return [
			'valid database' => [ 'wikidb', true ],
			'invalid database' => [ 'invalidwiki', Message::class ]
		];
	}

	/**
	 * @covers ::isValidReason
	 * @dataProvider isValidReasonDataProvider
	 */
	public function testIsValidReason( ?string $reason, $expected ) {
		$result = $this->specialRequestImport->isValidReason( $reason );
		if ( $expected instanceof Message ) {
			$this->assertInstanceOf( Message::class, $result );
		} else {
			$this->assertSame( $expected, $result );
		}
	}

	public function isValidReasonDataProvider(): array {
		return [
			'valid reason' => [ 'Test reason', true ],
			'invalid reason' => [ '', Message::class ]
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
