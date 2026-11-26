<?php

declare( strict_types = 1 );

namespace Miraheze\ImportDump\Tests\Upload;

use Generator;
use MediaWiki\Config\ServiceOptions;
use MediaWiki\MainConfigNames;
use MediaWiki\Status\Status;
use MediaWikiIntegrationTestCase;
use Miraheze\ImportDump\ConfigNames;
use Miraheze\ImportDump\Upload\ImageProcessor;
use Miraheze\ImportDump\Upload\ImageResult;
use Miraheze\ImportDump\Upload\SkippedFile;
use Wikimedia\FileBackend\FSFile\TempFSFileFactory;
use Wikimedia\Message\ScalarParam;
use function array_column;
use function array_keys;
use function array_map;
use function base64_decode;
use function file_get_contents;
use function file_put_contents;
use function ksort;
use function str_repeat;
use function strlen;

/**
 * @group ImportDump
 * @group medium
 * @covers \Miraheze\ImportDump\Upload\ImageProcessor
 * @covers \Miraheze\ImportDump\Upload\ImageResult
 */
class ImageProcessorTest extends MediaWikiIntegrationTestCase {

	use ArchiveFixtures;

	private const int DEFAULT_SIZE = 1048576;

	private const int DEFAULT_SKIPPED = 50;

	private const string GIF = 'R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7';

	private const string PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR4' .
		'2mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

	private function newProcessor( int $maxSize, int $maxSkipped ): ImageProcessor {
		$services = $this->getServiceContainer();
		return new ImageProcessor(
			$this->newReader( [] ),
			$services->getMimeAnalyzer(),
			new ServiceOptions( ImageProcessor::CONSTRUCTOR_OPTIONS, [
				ConfigNames::MaxArchiveEntries => 100,
				ConfigNames::MaxExtractedSize => $maxSize,
				ConfigNames::MaxSkippedImages => $maxSkipped,
				MainConfigNames::FileExtensions => [ 'png', 'jpg', 'gif', 'svg' ],
				MainConfigNames::ProhibitedFileExtensions => [ 'jpg' ],
			] ),
			new TempFSFileFactory( $this->getNewTempDirectory() ),
			$services->getTitleFactory(),
			$services->getUploadVerification()
		);
	}

	private function process( string $path, int $maxSize ): Status {
		return $this->newProcessor( $maxSize, self::DEFAULT_SKIPPED )->process( $path, null, null );
	}

	private function processWithLimit( string $path, int $maxSkipped ): Status {
		return $this->newProcessor( self::DEFAULT_SIZE, $maxSkipped )->process( $path, null, null );
	}

	private static function gif(): string {
		return (string)base64_decode( self::GIF );
	}

	private static function png(): string {
		return (string)base64_decode( self::PNG );
	}

	public function testExtractsImages(): void {
		$status = $this->process( $this->newZip( [
			'folder/a.png' => self::png(),
			'B.PNG' => self::png(),
			'folder/' => '',
		] ), maxSize: self::DEFAULT_SIZE );

		$this->assertStatusGood( $status );
		$result = $status->getValue();
		'@phan-var ImageResult $result';

		$this->assertSame( [ 'a.png', 'B.PNG' ], array_keys( $result->files ) );
		$this->assertSame( 2, $result->count() );
		$this->assertSame( 2 * strlen( self::png() ), $result->size );
		$this->assertSame( self::png(), file_get_contents( $result->files['a.png'] ) );
	}

	public function testExtractsEveryImageOfATar(): void {
		$entries = [];
		for ( $number = 1; $number <= 5; $number++ ) {
			$entries["image$number.png"] = self::png();
		}

		$status = $this->process( $this->newTar( $entries ), maxSize: self::DEFAULT_SIZE );

		$this->assertStatusGood( $status );
		$result = $status->getValue();
		'@phan-var ImageResult $result';

		$this->assertSame( array_keys( $entries ), array_keys( $result->files ) );
		$this->assertSame( 5 * strlen( self::png() ), $result->size );
	}

	/** @dataProvider provideRejected */
	public function testRejects( callable $build, string $messageKey ): void {
		$status = $this->process( $build( $this ), maxSize: self::DEFAULT_SIZE );
		$this->assertStatusError( $messageKey, $status );
	}

