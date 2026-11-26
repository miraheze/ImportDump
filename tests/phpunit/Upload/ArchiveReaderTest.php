<?php

declare( strict_types = 1 );

namespace Miraheze\ImportDump\Tests\Upload;

use Generator;
use MediaWikiIntegrationTestCase;
use Miraheze\ImportDump\Upload\ArchiveEntry;
use Miraheze\ImportDump\Upload\ArchiveReader;
use Miraheze\ImportDump\Upload\ExternalBinaries;
use ZipArchive;
use function array_map;
use function bzcompress;
use function end;
use function file_get_contents;
use function filesize;
use function function_exists;
use function gzencode;
use function str_repeat;
use function strlen;
use function substr;
use const PHP_INT_MAX;

/**
 * @group ImportDump
 * @group medium
 * @covers \Miraheze\ImportDump\Upload\ArchiveReader
 * @covers \Miraheze\ImportDump\Upload\ArchiveEntry
 */
class ArchiveReaderTest extends MediaWikiIntegrationTestCase {

	use ArchiveFixtures;

	private const int MAX_SIZE = 1048576;

	private ArchiveReader $reader;

	protected function setUp(): void {
		parent::setUp();
		$this->reader = $this->newReader( [] );
	}

	public function testDetectFormat(): void {
		$this->assertSame( ArchiveReader::FORMAT_ZIP, $this->reader->detectFormat( $this->newZip( [ 'a' => 'b' ] ) ) );
		$this->assertSame(
			ArchiveReader::FORMAT_GZIP,
			$this->reader->detectFormat( $this->newFile( gzencode( 'content' ) ) )
		);

		$this->assertSame(
			ArchiveReader::FORMAT_TAR,
			$this->reader->detectFormat( $this->newTar( [ 'a' => 'b' ] ) )
		);

		$this->assertSame(
			ArchiveReader::FORMAT_BZIP2,
			$this->reader->detectFormat( $this->newFile( "BZh9\x31\x41\x59\x26\x53\x59" . 'data' ) )
		);

		$this->assertNull( $this->reader->detectFormat( $this->newFile( 'BZh0' . "\x31\x41\x59\x26\x53\x59" ) ) );
		$this->assertNull( $this->reader->detectFormat( $this->newFile( 'BZh9 but not bzip2' ) ) );
		$this->assertNull( $this->reader->detectFormat( $this->newFile( '<mediawiki/>' ) ) );
		$this->assertNull( $this->reader->detectFormat( $this->newFile( '' ) ) );
	}

	/** @dataProvider provideNames */
	public function testIsSafeName( string $name, bool $expected ): void {
		$this->assertSame( $expected, $this->reader->isSafeName( $name ) );
	}

	public static function provideNames(): Generator {
		yield 'file' => [ 'dump.xml', true ];
		yield 'file with spaces' => [ 'my dump.xml', true ];
		yield 'file in a directory' => [ 'folder/dump.xml', true ];
		yield 'directory' => [ 'folder/', true ];
		yield 'dots in a name' => [ 'dump..xml', true ];
		yield 'empty' => [ '', false ];
		yield 'only a slash' => [ '/', false ];
		yield 'absolute path' => [ '/etc/passwd', false ];
		yield 'parent directory' => [ '../dump.xml', false ];
		yield 'parent directory in the middle' => [ 'folder/../../dump.xml', false ];
		yield 'only a parent directory' => [ '..', false ];
		yield 'current directory' => [ './dump.xml', false ];
		yield 'empty part' => [ 'folder//dump.xml', false ];
		yield 'backslash' => [ 'folder\\dump.xml', false ];
		yield 'windows parent directory' => [ '..\\dump.xml', false ];
		yield 'drive letter' => [ 'C:dump.xml', false ];
		yield 'null byte' => [ "dump.xml\0.txt", false ];
		yield 'control character' => [ "dump\n.xml", false ];
		yield 'too long' => [ str_repeat( 'a', 2000 ), false ];
	}

	public function testListFilesZip(): void {
		$path = $this->newZip( [
			'folder/' => '',
			'folder/dump.xml' => 'one',
			'__MACOSX/folder/._dump.xml' => 'metadata',
			'other.xml' => 'two',
		] );

		$status = $this->reader->listFiles( $path, ArchiveReader::FORMAT_ZIP, 100, self::MAX_SIZE );
		$this->assertStatusGood( $status );

		$entries = $status->getValue();
		$this->assertCount( 2, $entries );
		$this->assertContainsOnlyInstancesOf( ArchiveEntry::class, $entries );
		$this->assertSame( 'folder/dump.xml', $entries[0]->name );
		$this->assertSame( 3, $entries[0]->size );
		$this->assertSame( 'other.xml', $entries[1]->name );
	}

	public function testListFilesGzip(): void {
		$path = $this->newFile( gzencode( 'content' ) );

		$status = $this->reader->listFiles( $path, ArchiveReader::FORMAT_GZIP, 100, self::MAX_SIZE );
		$this->assertStatusGood( $status );
		$this->assertCount( 1, $status->getValue() );
		$this->assertNull( $status->getValue()[0]->size );
		$this->assertSame( filesize( $path ), $status->getValue()[0]->compressedSize );
	}

	public function testListFilesUnsafeName(): void {
		$path = $this->newZip( [ '../dump.xml' => 'content' ] );
		$this->assertStatusError(
			'requestimport-archive-unsafe',
			$this->reader->listFiles( $path, ArchiveReader::FORMAT_ZIP, 100, self::MAX_SIZE )
		);
	}

	public function testListFilesLink(): void {
		$path = $this->newZip( [ 'dump.xml' => 'target' ] );

		$zip = new ZipArchive();
		$zip->open( $path );
		$zip->setExternalAttributesName( 'dump.xml', ZipArchive::OPSYS_UNIX, 0120777 << 16 );
		$zip->close();

		$this->assertStatusError(
			'requestimport-archive-unsafe',
			$this->reader->listFiles( $path, ArchiveReader::FORMAT_ZIP, 100, self::MAX_SIZE )
		);
	}

	public function testListFilesRegularFileWithUnixAttributes(): void {
		$path = $this->newZip( [ 'dump.xml' => 'content' ] );

		$zip = new ZipArchive();
		$zip->open( $path );
		$zip->setExternalAttributesName( 'dump.xml', ZipArchive::OPSYS_UNIX, 0100644 << 16 );
		$zip->close();

		$this->assertStatusGood( $this->reader->listFiles( $path, ArchiveReader::FORMAT_ZIP, 100, self::MAX_SIZE ) );
	}

