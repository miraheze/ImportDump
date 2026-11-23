<?php

declare( strict_types = 1 );

namespace Miraheze\ImportDump\Tests\Upload;

use Generator;
use InvalidArgumentException;
use MediaWiki\MainConfigNames;
use MediaWiki\Upload\UploadBase;
use MediaWiki\Upload\UploadVerification;
use MediaWikiIntegrationTestCase;
use Miraheze\ImportDump\ConfigNames;
use Miraheze\ImportDump\Upload\RequestImportUploadFromChunks;
use Wikimedia\TestingAccessWrapper;
use function file_exists;
use function file_put_contents;
use function filesize;
use function gzencode;
use function unlink;
use const PHP_INT_MAX;

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
		$this->assertSame( 'xml', $upload->getType() );
	}

	/**
	 * @covers ::__construct
	 */
	public function testConstructorImages(): void {
		$this->assertSame( 'images', $this->newUpload( type: 'images' )->getType() );
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
		$this->assertContains( 'images', RequestImportUploadFromChunks::getTypeNames() );
	}

	/**
	 * @covers ::getMaxUploadSize
	 */
	public function testGetMaxUploadSizeUnlimitedByDefault(): void {
		$this->overrideConfigValue( MainConfigNames::MaxUploadSize, 10 );

		$this->assertSame( PHP_INT_MAX, RequestImportUploadFromChunks::getMaxUploadSize() );
		$this->assertSame( PHP_INT_MAX, $this->newUpload( type: 'xml' )->getMaxUploadSize() );
	}

	/**
	 * @covers ::getMaxUploadSize
	 */
	public function testGetMaxUploadSizeConfigured(): void {
		$this->overrideConfigValue( ConfigNames::MaxUploadSize, 1234 );

		$this->assertSame( 1234, RequestImportUploadFromChunks::getMaxUploadSize() );
		$this->assertSame( 1234, $this->newUpload( type: 'xml' )->getMaxUploadSize() );
	}

	/**
	 * @covers ::verifyUpload
	 */
	public function testVerifyUploadIgnoresCoreMaxUploadSize(): void {
		$this->overrideConfigValue( MainConfigNames::MaxUploadSize, 5 );

		$path = $this->newFile( "<?xml version=\"1.0\"?>\n<mediawiki></mediawiki>" );
		$upload = $this->newUpload( type: 'xml' );
		$upload->initializePathInfo( $upload->getStashName(), $path, filesize( $path ) );

		$this->assertSame( UploadBase::OK, $upload->verifyUpload()['status'] );
	}

	/**
	 * @covers ::getStashName
	 */
	public function testGetStashName(): void {
		$this->assertSame( 'requestimport.xml', $this->newUpload( type: 'xml' )->getStashName() );
	}

	/**
	 * @covers ::getStashName
	 */
	public function testGetStashNameForImages(): void {
		$this->assertSame( 'requestimport.archive', $this->newUpload( type: 'images' )->getStashName() );
	}

	/**
	 * @covers ::verifyUpload
	 */
	public function testVerifyUploadSetsPropsSoTheStashDoesNotReadTheFile(): void {
		$path = $this->newFile( ZipFixture::build( [ 'a.png' => 'image' ] ) );
		$upload = $this->newUpload( type: 'images' );
		$upload->initializePathInfo( $upload->getStashName(), $path, filesize( $path ) );

		$this->assertSame( UploadBase::OK, $upload->verifyUpload()['status'] );

		$props = TestingAccessWrapper::newFromObject( $upload )->mFileProps;
		$this->assertSame( filesize( $path ), $props['size'] );
		$this->assertSame( 'application/zip', $props['mime'] );
		$this->assertSame( '', $props['sha1'] );
	}

	/**
	 * @covers ::verifyUpload
	 * @dataProvider verifyImagesUploadDataProvider
	 */
	public function testVerifyUploadForImages( string $content, int $expectedStatus ): void {
		$path = $this->newFile( $content );
		$upload = $this->newUpload( type: 'images' );
		$upload->initializePathInfo( $upload->getStashName(), $path, filesize( $path ) );

		$result = $upload->verifyUpload();
		$this->assertSame( $expectedStatus, $result['status'] );

		if ( $expectedStatus === UploadBase::VERIFICATION_ERROR ) {
			$this->assertSame( 'filetype-mime-mismatch', $result['details'][0] );
			$this->assertSame( 'archive', $result['details'][1] );
		}
	}

	public static function verifyImagesUploadDataProvider(): Generator {
		yield 'zip archive' => [ ZipFixture::build( [ 'a.png' => 'image' ] ), UploadBase::OK ];
		yield 'tar archive' => [ TarFixture::build( [ 'a.png' => 'image' ] ), UploadBase::OK ];
		yield 'gzip archive' => [ gzencode( 'image' ), UploadBase::OK ];
		yield 'a dump is not an archive' => [
			"<?xml version=\"1.0\"?>\n<mediawiki></mediawiki>",
			UploadBase::VERIFICATION_ERROR,
		];
		yield 'empty file' => [ '', UploadBase::EMPTY_FILE ];
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
			$this->overrideConfigValue( ConfigNames::MaxUploadSize, $maxUploadSize );
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

		yield 'zip archive' => [
			ZipFixture::build( [ 'dump.xml' => "<?xml version=\"1.0\"?>\n<mediawiki></mediawiki>" ] ),
			null,
			UploadBase::OK,
			null,
		];

		yield 'tar archive' => [
			TarFixture::build( [ 'dump.xml' => "<?xml version=\"1.0\"?>\n<mediawiki></mediawiki>" ] ),
			null,
			UploadBase::OK,
			null,
		];

		yield 'gzip archive' => [
			gzencode( "<?xml version=\"1.0\"?>\n<mediawiki></mediawiki>" ),
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
	public function testVerifyPartialFileDoesNotScan(): void {
		$verification = $this->createMock( UploadVerification::class );
		$verification->expects( $this->never() )->method( 'detectVirus' );
		$this->setService( 'UploadVerification', $verification );

		$path = $this->newFile( '<page><title>Test</title><script>x</script></page>' );
		$upload = $this->newUpload( type: 'xml' );
		$upload->initializePathInfo( $upload->getStashName(), $path, filesize( $path ) );

		$wrapper = TestingAccessWrapper::newFromObject( $upload );
		$this->assertTrue( $wrapper->verifyPartialFile() );
	}
}