	public static function provideRejected(): Generator {
		yield 'not an archive' => [
			static fn ( self $test ): string => $test->newFile( self::png() ),
			'requestimport-images-not-archive',
		];

		yield 'no files' => [
			static fn ( self $test ): string => $test->newZip( [ 'folder/' => '' ] ),
			'requestimport-images-empty',
		];

		yield 'only an extension that is not allowed' => [
			static fn ( self $test ): string => $test->newZip( [ 'run.exe' => 'MZ' ] ),
			'requestimport-images-too-many-skipped',
		];

		yield 'only an extension that is prohibited' => [
			static fn ( self $test ): string => $test->newZip( [ 'a.jpg' => 'x' ] ),
			'requestimport-images-too-many-skipped',
		];

		yield 'only a file without an extension' => [
			static fn ( self $test ): string => $test->newZip( [ 'a' => 'x' ] ),
			'requestimport-images-too-many-skipped',
		];

		yield 'backslash in the name' => [
			static fn ( self $test ): string => $test->newZip( [ 'dir\\a.png' => self::png() ] ),
			'requestimport-archive-unsafe',
		];

		yield 'path that leaves the folder' => [
			static fn ( self $test ): string => $test->newZip( [ '../a.png' => self::png() ] ),
			'requestimport-archive-unsafe',
		];

		yield 'only a colon in the name' => [
			static fn ( self $test ): string => $test->newZip( [ 'File:a.png' => self::png() ] ),
			'requestimport-images-too-many-skipped',
		];

		yield 'only a name that is too long' => [
			static fn ( self $test ): string => $test->newZip( [ str_repeat( 'a', 250 ) . '.png' => self::png() ] ),
			'requestimport-images-too-many-skipped',
		];

		yield 'only hidden files' => [
			static fn ( self $test ): string => $test->newZip( [ '.hidden.png' => self::png() ] ),
			'requestimport-images-empty',
		];

		yield 'only a script hidden in an image' => [
			static fn ( self $test ): string => $test->newZip( [
				'a.png' => self::png() . '<script>alert(1)</script>',
			] ),
			'requestimport-images-too-many-skipped',
		];

		yield 'only contents that do not match the extension' => [
			static fn ( self $test ): string => $test->newZip( [ 'a.png' => 'This is plain text.' ] ),
			'requestimport-images-too-many-skipped',
		];
	}

	public function testSkipsHiddenFilesAndKeepsTheRest(): void {
		$status = $this->process( $this->newZip( [
			'.DS_Store' => 'x',
			'__MACOSX/._a.png' => 'x',
			'folder/._a.png' => 'x',
			'a.png' => self::png(),
		] ), maxSize: self::DEFAULT_SIZE );

		$this->assertStatusGood( $status );
		$result = $status->getValue();
		'@phan-var ImageResult $result';
		$this->assertSame( [ 'a.png' ], array_keys( $result->files ) );
	}

	public function testNamesEveryFileWhenTooManyAreSkipped(): void {
		$status = $this->process( $this->newZip( [
			'good.png' => self::png(),
			'one.png' => 'plain text',
			'two.png' => 'more text',
		] ), maxSize: self::DEFAULT_SIZE );

		$this->assertStatusError( 'requestimport-images-too-many-skipped', $status );
		$this->assertCount( 3, $status->getMessages() );
		$this->assertSame( 'requestimport-images-file-rejected', $status->getMessages()[1]->getKey() );

		$first = $status->getMessages()[1]->getParams()[0];
		$second = $status->getMessages()[2]->getParams()[0];
		'@phan-var ScalarParam $first';
		'@phan-var ScalarParam $second';

		$this->assertSame( 'one.png', $first->getValue() );
		$this->assertSame( 'two.png', $second->getValue() );
	}