	public function testListFilesEncrypted(): void {
		$path = $this->newZip( [ 'dump.xml' => 'content' ] );

		$zip = new ZipArchive();
		$zip->open( $path );
		if ( !$zip->setEncryptionName( 'dump.xml', ZipArchive::EM_AES_256, 'password' ) ) {
			$this->markTestSkipped( 'This build of libzip cannot create encrypted entries.' );
		}

		$zip->close();

		$this->assertStatusError(
			'requestimport-archive-unsafe',
			$this->reader->listFiles( $path, ArchiveReader::FORMAT_ZIP, 100, self::MAX_SIZE )
		);
	}

	public function testListFilesUnsupportedCompression(): void {
		$path = $this->getNewTempFile();
		$zip = new ZipArchive();
		$zip->open( $path, ZipArchive::CREATE | ZipArchive::OVERWRITE );
		$zip->addFromString( 'dump.xml', 'content' );
		$zip->setCompressionName( 'dump.xml', ZipArchive::CM_BZIP2 );
		$zip->close();

		$this->assertStatusError(
			'requestimport-archive-unsafe',
			$this->reader->listFiles( $path, ArchiveReader::FORMAT_ZIP, 10, self::MAX_SIZE )
		);
	}

	public function testListFilesInconsistentHeaders(): void {
		$contents = file_get_contents( $this->newZip( [ 'dump.xml' => str_repeat( 'a', 5000 ) ] ) );

		// The name in the local header no longer matches the one in the central directory.
		$contents[30] = 'X';

		$this->assertStatusError(
			'requestimport-archive-unreadable',
			$this->reader->listFiles( $this->newFile( $contents ), ArchiveReader::FORMAT_ZIP, 10, self::MAX_SIZE )
		);
	}

	/** @dataProvider provideInvalidGzipHeaders */
	public function testListFilesInvalidGzipHeader( string $header ): void {
		$this->assertStatusError(
			'requestimport-archive-unreadable',
			$this->reader->listFiles( $this->newFile( $header ), ArchiveReader::FORMAT_GZIP, 10, self::MAX_SIZE )
		);
	}

	public static function provideInvalidGzipHeaders(): Generator {
		yield 'cut off' => [ "\x1f\x8b\x08" ];
		yield 'not deflate' => [ "\x1f\x8b\x07\x00\x00\x00\x00\x00\x00\x03" ];
		yield 'reserved flags' => [ "\x1f\x8b\x08\xe0\x00\x00\x00\x00\x00\x03" ];
	}

	public function testListFilesTooManyEntries(): void {
		$path = $this->newZip( [ 'a' => '1', 'b' => '2', 'c' => '3' ] );
		$this->assertStatusError(
			'requestimport-archive-too-many-entries',
			$this->reader->listFiles( $path, ArchiveReader::FORMAT_ZIP, 2, self::MAX_SIZE )
		);
	}

	public function testListFilesUnreadable(): void {
		$path = $this->newFile( "PK\x03\x04 this is not a zip file" );
		$this->assertStatusError(
			'requestimport-archive-unreadable',
			$this->reader->listFiles( $path, ArchiveReader::FORMAT_ZIP, 100, self::MAX_SIZE )
		);
	}

	public function testExtractZip(): void {
		$path = $this->newZip( [ 'dump.xml' => str_repeat( 'content ', 1000 ) ] );
		$entry = $this->reader->listFiles( $path, ArchiveReader::FORMAT_ZIP, 100, self::MAX_SIZE )->getValue()[0];
		$destination = $this->getNewTempFile();

		$percentages = [];
		$status = $this->reader->extractTo(
			$path,
			ArchiveReader::FORMAT_ZIP,
			$entry,
			$destination,
			1048576,
			static function ( ?int $percent ) use ( &$percentages ): void {
				$percentages[] = $percent;
			}
		);

		$this->assertStatusGood( $status );
		$this->assertSame( str_repeat( 'content ', 1000 ), file_get_contents( $destination ) );
		'@phan-var list<?int> $percentages';
		$this->assertSame( 0, $percentages[0] );
		$this->assertSame( 100, end( $percentages ) );
	}

	public function testExtractGzip(): void {
		$path = $this->newFile( gzencode( str_repeat( 'content ', 1000 ) ) );
		$entry = $this->reader->listFiles( $path, ArchiveReader::FORMAT_GZIP, 100, self::MAX_SIZE )->getValue()[0];
		$destination = $this->getNewTempFile();

		$percentages = [];
		$status = $this->reader->extractTo(
			$path,
			ArchiveReader::FORMAT_GZIP,
			$entry,
			$destination,
			1048576,
			static function ( ?int $percent ) use ( &$percentages ): void {
				$percentages[] = $percent;
			}
		);

		$this->assertStatusGood( $status );
		$this->assertSame( str_repeat( 'content ', 1000 ), file_get_contents( $destination ) );
		// The size of a gzip file is not known before it is extracted.
		$this->assertSame( [ null ], $percentages );
	}

	public function testExtractTooLarge(): void {
		$zip = $this->newZip( [ 'dump.xml' => str_repeat( 'content ', 1000 ) ] );
		$entry = $this->reader->listFiles( $zip, ArchiveReader::FORMAT_ZIP, 100, self::MAX_SIZE )->getValue()[0];
		$this->assertStatusError(
			'requestimport-archive-too-large',
			$this->reader->extractTo( $zip, ArchiveReader::FORMAT_ZIP, $entry, $this->getNewTempFile(), 100, null )
		);

		$gzip = $this->newFile( gzencode( str_repeat( 'content ', 1000 ) ) );
		$entry = $this->reader->listFiles( $gzip, ArchiveReader::FORMAT_GZIP, 100, self::MAX_SIZE )->getValue()[0];
		$destination = $this->getNewTempFile();
		$this->assertStatusError(
			'requestimport-archive-too-large',
			$this->reader->extractTo( $gzip, ArchiveReader::FORMAT_GZIP, $entry, $destination, 100, null )
		);

		$this->assertSame( 0, filesize( $destination ) );
	}

	public function testExtractExtremeCompressionRatio(): void {
		$path = $this->newFile( gzencode( str_repeat( 'a', 4 * 1048576 ) ) );
		$entry = $this->reader->listFiles( $path, ArchiveReader::FORMAT_GZIP, 100, self::MAX_SIZE )->getValue()[0];

		$destination = $this->getNewTempFile();
		$this->assertStatusError(
			'requestimport-archive-ratio',
			$this->reader->extractTo( $path, ArchiveReader::FORMAT_GZIP, $entry, $destination, PHP_INT_MAX, null )
		);
	}

