<?php

namespace Miraheze\ImportDump\Tests\Upload;

use Generator;
use InvalidArgumentException;
use MediaWiki\MainConfigNames;
use MediaWiki\Upload\UploadBase;
use MediaWiki\Upload\UploadFromChunks;
use MediaWiki\Upload\UploadVerification;
use MediaWikiIntegrationTestCase;
use Miraheze\ImportDump\Upload\RequestImportUploadFromChunks;
use Wikimedia\TestingAccessWrapper;

/**
 * @group ImportDump
 * @group Database
 * @group medium
 * @coversDefaultClass \Miraheze\ImportDump\Upload\RequestImportUploadFromChunks
 */
class RequestImportUploadFromChunksTest extends MediaWikiIntegrationTestCase {

	/** @var string[] */
	private array $files = [];

	protected function tearDown(): void {
		foreach ( $this->files as $file ) {
			if ( file_exists( $file ) ) {
				unlink( $file );
			}
		}

		parent::tearDown();
	}

	private function newUpload( string $type ): RequestImportUploadFromChunks {
		return new RequestImportUploadFromChunks(
			$this->getTestUser()->getUser(),
			$type
		);
	}

	private function newFile( string $content ): string {
		$path = $this->getNewTempFile() . '.xml';
		file_put_contents( $path, $content );
		$this->files[] = $path;
		return $path;
	}

	/**
	 * @covers ::__construct
	 */
	public function testConstructor(): void {
		$upload = $this->newUpload( type: 'xml' );
		$this->assertInstanceOf( UploadFromChunks::class, $upload );
		$this->assertSame( 'xml', $upload->getType() );
	}

	/**
	 * @covers ::__construct
	 */
	public function testConstructorUnknownType(): void {
		$this->expectException( InvalidArgumentException::class );
		$this->newUpload( type: 'unknown' );
	}

	/**
	 * @covers ::getTypeNames
	 */
	public function testGetTypeNames(): void {
		$this->assertContains( 'xml', RequestImportUploadFromChunks::getTypeNames() );
	}

	/**
	 * @covers ::getStashName
	 */
	public function testGetStashName(): void {
		$this->assertSame( 'requestimport.xml', $this->newUpload( type: 'xml' )->getStashName() );
	}

	/**
	 * @covers ::verifyUpload
	 * @dataProvider verifyUploadDataProvider
	 */
	public function testVerifyUpload(
		string $content,
		?int $maxUploadSize,
		int $expectedStatus,
		?string $expectedMessage
	): void {
		if ( $maxUploadSize !== null ) {
			$this->overrideConfigValue( MainConfigNames::MaxUploadSize, $maxUploadSize );
		}

		$path = $this->newFile( $content );
		$upload = $this->newUpload( type: 'xml' );
		$upload->initializePathInfo( $upload->getStashName(), $path, filesize( $path ) );

		$result = $upload->verifyUpload();
		$this->assertSame( $expectedStatus, $result['status'] );

		if ( $expectedMessage !== null ) {
			$this->assertSame( $expectedMessage, $result['details'][0] );
			$this->assertSame( 'xml', $result['details'][1] );
		}
	}

	public static function verifyUploadDataProvider(): Generator {
		yield 'valid xml' => [
			"<?xml version=\"1.0\"?>\n<mediawiki></mediawiki>",
			null,
			UploadBase::OK,
			null,
		];

		yield 'empty file' => [
			'',
			null,
			UploadBase::EMPTY_FILE,
			null,
		];

		yield 'file too large' => [
			"<?xml version=\"1.0\"?>\n<mediawiki></mediawiki>",
			5,
			UploadBase::FILE_TOO_LARGE,
			null,
		];

		yield 'mime mismatch' => [
			'content',
			null,
			UploadBase::VERIFICATION_ERROR,
			'filetype-mime-mismatch',
		];
	}

	/**
	 * @covers ::verifyPartialFile
	 */
	public function testVerifyPartialFileAllowsXmlTags(): void {
		$path = $this->newFile( '<page><title>Test</title><script>x</script></page>' );
		$upload = $this->newUpload( type: 'xml' );
		$upload->initializePathInfo( $upload->getStashName(), $path, filesize( $path ) );

		$wrapper = TestingAccessWrapper::newFromObject( $upload );
		$this->assertTrue( $wrapper->verifyPartialFile() );
	}

	/**
	 * @covers ::verifyPartialFile
	 */
	public function testVerifyPartialFileDetectsVirus(): void {
		$verification = $this->createMock( UploadVerification::class );
		$verification->method( 'detectVirus' )->willReturn( 'TestVirus' );
		$this->setService( 'UploadVerification', $verification );

		$path = $this->newFile( '<page></page>' );
		$upload = $this->newUpload( type: 'xml' );
		$upload->initializePathInfo( $upload->getStashName(), $path, filesize( $path ) );

		$wrapper = TestingAccessWrapper::newFromObject( $upload );
		$this->assertSame( [ 'uploadvirus', 'TestVirus' ], $wrapper->verifyPartialFile() );
	}
}