	public function testSkipsAFileThatCannotBeImportedAndKeepsTheRest(): void {
		$status = $this->process( $this->newZip( [
			'one.png' => self::png(),
			'two.png' => self::png(),
			'bad.png' => 'This is plain text.',
		] ), maxSize: self::DEFAULT_SIZE );

		$this->assertStatusGood( $status );
		$result = $status->getValue();
		'@phan-var ImageResult $result';

		$this->assertSame( [ 'one.png', 'two.png' ], array_keys( $result->files ) );
		$this->assertCount( 1, $result->skipped );
		$this->assertSame( 'bad.png', $result->skipped[0]->name );
		$this->assertNotSame( '', $result->skipped[0]->reason );
	}

	public function testSkipsFilesThatCannotBeNamedOrUploaded(): void {
		$status = $this->process( $this->newZip( [
			'a.png' => self::png(),
			'b.png' => self::png(),
			'c.png' => self::png(),
			'd.png' => self::png(),
			'run.exe' => 'MZ',
			'noextension' => 'x',
			'File:colon.png' => self::png(),
		] ), maxSize: self::DEFAULT_SIZE );

		$this->assertStatusGood( $status );
		$result = $status->getValue();
		'@phan-var ImageResult $result';

		$this->assertSame( [ 'a.png', 'b.png', 'c.png', 'd.png' ], array_keys( $result->files ) );

		// Names are rejected before extraction and the content of the rest is checked afterwards.
		$reasons = array_column( array_map(
			static fn ( SkippedFile $file ): array => [ $file->name, $file->reason ],
			$result->skipped
		), 1, 0 );

		ksort( $reasons );
		$this->assertSame(
			[
				'File:colon.png' => 'requestimport-images-skip-name',
				'noextension' => 'requestimport-images-skip-no-extension',
				'run.exe' => 'requestimport-images-skip-extension',
			],
			$reasons
		);
	}

	public function testNamesFilesWithoutAnExtensionAndKeepsOtherNames(): void {
		$status = $this->process( $this->newZip( [
			'Site-background' => self::png(),
			'Disguised.png' => self::gif(),
			'plain.png' => self::png(),
		] ), maxSize: self::DEFAULT_SIZE );

		$this->assertStatusGood( $status );
		$result = $status->getValue();
		'@phan-var ImageResult $result';

		$this->assertSame( [ 'Site-background.png', 'Disguised.png', 'plain.png' ], array_keys( $result->files ) );
		$this->assertSame( [], $result->skipped );
		$this->assertSame( [ 'Site-background' => 'Site-background.png' ], $result->renamed );
	}

	public function testHoldsFilesThatOnlyLackAnAcceptedExtension(): void {
		$zip = $this->newZip( [ 'a.png' => self::png(), 'b.gif' => self::gif(), 'c.jpg' => self::gif() ] );
		$processor = $this->newProcessor( self::DEFAULT_SIZE, maxSkipped: 100 );

		$status = $processor->process( $zip, null, [ 'png' ] );
		$this->assertStatusGood( $status );
		$result = $status->getValue();
		'@phan-var ImageResult $result';

		$this->assertSame( [ 'a.png' ], array_keys( $result->files ) );
		$this->assertSame( [ 'b.gif' ], array_keys( $result->held ) );
		$this->assertSame( self::gif(), file_get_contents( $result->held['b.gif'] ) );
		$this->assertCount( 2, $result->skipped );
	}

	public function testCheckHeldAcceptsOnlyWhatIsAllowedNow(): void {
		$processor = $this->newProcessor( self::DEFAULT_SIZE, maxSkipped: 100 );
		$directory = $this->getNewTempDirectory();
		$paths = [];
		foreach ( [ 'a.gif', 'b.png', 'c.jpg', 'd.svg' ] as $name ) {
			$paths[$name] = "$directory/$name";
			file_put_contents( $paths[$name], self::gif() );
		}

		$checked = $processor->checkHeld( $paths, [ 'gif', 'jpg', 'png' ] );

		$this->assertSame( [ 'a.gif' ], array_keys( $checked['accepted'] ) );
		$this->assertSame( 'requestimport-images-skip-extension', $checked['rejected']['c.jpg']->reason );
		$this->assertSame( 'requestimport-images-skip-extension', $checked['rejected']['d.svg']->reason );
		$this->assertArrayHasKey( 'b.png', $checked['rejected'] );
		$this->assertNotSame( 'requestimport-images-skip-extension', $checked['rejected']['b.png']->reason );
	}