	public function testExtractEntryWithWrongSize(): void {
		$path = $this->newZip( [ 'dump.xml' => 'content' ] );
		$entry = new ArchiveEntry( 0, 'dump.xml', 1000, 10 );

		$this->assertStatusError(
			'requestimport-archive-unreadable',
			$this->reader->extractTo( $path, ArchiveReader::FORMAT_ZIP, $entry, $this->getNewTempFile(), 1048576, null )
		);
	}

	public function testListFilesTar(): void {
		$path = $this->newTar( [
			'./' => '',
			'./folder/' => '',
			'./folder/dump.xml' => 'one',
		] );

		$status = $this->reader->listFiles( $path, ArchiveReader::FORMAT_TAR, 100, self::MAX_SIZE );
		$this->assertStatusGood( $status );

		$entries = $status->getValue();
		$this->assertCount( 1, $entries );
		$this->assertSame( 'folder/dump.xml', $entries[0]->name );
		$this->assertSame( 3, $entries[0]->size );
		$this->assertSame( filesize( $path ), $entries[0]->compressedSize );
	}

	public function testListFilesTarSkipsMacMetadata(): void {
		$path = $this->newTar( [
			'dump.xml' => 'one',
			'._dump.xml' => 'metadata',
			'__MACOSX/' => '',
			'__MACOSX/._dump.xml' => 'metadata',
		] );

		$status = $this->reader->listFiles( $path, ArchiveReader::FORMAT_TAR, 100, self::MAX_SIZE );
		$this->assertStatusGood( $status );
		$this->assertCount( 1, $status->getValue() );
	}

	public function testListFilesTarWithoutEndMarker(): void {
		$path = $this->newFile( substr( TarFixture::build( [ 'dump.xml' => 'one' ] ), 0, -1024 ) );

		$status = $this->reader->listFiles( $path, ArchiveReader::FORMAT_TAR, 100, self::MAX_SIZE );
		$this->assertStatusGood( $status );
		$this->assertCount( 1, $status->getValue() );
	}

	public function testListFilesTarListsEveryFile(): void {
		$path = $this->newTar( [ 'one.xml' => 'one', 'two.xml' => 'two', 'three.xml' => 'three' ] );

		$status = $this->reader->listFiles( $path, ArchiveReader::FORMAT_TAR, 100, self::MAX_SIZE );
		$this->assertStatusGood( $status );
		$this->assertSame( [ 'one.xml', 'two.xml', 'three.xml' ], array_map(
			static fn ( ArchiveEntry $entry ): string => $entry->name,
			$status->getValue()
		) );
	}

	public function testExtractTarEntriesInOrderAndOutOfOrder(): void {
		$contents = [ 'a.png' => 'first', 'b.png' => 'second', 'c.png' => 'third' ];
		$path = $this->newTar( $contents );
		$entries = $this->reader->listFiles( $path, ArchiveReader::FORMAT_TAR, 100, self::MAX_SIZE )->getValue();

		// In order is one pass, and going back starts over. Both give the right file.
		foreach ( [ 0, 1, 2, 1, 0, 2 ] as $position ) {
			$destination = $this->getNewTempFile();
			$this->assertStatusGood( $this->reader->extractTo(
				$path,
				ArchiveReader::FORMAT_TAR,
				$entries[$position],
				$destination,
				self::MAX_SIZE,
				null
			) );

			$this->assertSame( $contents[$entries[$position]->name], file_get_contents( $destination ) );
		}

		$this->reader->close();
	}

	public function testListFilesCompressedTar(): void {
		$tar = TarFixture::build( [ 'dump.xml' => 'one' ] );

		$status = $this->reader->listFiles(
			$this->newFile( gzencode( $tar ) ),
			ArchiveReader::FORMAT_GZIP,
			100,
			self::MAX_SIZE
		);

		$this->assertStatusGood( $status );
		$this->assertSame( 'dump.xml', $status->getValue()[0]->name );
		$this->assertSame( 3, $status->getValue()[0]->size );
	}

	public function testListFilesTarEntryTypes(): void {
		$entries = [
			'hard link' => TarFixture::link( 'dump.xml', 'other', '1' ),
			'symbolic link' => TarFixture::link( 'dump.xml', 'other', '2' ),
			'character device' => TarFixture::link( 'dump.xml', '', '3' ),
			'block device' => TarFixture::link( 'dump.xml', '', '4' ),
			'fifo' => TarFixture::link( 'dump.xml', '', '6' ),
			'contiguous file' => TarFixture::entry( 'dump.xml', 'content', '7' ),
			'GNU sparse file' => TarFixture::entry( 'dump.xml', 'content', 'S' ),
			'unknown type' => TarFixture::entry( 'dump.xml', 'content', 'Q' ),
		];

		foreach ( $entries as $label => $entry ) {
			$path = $this->newFile( TarFixture::finish( TarFixture::entry( 'ok.xml', 'ok', '0' ) . $entry ) );
			$this->assertStatusError(
				'requestimport-archive-unsafe',
				$this->reader->listFiles( $path, ArchiveReader::FORMAT_TAR, 100, self::MAX_SIZE ),
				$label
			);
		}
	}

	/** @dataProvider provideUnsafeTarNames */
	public function testListFilesTarUnsafeName( string $name ): void {
		$path = $this->newTar( [ $name => 'content' ] );
		$this->assertStatusError(
			'requestimport-archive-unsafe',
			$this->reader->listFiles( $path, ArchiveReader::FORMAT_TAR, 100, self::MAX_SIZE )
		);
	}

	public static function provideUnsafeTarNames(): Generator {
		yield 'parent directory' => [ '../dump.xml' ];
		yield 'parent directory in the middle' => [ 'folder/../../dump.xml' ];
		yield 'absolute path' => [ '/etc/passwd' ];
		yield 'backslash' => [ 'folder\\dump.xml' ];
		yield 'empty part' => [ 'folder//dump.xml' ];
		yield 'only a dot' => [ '.' ];
	}

	public function testListFilesTarUnsafeDirectoryName(): void {
		$path = $this->newTar( [ '../folder/' => '', 'dump.xml' => 'content' ] );
		$this->assertStatusError(
			'requestimport-archive-unsafe',
			$this->reader->listFiles( $path, ArchiveReader::FORMAT_TAR, 100, self::MAX_SIZE )
		);
	}

