<?php

namespace Miraheze\ImportDump\Tests\Upload;

use Generator;
use MediaWiki\Config\ServiceOptions;
use MediaWiki\Status\Status;
use MediaWikiIntegrationTestCase;
use Miraheze\ImportDump\ConfigNames;
use Miraheze\ImportDump\RequestHistory;
use Miraheze\ImportDump\Upload\ArchiveReader;
use Miraheze\ImportDump\Upload\DumpProcessor;
use Miraheze\ImportDump\Upload\DumpResult;
use Miraheze\ImportDump\Upload\ExternalBinaries;
use Wikimedia\FileBackend\FSFile\TempFSFileFactory;

/**
 * @group ImportDump
 * @group medium
 * @covers \Miraheze\ImportDump\Upload\DumpProcessor
 * @covers \Miraheze\ImportDump\Upload\DumpResult
 */
class DumpProcessorTest extends MediaWikiIntegrationTestCase
	implements RequestHistory {

	use ArchiveFixtures;

	private const int MAX_SIZE = 1048576;

	private function newProcessor( int $maxSize, bool $detectHistory, array $binaries ): DumpProcessor {
		return new DumpProcessor(
			$this->newReader( $binaries ),
			new ServiceOptions( DumpProcessor::CONSTRUCTOR_OPTIONS, [
				ConfigNames::EnableHistoryDetection => $detectHistory,
				ConfigNames::MaxArchiveEntries => 100,
				ConfigNames::MaxExtractedSize => $maxSize,
			] ),
			new TempFSFileFactory( $this->getNewTempDirectory() ),
			$this->getServiceContainer()->getUploadVerification()
		);
	}

	private function process( string $path ): Status {
		return $this->newProcessor( self::MAX_SIZE, true, [] )->process( $path, null );
	}

	/**
	 * @param int $id
	 * @param array<int, int> $revisions Revision ID to parent revision ID, or 0 if it has none.
	 */
	private static function page( int $id, array $revisions ): string {
		return self::buildPage( $id, $revisions, true );
	}

	/**
	 * @param int $id
	 * @param array<int, int> $revisions Revision ID to parent revision ID, or 0 if it has none.
	 */
	private static function pageWithoutParents( int $id, array $revisions ): string {
		return self::buildPage( $id, $revisions, false );
	}

	/**
	 * @param int $id
	 * @param array<int, int> $revisions Revision ID to parent revision ID, or 0 if it has none.
	 * @param bool $withParents Whether each revision names its parent.
	 */
	private static function buildPage( int $id, array $revisions, bool $withParents ): string {
		$xml = "<page><title>Page $id</title><ns>0</ns><id>$id</id>";
		foreach ( $revisions as $revisionId => $parentId ) {
			$xml .= "<revision><id>$revisionId</id>";
			if ( $withParents && $parentId ) {
				$xml .= "<parentid>$parentId</parentid>";
			}

			$xml .= '<timestamp>2024-01-01T00:00:00Z</timestamp>' .
				'<contributor><username>Test</username><id>7</id></contributor>' .
				'<text xml:space="preserve">Text</text></revision>';
		}

		return $xml . '</page>';
	}

	private static function dump( string $pages ): string {
		return self::dumpWithVersion( $pages, '0.11' );
	}

	private static function dumpWithVersion( string $pages, string $version ): string {
		return '<mediawiki xmlns="http://www.mediawiki.org/xml/export-' . $version . '/" version="' . $version .
			'" xml:lang="en"><siteinfo><sitename>Test</sitename></siteinfo>' . $pages . '</mediawiki>';
	}

	/** @dataProvider provideHistory */
	public function testHistory( string $xml, string $expected ): void {
		$status = $this->process( $this->newFile( $xml ) );
		$this->assertStatusGood( $status );

		$dump = $status->getValue();
		$this->assertInstanceOf( DumpResult::class, $dump );
		$this->assertSame( $expected, $dump->history );
		$this->assertFalse( $dump->wasExtracted() );
	}

	public static function provideHistory(): Generator {
		yield 'full history' => [
			self::dump( self::page( 1, [ 10 => 0, 11 => 10, 12 => 11 ] ) ),
			self::HISTORY_FULL,
		];

		yield 'only the current revision' => [
			self::dump( self::page( 1, [ 12 => 11 ] ) ),
			self::HISTORY_PARTIAL,
		];

		yield 'pages with a single revision' => [
			self::dump( self::page( 1, [ 10 => 0 ] ) . self::page( 2, [ 20 => 0 ] ) ),
			self::HISTORY_FULL,
		];

		yield 'a few gaps are allowed' => [
			self::dump(
				implode( '', array_map(
					static fn ( int $id ): string => self::page( $id, [ $id * 10 => 0 ] ),
					range( 1, 30 )
				) ) . self::page( 31, [ 311 => 310 ] )
			),
			self::HISTORY_FULL,
		];

		yield 'mostly gaps' => [
			self::dump(
				self::page( 1, [ 11 => 10 ] ) . self::page( 2, [ 21 => 20 ] ) . self::page( 3, [ 30 => 0 ] )
			),
			self::HISTORY_PARTIAL,
		];

		yield 'old schema that does not name parents' => [
			self::dumpWithVersion( self::pageWithoutParents( 1, [ 10 => 0, 11 => 10 ] ), '0.6' ),
			self::HISTORY_UNKNOWN,
		];

		yield 'page without revisions among others' => [
			self::dump(
				'<page><title>Empty</title><ns>0</ns><id>5</id></page>' .
				self::page( 1, [ 10 => 0, 11 => 10 ] )
			),
			self::HISTORY_FULL,
		];
	}

	/** @dataProvider provideInvalidXml */
	public function testInvalidXml( string $xml, string $expectedError ): void {
		$this->assertStatusError(
			$expectedError,
			$this->process( $this->newFile( $xml ) )
		);
	}

	public static function provideInvalidXml(): Generator {
		$page = self::page( 1, [ 10 => 0 ] );

		yield 'not xml' => [ 'content', 'importdump-xml-invalid' ];
		yield 'truncated' => [ substr( self::dump( $page ), 0, -30 ), 'importdump-xml-invalid' ];
		yield 'other root element' => [ '<html xmlns="http://www.w3.org/1999/xhtml"/>', 'importdump-xml-not-dump' ];
		yield 'no export namespace' => [ '<mediawiki>' . $page . '</mediawiki>', 'importdump-xml-not-dump' ];
		yield 'no pages' => [ self::dump( '' ), 'importdump-xml-no-pages' ];
		yield 'doctype' => [
			'<!DOCTYPE mediawiki><mediawiki xmlns="http://www.mediawiki.org/xml/export-0.11/">' .
				$page . '</mediawiki>',
			'importdump-xml-doctype',
		];

		yield 'entity declaration' => [
			'<!DOCTYPE mediawiki [<!ENTITY a "aaaa">]>' .
				'<mediawiki xmlns="http://www.mediawiki.org/xml/export-0.11/">' . $page . '</mediawiki>',
			'importdump-xml-doctype',
		];
	}

	public function testEmptyFile(): void {
		$status = $this->process( $this->newFile( '' ) );
		$this->assertStatusNotGood( $status );
	}

	public function testProgressOfPlainXml(): void {
		$xml = self::dump( self::page( 1, [ 10 => 0, 11 => 10 ] ) );

		$calls = [];
		$status = $this->newProcessor( self::MAX_SIZE, true, [] )->process(
			$this->newFile( $xml ),
			static function ( string $stage, ?int $percent ) use ( &$calls ): void {
				$calls[] = [ $stage, $percent ];
			}
		);

		$this->assertStatusGood( $status );
		$this->assertSame( [ DumpProcessor::STAGE_CHECKING, 100 ], end( $calls ) );
		$this->assertSame(
			[ DumpProcessor::STAGE_CHECKING ],
			array_values( array_unique( array_column( $calls, 0 ) ) )
		);
	}

	public function testZipWithOneFile(): void {
		$xml = self::dump( self::page( 1, [ 10 => 0, 11 => 10 ] ) );
		$status = $this->process( $this->newZip( [ 'any name.txt' => $xml ] ) );
		$this->assertStatusGood( $status );

		$dump = $status->getValue();
		$this->assertTrue( $dump->wasExtracted() );
		$this->assertSame( self::HISTORY_FULL, $dump->history );
		$this->assertSame( $xml, file_get_contents( $dump->path ) );

		$path = $dump->path;
		unset( $status, $dump );
		$this->assertFileDoesNotExist( $path );
	}

	public function testProgressOfZip(): void {
		$xml = self::dump( self::page( 1, [ 10 => 0, 11 => 10 ] ) );

		$calls = [];
		$status = $this->newProcessor( self::MAX_SIZE, true, [] )->process(
			$this->newZip( [ 'dump.xml' => $xml ] ),
			static function ( string $stage, ?int $percent ) use ( &$calls ): void {
				$calls[] = [ $stage, $percent ];
			}
		);

		$this->assertStatusGood( $status );
		$this->assertSame( [ DumpProcessor::STAGE_EXTRACTING, null ], $calls[0] );
		$this->assertContains( [ DumpProcessor::STAGE_EXTRACTING, 100 ], $calls );
		$this->assertSame( [ DumpProcessor::STAGE_CHECKING, 100 ], end( $calls ) );
	}

	public function testZipIgnoresDirectoriesAndMacMetadata(): void {
		$xml = self::dump( self::page( 1, [ 10 => 0 ] ) );
		$status = $this->process( $this->newZip( [
			'folder/' => '',
			'folder/dump.xml' => $xml,
			'__MACOSX/folder/._dump.xml' => 'junk',
		] ) );

		$this->assertStatusGood( $status );
		$this->assertTrue( $status->getValue()->wasExtracted() );
	}

	public function testZipWithSeveralFiles(): void {
		$xml = self::dump( self::page( 1, [ 10 => 0 ] ) );
		$status = $this->process(
			$this->newZip( [ 'a.xml' => $xml, 'b.xml' => $xml ] )
		);

		$this->assertStatusError( 'importdump-archive-not-single', $status );
	}

	public function testZipWithOnlyDirectories(): void {
		$status = $this->process(
			$this->newZip( [ 'folder/' => '', 'other/' => '' ] )
		);

		$this->assertStatusError( 'importdump-archive-not-single', $status );
	}

	public function testZipWithUnsafeName(): void {
		$xml = self::dump( self::page( 1, [ 10 => 0 ] ) );
		$status = $this->process( $this->newZip( [ '../dump.xml' => $xml ] ) );
		$this->assertStatusError( 'importdump-archive-unsafe', $status );
	}

	public function testZipWithNestedArchive(): void {
		$inner = $this->newZip( [ 'dump.xml' => self::dump( self::page( 1, [ 10 => 0 ] ) ) ] );
		$status = $this->process(
			$this->newZip( [ 'inner.zip' => file_get_contents( $inner ) ] )
		);

		$this->assertStatusError( 'importdump-xml-invalid', $status );
	}

	public function testCorruptZip(): void {
		$status = $this->process(
			$this->newFile( "PK\x03\x04 this is not a zip file" )
		);

		$this->assertStatusError( 'importdump-archive-unreadable', $status );
	}

	public function testGzip(): void {
		$xml = self::dump( self::page( 1, [ 10 => 0, 11 => 10 ] ) );
		$status = $this->process( $this->newFile( gzencode( $xml ) ) );
		$this->assertStatusGood( $status );

		$dump = $status->getValue();
		$this->assertTrue( $dump->wasExtracted() );
		$this->assertSame( self::HISTORY_FULL, $dump->history );
		$this->assertSame( $xml, file_get_contents( $dump->path ) );
	}

	public function testGzipWithInvalidXml(): void {
		$status = $this->process( $this->newFile( gzencode( 'content' ) ) );
		$this->assertStatusError( 'importdump-xml-invalid', $status );
	}

	public function testTar(): void {
		$xml = self::dump( self::page( 1, [ 10 => 0, 11 => 10 ] ) );
		$status = $this->process( $this->newTar( [ './' => '', './any name.txt' => $xml ] ) );
		$this->assertStatusGood( $status );

		$dump = $status->getValue();
		$this->assertTrue( $dump->wasExtracted() );
		$this->assertSame( self::HISTORY_FULL, $dump->history );
		$this->assertSame( $xml, file_get_contents( $dump->path ) );
	}

	public function testGzippedTar(): void {
		$xml = self::dump( self::page( 1, [ 10 => 0, 11 => 10 ] ) );
		$status = $this->process( $this->newFile( gzencode( TarFixture::build( [ 'dump.xml' => $xml ] ) ) ) );
		$this->assertStatusGood( $status );

		$dump = $status->getValue();
		$this->assertTrue( $dump->wasExtracted() );
		$this->assertSame( $xml, file_get_contents( $dump->path ) );
	}

	public function testBzip2(): void {
		if ( !function_exists( 'bzcompress' ) ) {
			$this->markTestSkipped( 'The bz2 extension is not installed.' );
		}

		$xml = self::dump( self::page( 1, [ 10 => 0 ] ) );
		$status = $this->process( $this->newFile( bzcompress( $xml ) ) );
		$this->assertStatusGood( $status );
		$this->assertSame( $xml, file_get_contents( $status->getValue()->path ) );
	}

	public function testProgressOfTar(): void {
		$xml = self::dump( self::page( 1, [ 10 => 0, 11 => 10 ] ) );

		$calls = [];
		$status = $this->newProcessor( self::MAX_SIZE, true, [] )->process(
			$this->newTar( [ 'dump.xml' => $xml ] ),
			static function ( string $stage, ?int $percent ) use ( &$calls ): void {
				$calls[] = [ $stage, $percent ];
			}
		);

		$this->assertStatusGood( $status );
		$this->assertSame( [ DumpProcessor::STAGE_EXTRACTING, null ], $calls[0] );
		$this->assertContains( [ DumpProcessor::STAGE_EXTRACTING, 100 ], $calls );
		$this->assertSame( [ DumpProcessor::STAGE_CHECKING, 100 ], end( $calls ) );
	}

	public function testTarWithSeveralFiles(): void {
		$xml = self::dump( self::page( 1, [ 10 => 0 ] ) );
		$status = $this->process( $this->newTar( [ 'a.xml' => $xml, 'b.xml' => $xml ] ) );
		$this->assertStatusError( 'importdump-archive-not-single', $status );
	}

	public function testTarWithOnlyDirectories(): void {
		$status = $this->process( $this->newTar( [ 'folder/' => '', 'other/' => '' ] ) );
		$this->assertStatusError( 'importdump-archive-not-single', $status );
	}

	public function testTarWithLink(): void {
		$xml = self::dump( self::page( 1, [ 10 => 0 ] ) );
		$tar = TarFixture::finish(
			TarFixture::entry( 'dump.xml', $xml, '0' ) . TarFixture::link( 'link', 'dump.xml', '2' )
		);

		$this->assertStatusError( 'importdump-archive-unsafe', $this->process( $this->newFile( $tar ) ) );
	}

	public function testTarWithInvalidXml(): void {
		$status = $this->process( $this->newTar( [ 'dump.xml' => 'content' ] ) );
		$this->assertStatusError( 'importdump-xml-invalid', $status );
	}

	public function testTarWithNestedArchive(): void {
		$inner = TarFixture::build( [ 'dump.xml' => self::dump( self::page( 1, [ 10 => 0 ] ) ) ] );
		$status = $this->process( $this->newTar( [ 'inner.tar' => $inner ] ) );
		$this->assertStatusError( 'importdump-xml-invalid', $status );
	}

	public function testArchiveExpandingPastTheLimit(): void {
		$xml = self::dump( self::page( 1, [ 10 => 0 ] ) );
		$processor = $this->newProcessor( strlen( $xml ) - 1, true, [] );

		$this->assertStatusError(
			'importdump-archive-too-large',
			$processor->process( $this->newFile( gzencode( $xml ) ), null )
		);

		$this->assertStatusError(
			'importdump-archive-too-large',
			$processor->process( $this->newZip( [ 'dump.xml' => $xml ] ), null )
		);

		$this->assertStatusError(
			'importdump-archive-too-large',
			$processor->process( $this->newTar( [ 'dump.xml' => $xml ] ), null )
		);

		$this->assertStatusError(
			'importdump-archive-too-large',
			$processor->process( $this->newFile( gzencode( TarFixture::build( [ 'dump.xml' => $xml ] ) ) ), null )
		);
	}

	public function testHistoryDetectionOff(): void {
		$xml = self::dump( self::page( 1, [ 12 => 11 ] ) );
		$status = $this->newProcessor( self::MAX_SIZE, false, [] )->process( $this->newFile( $xml ), null );

		$this->assertStatusGood( $status );
		$this->assertNull( $status->getValue()->history );
	}

	public function testHistoryDetectionOffStillRejectsInvalidXml(): void {
		$status = $this->newProcessor( self::MAX_SIZE, false, [] )->process( $this->newFile( 'content' ), null );
		$this->assertStatusError( 'importdump-xml-invalid', $status );
	}

	public function testMimeTypesFollowTheConfiguredPrograms(): void {
		$plain = $this->newProcessor( self::MAX_SIZE, true, [] );
		$this->assertNotContains( 'application/x-xz', $plain->getAcceptedMimeTypes() );
		$this->assertNotContains( 'application/x-7z-compressed', $plain->getArchiveMimeTypes() );
		$this->assertContains( 'application/zip', $plain->getArchiveMimeTypes() );
		$this->assertContains( 'text/xml', $plain->getAcceptedMimeTypes() );

		$both = $this->newProcessor( self::MAX_SIZE, true, [
			ExternalBinaries::XZ => '/not/installed/xz',
			ExternalBinaries::SEVEN_ZIP => '/not/installed/7z',
		] );

		foreach ( [ 'application/x-xz', 'application/x-7z-compressed' ] as $type ) {
			$this->assertContains( $type, $both->getAcceptedMimeTypes() );
			$this->assertContains( $type, $both->getArchiveMimeTypes() );
		}
	}

	public function testArchiveFormatsFollowTheConfiguredPrograms(): void {
		$this->assertSame( [
			ArchiveReader::FORMAT_ZIP,
			ArchiveReader::FORMAT_TAR,
			ArchiveReader::FORMAT_GZIP,
			ArchiveReader::FORMAT_BZIP2,
		], $this->newProcessor( self::MAX_SIZE, true, [] )->getArchiveFormats() );

		$formats = $this->newProcessor( self::MAX_SIZE, true, [
			ExternalBinaries::XZ => '/not/installed/xz',
			ExternalBinaries::SEVEN_ZIP => '/not/installed/7z',
		] )->getArchiveFormats();

		$this->assertSame(
			[ ArchiveReader::FORMAT_XZ, ArchiveReader::FORMAT_SEVEN_ZIP ],
			array_slice( $formats, 4 )
		);
	}

	public function testXzAndSevenZipAreProcessed(): void {
		$processor = $this->newProcessor( self::MAX_SIZE, false, [
			ExternalBinaries::XZ => $this->requireBinary( ExternalBinaries::XZ ),
			ExternalBinaries::SEVEN_ZIP => $this->requireBinary( ExternalBinaries::SEVEN_ZIP ),
		] );

		foreach ( [ ExternalFixtures::XZ_XML, ExternalFixtures::SEVEN_ZIP_ONE ] as $fixture ) {
			$status = $processor->process( $this->newFile( ExternalFixtures::decode( $fixture ) ), null );
			$this->assertStatusError( 'importdump-xml-not-dump', $status );
		}
	}
}