	public function testUsesTheExtensionsOfTheTarget(): void {
		$zip = $this->newZip( [ 'a.png' => self::png(), 'b.png' => self::gif(), 'c.gif' => self::gif() ] );
		$processor = $this->newProcessor( self::DEFAULT_SIZE, maxSkipped: 100 );

		$status = $processor->process( $zip, null, [ 'PNG' ] );
		$this->assertStatusGood( $status );
		$result = $status->getValue();
		'@phan-var ImageResult $result';
		$this->assertSame( [ 'a.png' ], array_keys( $result->files ) );

		$reasons = array_column( array_map(
			static fn ( SkippedFile $file ): array => [ $file->name, $file->reason ],
			$result->skipped
		), 1, 0 );

		ksort( $reasons );
		$this->assertSame(
			[ 'b.png' => 'requestimport-images-skip-type', 'c.gif' => 'requestimport-images-skip-extension' ],
			$reasons
		);
	}

	public function testSkipsMarkupThatIsNamedAsAnotherType(): void {
		$status = $this->process( $this->newZip( [
			'plain.png' => self::png(),
			'vector.png' => '<svg xmlns="http://www.w3.org/2000/svg" width="1" height="1"></svg>',
		] ), maxSize: self::DEFAULT_SIZE );

		$this->assertStatusGood( $status );
		$result = $status->getValue();
		'@phan-var ImageResult $result';

		$this->assertSame( [ 'plain.png' ], array_keys( $result->files ) );
		$this->assertCount( 1, $result->skipped );
		$this->assertSame( 'vector.png', $result->skipped[0]->name );
		$this->assertSame( 'filetype-mime-mismatch', $result->skipped[0]->reason );
	}

	public function testSkipsAnUnnamedFileWhoseNewNameIsTaken(): void {
		$status = $this->process( $this->newZip( [
			'logo' => self::png(),
			'logo.png' => self::png(),
		] ), maxSize: self::DEFAULT_SIZE );

		$this->assertStatusGood( $status );
		$result = $status->getValue();
		'@phan-var ImageResult $result';

		$this->assertSame( [ 'logo.png' ], array_keys( $result->files ) );
		$this->assertSame( [], $result->renamed );
		$this->assertSame(
			[ 'logo' => 'requestimport-images-skip-duplicate' ],
			array_column( array_map(
				static fn ( SkippedFile $file ): array => [ $file->name, $file->reason ],
				$result->skipped
			), 1, 0 )
		);
	}

	public function testSkipsALaterFileWithTheSameTitle(): void {
		$status = $this->process( $this->newZip( [
			'one/a.png' => self::png(),
			'two/A.png' => self::png(),
			'b.png' => self::png(),
		] ), maxSize: self::DEFAULT_SIZE );

		$this->assertStatusGood( $status );
		$result = $status->getValue();
		'@phan-var ImageResult $result';

		$this->assertSame( [ 'a.png', 'b.png' ], array_keys( $result->files ) );
		$this->assertCount( 1, $result->skipped );
		$this->assertSame( 'A.png', $result->skipped[0]->name );
		$this->assertSame( 'requestimport-images-skip-duplicate', $result->skipped[0]->reason );
	}

	public function testNothingIsSkippedWhenNoneAreAllowed(): void {
		$zip = $this->newZip( [ 'good.png' => self::png(), 'bad.png' => 'This is plain text.' ] );

		$this->assertStatusError( 'requestimport-images-too-many-skipped', $this->processWithLimit( $zip, 0 ) );
		$this->assertStatusGood( $this->processWithLimit( $zip, 50 ) );
	}

	public function testNothingSkippedForAGoodArchive(): void {
		$status = $this->process( $this->newZip( [ 'a.png' => self::png() ] ), maxSize: self::DEFAULT_SIZE );

		$this->assertStatusGood( $status );
		$result = $status->getValue();
		'@phan-var ImageResult $result';
		$this->assertSame( [], $result->skipped );
	}

	public function testRejectsTooMuchData(): void {
		$status = $this->process( $this->newZip( [ 'a.png' => self::png() ] ), maxSize: 10 );
		$this->assertStatusError( 'requestimport-archive-too-large', $status );
	}
}