	public function testListFilesTarLongNames(): void {
		$name = str_repeat( 'a', 150 ) . '/dump.xml';

		$gnu = $this->newFile(
			TarFixture::finish( TarFixture::longName( $name ) . TarFixture::entry( 'x', 'one', '0' ) )
		);

		$status = $this->reader->listFiles( $gnu, ArchiveReader::FORMAT_TAR, 100, self::MAX_SIZE );
		$this->assertStatusGood( $status );
		$this->assertSame( $name, $status->getValue()[0]->name );

		$pax = $this->newFile(
			TarFixture::finish( TarFixture::pax( [ 'path' => $name ] ) . TarFixture::entry( 'x', 'one', '0' ) )
		);

		$status = $this->reader->listFiles( $pax, ArchiveReader::FORMAT_TAR, 100, self::MAX_SIZE );
		$this->assertStatusGood( $status );
		$this->assertSame( $name, $status->getValue()[0]->name );
	}

	public function testListFilesTarLongNameIsChecked(): void {
		$path = $this->newFile(
			TarFixture::finish( TarFixture::longName( '../dump.xml' ) . TarFixture::entry( 'x', 'one', '0' ) )
		);

		$this->assertStatusError(
			'requestimport-archive-unsafe',
			$this->reader->listFiles( $path, ArchiveReader::FORMAT_TAR, 100, self::MAX_SIZE )
		);
	}

	public function testListFilesTarSparsePax(): void {
		$path = $this->newFile( TarFixture::finish(
			TarFixture::pax( [ 'GNU.sparse.major' => '1' ] ) . TarFixture::entry( 'dump.xml', 'one', '0' )
		) );

		$this->assertStatusError(
			'requestimport-archive-unsafe',
			$this->reader->listFiles( $path, ArchiveReader::FORMAT_TAR, 100, self::MAX_SIZE )
		);
	}

	public function testListFilesTarBinarySize(): void {
		$path = $this->newFile( TarFixture::finish( TarFixture::entryWithBinarySize( 'dump.xml', 'content' ) ) );

		$status = $this->reader->listFiles( $path, ArchiveReader::FORMAT_TAR, 100, self::MAX_SIZE );
		$this->assertStatusGood( $status );
		$this->assertSame( 7, $status->getValue()[0]->size );
	}

	public function testListFilesTarTooManyEntries(): void {
		$path = $this->newTar( [ 'a/' => '', 'b/' => '', 'dump.xml' => 'content' ] );
		$this->assertStatusError(
			'requestimport-archive-too-many-entries',
			$this->reader->listFiles( $path, ArchiveReader::FORMAT_TAR, 2, self::MAX_SIZE )
		);
	}

	public function testListFilesTarTooLarge(): void {
		$path = $this->newTar( [ 'dump.xml' => str_repeat( 'a', 1000 ) ] );
		$this->assertStatusError(
			'requestimport-archive-too-large',
			$this->reader->listFiles( $path, ArchiveReader::FORMAT_TAR, 100, 999 )
		);
	}

	public function testListFilesTarTooLargeTogether(): void {
		$path = $this->newTar( [ 'dump.xml' => str_repeat( 'a', 600 ), '._dump.xml' => str_repeat( 'a', 600 ) ] );
		$this->assertStatusError(
			'requestimport-archive-too-large',
			$this->reader->listFiles( $path, ArchiveReader::FORMAT_TAR, 100, 1000 )
		);
	}

	public function testListFilesTarExtremeCompressionRatio(): void {
		$tar = TarFixture::build( [ 'dump.xml' => str_repeat( 'a', 4 * 1048576 ) ] );
		$path = $this->newFile( gzencode( $tar ) );

		$this->assertStatusError(
			'requestimport-archive-ratio',
			$this->reader->listFiles( $path, ArchiveReader::FORMAT_GZIP, 100, PHP_INT_MAX )
		);
	}

	public function testListFilesTarDamaged(): void {
		$tar = TarFixture::build( [ 'dump.xml' => 'content' ] );

		$badChecksum = $tar;
		$badChecksum[3] = 'Z';

		$cases = [
			'wrong checksum' => $badChecksum,
			'cut off in the data' => substr( $tar, 0, 520 ),
			'cut off in the header' => substr( $tar, 0, 300 ),
			'cut off in the second header' => substr( TarFixture::build( [ 'a' => 'b', 'c' => 'd' ] ), 0, 1300 ),
		];

		foreach ( $cases as $label => $bytes ) {
			$path = $this->newFile( $bytes );
			$format = $this->reader->detectFormat( $path );
			if ( $format !== ArchiveReader::FORMAT_TAR ) {
				// The header was cut off so far that the file does not look like a TAR anymore.
				$this->assertNull( $format, $label );
				continue;
			}

			$status = $this->reader->listFiles( $path, $format, 100, self::MAX_SIZE );
			$this->assertStatusError( 'requestimport-archive-unreadable', $status, $label );
		}
	}

	public function testListFilesTarWithOnlyPadding(): void {
		$path = $this->newFile( TarFixture::finish( '' ) );
		$this->assertNull( $this->reader->detectFormat( $path ) );
	}

	public function testExtractTar(): void {
		$path = $this->newTar( [
			'a.xml' => 'first',
			'folder/' => '',
			'folder/dump.xml' => str_repeat( 'content ', 1000 ),
		] );
		$status = $this->reader->listFiles( $path, ArchiveReader::FORMAT_TAR, 100, self::MAX_SIZE );
		$entry = $status->getValue()[1];
		$this->assertSame( 'folder/dump.xml', $entry->name );

		$destination = $this->getNewTempFile();
		$percentages = [];
		$status = $this->reader->extractTo(
			$path,
			ArchiveReader::FORMAT_TAR,
			$entry,
			$destination,
			self::MAX_SIZE,
			static function ( ?int $percent ) use ( &$percentages ): void {
				$percentages[] = $percent;
			}
		);

		$this->assertStatusGood( $status );
		$this->assertSame( str_repeat( 'content ', 1000 ), file_get_contents( $destination ) );
		'@phan-var list<?int> $percentages';
		$this->assertSame( 0, $percentages[0] );
		$this->assertSame( 100, end( $percentages ) );
	}

