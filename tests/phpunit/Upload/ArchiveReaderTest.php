<?php

namespace Miraheze\ImportDump\Tests\Upload;

use Generator;
use MediaWikiIntegrationTestCase;
use Miraheze\ImportDump\Upload\ArchiveEntry;
use Miraheze\ImportDump\Upload\ArchiveReader;
use ZipArchive;

/**
 * @group ImportDump
 * @group medium
 * @covers \Miraheze\ImportDump\Upload\ArchiveReader
 * @covers \Miraheze\ImportDump\Upload\ArchiveEntry
 */
class ArchiveReaderTest extends MediaWikiIntegrationTestCase {

	use ArchiveFixtures;

	private ArchiveReader $reader;

	protected function setUp(): void {
		parent::setUp();
		$this->reader = new ArchiveReader();
	}

	public function testDetectFormat(): void {
		$this->assertSame( ArchiveReader::FORMAT_ZIP, $this->reader->detectFormat( $this->newZip( [ 'a' => 'b' ] ) ) );
		$this->assertSame(
			ArchiveReader::FORMAT_GZIP,
			$this->reader->detectFormat( $this->newFile( gzencode( 'content' ) ) )
		);

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

		$status = $this->reader->listFiles( $path, ArchiveReader::FORMAT_ZIP, 100 );
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

		$status = $this->reader->listFiles( $path, ArchiveReader::FORMAT_GZIP, 100 );
		$this->assertStatusGood( $status );
		$this->assertCount( 1, $status->getValue() );
		$this->assertNull( $status->getValue()[0]->size );
		$this->assertSame( filesize( $path ), $status->getValue()[0]->compressedSize );
	}

	public function testListFilesUnsafeName(): void {
		$path = $this->newZip( [ '../dump.xml' => 'content' ] );
		$this->assertStatusError(
			'importdump-archive-unsafe',
			$this->reader->listFiles( $path, ArchiveReader::FORMAT_ZIP, 100 )
		);
	}

	public function testListFilesLink(): void {
		$path = $this->newZip( [ 'dump.xml' => 'target' ] );

		$zip = new ZipArchive();
		$zip->open( $path );
		$zip->setExternalAttributesName( 'dump.xml', ZipArchive::OPSYS_UNIX, 0120777 << 16 );
		$zip->close();

		$this->assertStatusError(
			'importdump-archive-unsafe',
			$this->reader->listFiles( $path, ArchiveReader::FORMAT_ZIP, 100 )
		);
	}

	public function testListFilesRegularFileWithUnixAttributes(): void {
		$path = $this->newZip( [ 'dump.xml' => 'content' ] );

		$zip = new ZipArchive();
		$zip->open( $path );
		$zip->setExternalAttributesName( 'dump.xml', ZipArchive::OPSYS_UNIX, 0100644 << 16 );
		$zip->close();

		$this->assertStatusGood( $this->reader->listFiles( $path, ArchiveReader::FORMAT_ZIP, 100 ) );
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
			'importdump-archive-unsafe',
			$this->reader->listFiles( $path, ArchiveReader::FORMAT_ZIP, 100 )
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
			'importdump-archive-unsafe',
			$this->reader->listFiles( $path, ArchiveReader::FORMAT_ZIP, 10 )
		);
	}

	public function testListFilesInconsistentHeaders(): void {
		$contents = file_get_contents( $this->newZip( [ 'dump.xml' => str_repeat( 'a', 5000 ) ] ) );

		// The name in the local header no longer matches the one in the central directory.
		$contents[30] = 'X';

		$this->assertStatusError(
			'importdump-archive-unreadable',
			$this->reader->listFiles( $this->newFile( $contents ), ArchiveReader::FORMAT_ZIP, 10 )
		);
	}

	/** @dataProvider provideInvalidGzipHeaders */
	public function testListFilesInvalidGzipHeader( string $header ): void {
		$this->assertStatusError(
			'importdump-archive-unreadable',
			$this->reader->listFiles( $this->newFile( $header ), ArchiveReader::FORMAT_GZIP, 10 )
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
			'importdump-archive-too-many-entries',
			$this->reader->listFiles( $path, ArchiveReader::FORMAT_ZIP, 2 )
		);
	}

	public function testListFilesUnreadable(): void {
		$path = $this->newFile( "PK\x03\x04 this is not a zip file" );
		$this->assertStatusError(
			'importdump-archive-unreadable',
			$this->reader->listFiles( $path, ArchiveReader::FORMAT_ZIP, 100 )
		);
	}

	public function testExtractZip(): void {
		$path = $this->newZip( [ 'dump.xml' => str_repeat( 'content ', 1000 ) ] );
		$entry = $this->reader->listFiles( $path, ArchiveReader::FORMAT_ZIP, 100 )->getValue()[0];
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
		$this->assertSame( 0, $percentages[0] );
		$this->assertSame( 100, end( $percentages ) );
	}

	public function testExtractGzip(): void {
		$path = $this->newFile( gzencode( str_repeat( 'content ', 1000 ) ) );
		$entry = $this->reader->listFiles( $path, ArchiveReader::FORMAT_GZIP, 100 )->getValue()[0];
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
		$entry = $this->reader->listFiles( $zip, ArchiveReader::FORMAT_ZIP, 100 )->getValue()[0];
		$this->assertStatusError(
			'importdump-archive-too-large',
			$this->reader->extractTo( $zip, ArchiveReader::FORMAT_ZIP, $entry, $this->getNewTempFile(), 100, null )
		);

		$gzip = $this->newFile( gzencode( str_repeat( 'content ', 1000 ) ) );
		$entry = $this->reader->listFiles( $gzip, ArchiveReader::FORMAT_GZIP, 100 )->getValue()[0];
		$destination = $this->getNewTempFile();
		$this->assertStatusError(
			'importdump-archive-too-large',
			$this->reader->extractTo( $gzip, ArchiveReader::FORMAT_GZIP, $entry, $destination, 100, null )
		);

		$this->assertSame( 0, filesize( $destination ) );
	}

	public function testExtractExtremeCompressionRatio(): void {
		$path = $this->newFile( gzencode( str_repeat( 'a', 4 * 1048576 ) ) );
		$entry = $this->reader->listFiles( $path, ArchiveReader::FORMAT_GZIP, 100 )->getValue()[0];

		$destination = $this->getNewTempFile();
		$this->assertStatusError(
			'importdump-archive-ratio',
			$this->reader->extractTo( $path, ArchiveReader::FORMAT_GZIP, $entry, $destination, PHP_INT_MAX, null )
		);
	}

	public function testExtractEntryWithWrongSize(): void {
		$path = $this->newZip( [ 'dump.xml' => 'content' ] );
		$entry = new ArchiveEntry( 0, 'dump.xml', 1000, 10 );

		$this->assertStatusError(
			'importdump-archive-unreadable',
			$this->reader->extractTo( $path, ArchiveReader::FORMAT_ZIP, $entry, $this->getNewTempFile(), 1048576, null )
		);
	}
}