	public function testExtractCompressedTar(): void {
		$tar = TarFixture::build( [ 'dump.xml' => str_repeat( 'content ', 1000 ) ] );
		$path = $this->newFile( gzencode( $tar ) );
		$entry = $this->reader->listFiles( $path, ArchiveReader::FORMAT_GZIP, 100, self::MAX_SIZE )->getValue()[0];
		$destination = $this->getNewTempFile();

		$status = $this->reader->extractTo(
			$path,
			ArchiveReader::FORMAT_GZIP,
			$entry,
			$destination,
			self::MAX_SIZE,
			null
		);

		$this->assertStatusGood( $status );
		$this->assertSame( str_repeat( 'content ', 1000 ), file_get_contents( $destination ) );
	}

	public function testExtractTarTooLarge(): void {
		$path = $this->newTar( [ 'dump.xml' => str_repeat( 'a', 1000 ) ] );
		$entry = $this->reader->listFiles( $path, ArchiveReader::FORMAT_TAR, 100, self::MAX_SIZE )->getValue()[0];

		$this->assertStatusError(
			'requestimport-archive-too-large',
			$this->reader->extractTo( $path, ArchiveReader::FORMAT_TAR, $entry, $this->getNewTempFile(), 999, null )
		);
	}

	public function testExtractTarEntryThatIsNotThere(): void {
		$path = $this->newTar( [ 'dump.xml' => 'content' ] );

		$this->assertStatusError(
			'requestimport-archive-unreadable',
			$this->reader->extractTo(
				$path,
				ArchiveReader::FORMAT_TAR,
				new ArchiveEntry( 5, 'dump.xml', 7, 10 ),
				$this->getNewTempFile(),
				self::MAX_SIZE,
				null
			)
		);

		$this->assertStatusError(
			'requestimport-archive-unreadable',
			$this->reader->extractTo(
				$path,
				ArchiveReader::FORMAT_TAR,
				new ArchiveEntry( 0, 'other.xml', 7, 10 ),
				$this->getNewTempFile(),
				self::MAX_SIZE,
				null
			)
		);
	}

	public function testExtractTarThatWasCutOff(): void {
		$path = $this->newFile( substr( TarFixture::build( [ 'dump.xml' => str_repeat( 'a', 2000 ) ] ), 0, 1000 ) );

		$this->assertStatusError(
			'requestimport-archive-unreadable',
			$this->reader->extractTo(
				$path,
				ArchiveReader::FORMAT_TAR,
				new ArchiveEntry( 0, 'dump.xml', 2000, filesize( $path ) ),
				$this->getNewTempFile(),
				self::MAX_SIZE,
				null
			)
		);
	}

	public function testBzip2WithoutTheExtension(): void {
		if ( function_exists( 'bzopen' ) ) {
			$this->markTestSkipped( 'The bz2 extension is installed.' );
		}

		$path = $this->newFile( "BZh9\x31\x41\x59\x26\x53\x59" . 'data' );
		$this->assertStatusError(
			'requestimport-archive-bzip2-unavailable',
			$this->reader->listFiles( $path, ArchiveReader::FORMAT_BZIP2, 100, self::MAX_SIZE )
		);
	}

	public function testBzip2(): void {
		if ( !function_exists( 'bzcompress' ) ) {
			$this->markTestSkipped( 'The bz2 extension is not installed.' );
		}

		$path = $this->newFile( bzcompress( str_repeat( 'content ', 1000 ) ) );
		$this->assertSame( ArchiveReader::FORMAT_BZIP2, $this->reader->detectFormat( $path ) );

		$entry = $this->reader->listFiles( $path, ArchiveReader::FORMAT_BZIP2, 100, self::MAX_SIZE )->getValue()[0];
		$this->assertSame( '', $entry->name );
		$this->assertNull( $entry->size );

		$destination = $this->getNewTempFile();
		$status = $this->reader->extractTo(
			$path,
			ArchiveReader::FORMAT_BZIP2,
			$entry,
			$destination,
			self::MAX_SIZE,
			null
		);

		$this->assertStatusGood( $status );
		$this->assertSame( str_repeat( 'content ', 1000 ), file_get_contents( $destination ) );
	}

	public function testCompressedTarWithBzip2(): void {
		if ( !function_exists( 'bzcompress' ) ) {
			$this->markTestSkipped( 'The bz2 extension is not installed.' );
		}

		$path = $this->newFile( bzcompress( TarFixture::build( [ 'dump.xml' => 'content' ] ) ) );
		$status = $this->reader->listFiles( $path, ArchiveReader::FORMAT_BZIP2, 100, self::MAX_SIZE );
		$this->assertStatusGood( $status );
		$this->assertSame( 'dump.xml', $status->getValue()[0]->name );

		$destination = $this->getNewTempFile();
		$this->assertStatusGood( $this->reader->extractTo(
			$path,
			ArchiveReader::FORMAT_BZIP2,
			$status->getValue()[0],
			$destination,
			self::MAX_SIZE,
			null
		) );

		$this->assertSame( 'content', file_get_contents( $destination ) );
	}

	public function testBzip2ExtremeCompressionRatio(): void {
		if ( !function_exists( 'bzcompress' ) ) {
			$this->markTestSkipped( 'The bz2 extension is not installed.' );
		}

		$path = $this->newFile( bzcompress( str_repeat( 'a', 8 * 1048576 ) ) );
		$entry = $this->reader->listFiles( $path, ArchiveReader::FORMAT_BZIP2, 100, self::MAX_SIZE )->getValue()[0];

		$this->assertStatusError(
			'requestimport-archive-ratio',
			$this->reader->extractTo(
				$path,
				ArchiveReader::FORMAT_BZIP2,
				$entry,
				$this->getNewTempFile(),
				PHP_INT_MAX,
				null
			)
		);
	}

	public function testDetectFormatOfProgramFormats(): void {
		$xz = $this->newFile( ExternalFixtures::decode( ExternalFixtures::XZ_XML ) );
		$sevenZip = $this->newFile( ExternalFixtures::decode( ExternalFixtures::SEVEN_ZIP_ONE ) );

		$this->assertNull( $this->reader->detectFormat( $xz ) );
		$this->assertNull( $this->reader->detectFormat( $sevenZip ) );

		$onlyXz = $this->newReader( [ ExternalBinaries::XZ => '/not/installed/xz' ] );
		$this->assertSame( ArchiveReader::FORMAT_XZ, $onlyXz->detectFormat( $xz ) );
		$this->assertNull( $onlyXz->detectFormat( $sevenZip ) );

		$onlySevenZip = $this->newReader( [ ExternalBinaries::SEVEN_ZIP => '/not/installed/7z' ] );
		$this->assertNull( $onlySevenZip->detectFormat( $xz ) );
		$this->assertSame( ArchiveReader::FORMAT_SEVEN_ZIP, $onlySevenZip->detectFormat( $sevenZip ) );

		$empty = $this->newReader( [ ExternalBinaries::XZ => '' ] );
		$this->assertNull( $empty->detectFormat( $xz ) );
	}

	public function testExternalMimeTypes(): void {
		$this->assertSame( [], $this->reader->getExternalMimeTypes() );

		$reader = $this->newReader( [
			ExternalBinaries::XZ => '/not/installed/xz',
			ExternalBinaries::SEVEN_ZIP => '/not/installed/7z',
		] );

		$this->assertSame(
			[ 'application/x-xz', 'application/x-7z-compressed' ],
			$reader->getExternalMimeTypes()
		);
	}

	public function testProgramThatCannotBeRun(): void {
		$reader = $this->newReader( [ ExternalBinaries::SEVEN_ZIP => '/not/installed/7z' ] );
		$path = $this->newFile( ExternalFixtures::decode( ExternalFixtures::SEVEN_ZIP_ONE ) );

		$this->assertStatusError(
			'requestimport-archive-unreadable',
			$reader->listFiles( $path, ArchiveReader::FORMAT_SEVEN_ZIP, 100, self::MAX_SIZE )
		);
	}

	public function testProgramFormatsThatAreNotTurnedOn(): void {
		$path = $this->newFile( ExternalFixtures::decode( ExternalFixtures::XZ_XML ) );
		$this->assertStatusError(
			'requestimport-archive-binary-unavailable',
			$this->reader->listFiles( $path, ArchiveReader::FORMAT_XZ, 100, self::MAX_SIZE )
		);

		$path = $this->newFile( ExternalFixtures::decode( ExternalFixtures::SEVEN_ZIP_ONE ) );
		$this->assertStatusError(
			'requestimport-archive-binary-unavailable',
			$this->reader->listFiles( $path, ArchiveReader::FORMAT_SEVEN_ZIP, 100, self::MAX_SIZE )
		);

		$this->assertStatusError(
			'requestimport-archive-binary-unavailable',
			$this->reader->extractTo(
				$path,
				ArchiveReader::FORMAT_SEVEN_ZIP,
				new ArchiveEntry( 0, 'dump.xml', 28, 100 ),
				$this->getNewTempFile(),
				self::MAX_SIZE,
				null
			)
		);
	}

	public function testXz(): void {
		$reader = $this->newReader( [ ExternalBinaries::XZ => $this->requireBinary( ExternalBinaries::XZ ) ] );
		$path = $this->newFile( ExternalFixtures::decode( ExternalFixtures::XZ_XML ) );
		$this->assertSame( ArchiveReader::FORMAT_XZ, $reader->detectFormat( $path ) );

		$status = $reader->listFiles( $path, ArchiveReader::FORMAT_XZ, 100, self::MAX_SIZE );
		$this->assertStatusGood( $status );
		$this->assertCount( 1, $status->getValue() );
		$this->assertSame( '', $status->getValue()[0]->name );

		$destination = $this->getNewTempFile();
		$percentages = [];
		$extracted = $reader->extractTo(
			$path,
			ArchiveReader::FORMAT_XZ,
			$status->getValue()[0],
			$destination,
			self::MAX_SIZE,
			static function ( ?int $percent ) use ( &$percentages ): void {
				$percentages[] = $percent;
			}
		);

		$this->assertStatusGood( $extracted );
		$this->assertSame( ExternalFixtures::XML, file_get_contents( $destination ) );
		$this->assertSame( [ null, 100 ], $percentages );
	}

	public function testXzTar(): void {
		$reader = $this->newReader( [ ExternalBinaries::XZ => $this->requireBinary( ExternalBinaries::XZ ) ] );
		$path = $this->newFile( ExternalFixtures::decode( ExternalFixtures::XZ_TAR ) );

		$status = $reader->listFiles( $path, ArchiveReader::FORMAT_XZ, 100, self::MAX_SIZE );
		$this->assertStatusGood( $status );
		$this->assertCount( 1, $status->getValue() );
		$this->assertSame( 'dump.xml', $status->getValue()[0]->name );
		$this->assertSame( 28, $status->getValue()[0]->size );

		$destination = $this->getNewTempFile();
		$extracted = $reader->extractTo(
			$path,
			ArchiveReader::FORMAT_XZ,
			$status->getValue()[0],
			$destination,
			self::MAX_SIZE,
			null
		);

		$this->assertStatusGood( $extracted );
		$this->assertSame( ExternalFixtures::XML, file_get_contents( $destination ) );
	}

	public function testXzTarWithSeveralFiles(): void {
		$reader = $this->newReader( [ ExternalBinaries::XZ => $this->requireBinary( ExternalBinaries::XZ ) ] );
		$path = $this->newFile( ExternalFixtures::decode( ExternalFixtures::XZ_TAR_TWO_FILES ) );

		$status = $reader->listFiles( $path, ArchiveReader::FORMAT_XZ, 100, self::MAX_SIZE );
		$this->assertStatusGood( $status );
		$this->assertCount( 2, $status->getValue() );
	}

	public function testXzTarFilesAreTakenOutOfOneUnpackedFile(): void {
		$reader = $this->newReader( [ ExternalBinaries::XZ => $this->requireBinary( ExternalBinaries::XZ ) ] );
		$path = $this->newFile( ExternalFixtures::decode( ExternalFixtures::XZ_TAR_TWO_FILES ) );

		$status = $reader->listFiles( $path, ArchiveReader::FORMAT_XZ, 100, self::MAX_SIZE );
		$this->assertStatusGood( $status );

		$contents = [];
		foreach ( $status->getValue() as $entry ) {
			$destination = $this->getNewTempFile();
			$this->assertStatusGood(
				$reader->extractTo( $path, ArchiveReader::FORMAT_XZ, $entry, $destination, self::MAX_SIZE, null )
			);

			$contents[$entry->name] = file_get_contents( $destination );
		}

		$this->assertCount( 2, $contents );
		$this->assertContains( ExternalFixtures::XML, $contents );
	}

	public function testXzTarWithLink(): void {
		$reader = $this->newReader( [ ExternalBinaries::XZ => $this->requireBinary( ExternalBinaries::XZ ) ] );
		$path = $this->newFile( ExternalFixtures::decode( ExternalFixtures::XZ_TAR_LINK ) );

		$this->assertStatusError(
			'requestimport-archive-unsafe',
			$reader->listFiles( $path, ArchiveReader::FORMAT_XZ, 100, self::MAX_SIZE )
		);
	}

	public function testXzExpandingPastTheLimits(): void {
		$reader = $this->newReader( [ ExternalBinaries::XZ => $this->requireBinary( ExternalBinaries::XZ ) ] );
		$path = $this->newFile( ExternalFixtures::decode( ExternalFixtures::XZ_ZEROS ) );
		$entry = new ArchiveEntry( 0, '', null, (int)filesize( $path ) );

		$this->assertStatusError(
			'requestimport-archive-ratio',
			$reader->extractTo(
				$path,
				ArchiveReader::FORMAT_XZ,
				$entry,
				$this->getNewTempFile(),
				PHP_INT_MAX,
				null
			)
		);

		$this->assertStatusError(
			'requestimport-archive-too-large',
			$reader->extractTo( $path, ArchiveReader::FORMAT_XZ, $entry, $this->getNewTempFile(), 100, null )
		);
	}

	public function testXzThatWasCutOff(): void {
		$reader = $this->newReader( [ ExternalBinaries::XZ => $this->requireBinary( ExternalBinaries::XZ ) ] );
		$xz = ExternalFixtures::decode( ExternalFixtures::XZ_XML );
		$path = $this->newFile( substr( $xz, 0, strlen( $xz ) - 20 ) );

		$status = $reader->listFiles( $path, ArchiveReader::FORMAT_XZ, 100, self::MAX_SIZE );
		if ( $status->isOK() ) {
			$status = $reader->extractTo(
				$path,
				ArchiveReader::FORMAT_XZ,
				$status->getValue()[0],
				$this->getNewTempFile(),
				self::MAX_SIZE,
				null
			);
		}

		$this->assertStatusError( 'requestimport-archive-unreadable', $status );
	}

	public function testSevenZip(): void {
		$reader = $this->newReader( [
			ExternalBinaries::SEVEN_ZIP => $this->requireBinary( ExternalBinaries::SEVEN_ZIP ),
		] );

		$path = $this->newFile( ExternalFixtures::decode( ExternalFixtures::SEVEN_ZIP_ONE ) );
		$this->assertSame( ArchiveReader::FORMAT_SEVEN_ZIP, $reader->detectFormat( $path ) );

		$status = $reader->listFiles( $path, ArchiveReader::FORMAT_SEVEN_ZIP, 100, self::MAX_SIZE );
		$this->assertStatusGood( $status );
		$this->assertCount( 1, $status->getValue() );
		$this->assertSame( 'dump.xml', $status->getValue()[0]->name );
		$this->assertSame( 28, $status->getValue()[0]->size );

		$destination = $this->getNewTempFile();
		$percentages = [];
		$extracted = $reader->extractTo(
			$path,
			ArchiveReader::FORMAT_SEVEN_ZIP,
			$status->getValue()[0],
			$destination,
			self::MAX_SIZE,
			static function ( ?int $percent ) use ( &$percentages ): void {
				$percentages[] = $percent;
			}
		);

		$this->assertStatusGood( $extracted );
		$this->assertSame( ExternalFixtures::XML, file_get_contents( $destination ) );
		$this->assertSame( [ null, 100 ], $percentages );
	}

	public function testSevenZipInADirectory(): void {
		$reader = $this->newReader( [
			ExternalBinaries::SEVEN_ZIP => $this->requireBinary( ExternalBinaries::SEVEN_ZIP ),
		] );

		$path = $this->newFile( ExternalFixtures::decode( ExternalFixtures::SEVEN_ZIP_DIRECTORY ) );
		$status = $reader->listFiles( $path, ArchiveReader::FORMAT_SEVEN_ZIP, 100, self::MAX_SIZE );
		$this->assertStatusGood( $status );
		$this->assertCount( 1, $status->getValue() );
		$this->assertSame( 'd/dump.xml', $status->getValue()[0]->name );

		$destination = $this->getNewTempFile();
		$this->assertStatusGood( $reader->extractTo(
			$path,
			ArchiveReader::FORMAT_SEVEN_ZIP,
			$status->getValue()[0],
			$destination,
			self::MAX_SIZE,
			null
		) );

		$this->assertSame( ExternalFixtures::XML, file_get_contents( $destination ) );
	}

	public function testSevenZipSkipsMacMetadata(): void {
		$reader = $this->newReader( [
			ExternalBinaries::SEVEN_ZIP => $this->requireBinary( ExternalBinaries::SEVEN_ZIP ),
		] );

		$path = $this->newFile( ExternalFixtures::decode( ExternalFixtures::SEVEN_ZIP_MACOS ) );
		$status = $reader->listFiles( $path, ArchiveReader::FORMAT_SEVEN_ZIP, 100, self::MAX_SIZE );
		$this->assertStatusGood( $status );
		$this->assertCount( 1, $status->getValue() );
		$this->assertSame( 'dump.xml', $status->getValue()[0]->name );
	}

	public function testSevenZipWithSeveralFiles(): void {
		$reader = $this->newReader( [
			ExternalBinaries::SEVEN_ZIP => $this->requireBinary( ExternalBinaries::SEVEN_ZIP ),
		] );

		$path = $this->newFile( ExternalFixtures::decode( ExternalFixtures::SEVEN_ZIP_TWO_FILES ) );
		$status = $reader->listFiles( $path, ArchiveReader::FORMAT_SEVEN_ZIP, 100, self::MAX_SIZE );
		$this->assertStatusGood( $status );
		$this->assertCount( 2, $status->getValue() );

		$this->assertStatusError(
			'requestimport-archive-too-many-entries',
			$reader->listFiles( $path, ArchiveReader::FORMAT_SEVEN_ZIP, 2, self::MAX_SIZE )
		);
	}

	public function testSevenZipFilesAreTakenOutOfOneUnpackedStream(): void {
		$reader = $this->newReader( [
			ExternalBinaries::SEVEN_ZIP => $this->requireBinary( ExternalBinaries::SEVEN_ZIP ),
		] );

		// The skipped metadata file comes first in the stream, so the offset of the next one is not zero.
		$path = $this->newFile( ExternalFixtures::decode( ExternalFixtures::SEVEN_ZIP_MACOS ) );
		$status = $reader->listFiles( $path, ArchiveReader::FORMAT_SEVEN_ZIP, 100, self::MAX_SIZE );
		$this->assertStatusGood( $status );

		$destination = $this->getNewTempFile();
		$this->assertStatusGood( $reader->extractTo(
			$path,
			ArchiveReader::FORMAT_SEVEN_ZIP,
			$status->getValue()[0],
			$destination,
			self::MAX_SIZE,
			null
		) );

		$this->assertSame( ExternalFixtures::XML, file_get_contents( $destination ) );
	}

	public function testSevenZipFilesAreTakenOneByOneWhenAllOfThemAreTooBig(): void {
		$reader = $this->newReader( [
			ExternalBinaries::SEVEN_ZIP => $this->requireBinary( ExternalBinaries::SEVEN_ZIP ),
		] );

		// The limit allows the file but not everything that the archive holds.
		$path = $this->newFile( ExternalFixtures::decode( ExternalFixtures::SEVEN_ZIP_MACOS ) );
		$status = $reader->listFiles( $path, ArchiveReader::FORMAT_SEVEN_ZIP, 100, 30 );
		$this->assertStatusGood( $status );

		$destination = $this->getNewTempFile();
		$this->assertStatusGood( $reader->extractTo(
			$path,
			ArchiveReader::FORMAT_SEVEN_ZIP,
			$status->getValue()[0],
			$destination,
			30,
			null
		) );

		$this->assertSame( ExternalFixtures::XML, file_get_contents( $destination ) );
	}

	/** @dataProvider provideUnacceptableSevenZipArchives */
	public function testSevenZipThatIsNotAcceptable( string $fixture, string $error ): void {
		$reader = $this->newReader( [
			ExternalBinaries::SEVEN_ZIP => $this->requireBinary( ExternalBinaries::SEVEN_ZIP ),
		] );

		$path = $this->newFile( ExternalFixtures::decode( $fixture ) );
		$this->assertStatusError(
			$error,
			$reader->listFiles( $path, ArchiveReader::FORMAT_SEVEN_ZIP, 100, self::MAX_SIZE )
		);
	}

	public static function provideUnacceptableSevenZipArchives(): Generator {
		yield 'symbolic link' => [ ExternalFixtures::SEVEN_ZIP_LINK, 'requestimport-archive-unsafe' ];
		yield 'encrypted file' => [ ExternalFixtures::SEVEN_ZIP_ENCRYPTED, 'requestimport-archive-unsafe' ];
		yield 'encrypted headers' => [
			ExternalFixtures::SEVEN_ZIP_ENCRYPTED_HEADERS,
			'requestimport-archive-unreadable',
		];
	}

	public function testSevenZipExpandingPastTheLimits(): void {
		$reader = $this->newReader( [
			ExternalBinaries::SEVEN_ZIP => $this->requireBinary( ExternalBinaries::SEVEN_ZIP ),
		] );

		$path = $this->newFile( ExternalFixtures::decode( ExternalFixtures::SEVEN_ZIP_ZEROS ) );
		$entry = new ArchiveEntry( 0, 'zeros8.xml', null, (int)filesize( $path ) );

		$this->assertStatusError(
			'requestimport-archive-ratio',
			$reader->extractTo(
				$path,
				ArchiveReader::FORMAT_SEVEN_ZIP,
				$entry,
				$this->getNewTempFile(),
				PHP_INT_MAX,
				null
			)
		);

		$this->assertStatusError(
			'requestimport-archive-too-large',
			$reader->extractTo( $path, ArchiveReader::FORMAT_SEVEN_ZIP, $entry, $this->getNewTempFile(), 100, null )
		);
	}

	public function testSevenZipEntryThatIsNotThere(): void {
		$reader = $this->newReader( [
			ExternalBinaries::SEVEN_ZIP => $this->requireBinary( ExternalBinaries::SEVEN_ZIP ),
		] );

		$path = $this->newFile( ExternalFixtures::decode( ExternalFixtures::SEVEN_ZIP_ONE ) );
		$this->assertStatusError(
			'requestimport-archive-unreadable',
			$reader->extractTo(
				$path,
				ArchiveReader::FORMAT_SEVEN_ZIP,
				new ArchiveEntry( 0, 'other.xml', 28, (int)filesize( $path ) ),
				$this->getNewTempFile(),
				self::MAX_SIZE,
				null
			)
		);
	}

	public function testSevenZipEntryThatIsNotAsBigAsListed(): void {
		$reader = $this->newReader( [
			ExternalBinaries::SEVEN_ZIP => $this->requireBinary( ExternalBinaries::SEVEN_ZIP ),
		] );

		$path = $this->newFile( ExternalFixtures::decode( ExternalFixtures::SEVEN_ZIP_ONE ) );
		$this->assertStatusError(
			'requestimport-archive-unreadable',
			$reader->extractTo(
				$path,
				ArchiveReader::FORMAT_SEVEN_ZIP,
				new ArchiveEntry( 0, 'dump.xml', 27, (int)filesize( $path ) ),
				$this->getNewTempFile(),
				self::MAX_SIZE,
				null
			)
		);
	}

	public function testSevenZipThatNeedsMoreMemoryThanAllowed(): void {
		$reader = $this->newReader( [
			ExternalBinaries::SEVEN_ZIP => [
				'binary' => $this->requireBinary( ExternalBinaries::SEVEN_ZIP ),
				'limits' => [ 'dictionary' => 1 ],
			],
		] );

		$path = $this->newFile( ExternalFixtures::decode( ExternalFixtures::SEVEN_ZIP_ONE ) );
		$this->assertStatusError(
			'requestimport-archive-memory',
			$reader->listFiles( $path, ArchiveReader::FORMAT_SEVEN_ZIP, 100, self::MAX_SIZE )
		);
	}

	public function testSevenZipWithTheSettingsAsAnArray(): void {
		$reader = $this->newReader( [
			ExternalBinaries::SEVEN_ZIP => [
				'binary' => $this->requireBinary( ExternalBinaries::SEVEN_ZIP ),
				'limits' => [ 'dictionary' => 4096, 'memory' => 2147483648, 'time' => 60 ],
			],
		] );

		$path = $this->newFile( ExternalFixtures::decode( ExternalFixtures::SEVEN_ZIP_ONE ) );
		$this->assertSame( ArchiveReader::FORMAT_SEVEN_ZIP, $reader->detectFormat( $path ) );

		$status = $reader->listFiles( $path, ArchiveReader::FORMAT_SEVEN_ZIP, 100, self::MAX_SIZE );
		$this->assertStatusGood( $status );
		$this->assertSame( 'dump.xml', $status->getValue()[0]->name );
	}
}
