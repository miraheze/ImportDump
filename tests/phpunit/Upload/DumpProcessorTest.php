<?php

namespace Miraheze\ImportDump\Tests\Upload;

use Generator;
use MediaWiki\Config\ServiceOptions;
use MediaWikiIntegrationTestCase;
use Miraheze\ImportDump\ConfigNames;
use Miraheze\ImportDump\RequestHistory;
use Miraheze\ImportDump\Upload\ArchiveReader;
use Miraheze\ImportDump\Upload\DumpProcessor;
use Miraheze\ImportDump\Upload\DumpResult;
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

	private function newProcessor( int $maxSize = self::MAX_SIZE, bool $detectHistory = true ): DumpProcessor {
		return new DumpProcessor(
			new ArchiveReader(),
			new ServiceOptions( DumpProcessor::CONSTRUCTOR_OPTIONS, [
				ConfigNames::EnableHistoryDetection => $detectHistory,
				ConfigNames::MaxArchiveEntries => 100,
				ConfigNames::MaxExtractedSize => $maxSize,
			] ),
			new TempFSFileFactory( $this->getNewTempDirectory() ),
			$this->getServiceContainer()->getUploadVerification()
		);
	}

	/** @param array<int, int> $revisions Revision ID to parent revision ID, or 0 if it has none. */
	private static function page( int $id, array $revisions, bool $withParents = true ): string {
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
		return '<mediawiki xmlns="http://www.mediawiki.org/xml/export-0.11/" version="0.11" xml:lang="en">' .
			'<siteinfo><sitename>Test</sitename></siteinfo>' . $pages . '</mediawiki>';
	}

	/** @dataProvider provideHistory */
	public function testHistory( string $xml, string $expected ): void {
		$status = $this->newProcessor()->process( $this->newFile( $xml ) );
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

		yield 'revisions that do not name their parent' => [
			self::dump( self::page( 1, [ 10 => 0, 11 => 10 ], withParents: false ) ),
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
			$this->newProcessor()->process( $this->newFile( $xml ) )
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
		$status = $this->newProcessor()->process( $this->newFile( '' ) );
		$this->assertStatusNotGood( $status );
	}

	public function testProgressOfPlainXml(): void {
		$xml = self::dump( self::page( 1, [ 10 => 0, 11 => 10 ] ) );

		$calls = [];
		$status = $this->newProcessor()->process(
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
		$status = $this->newProcessor()->process( $this->newZip( [ 'any name.txt' => $xml ] ) );
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
		$status = $this->newProcessor()->process(
			$this->newZip( [ 'dump.xml' => $xml ] ),
			static function ( string $stage, ?int $percent ) use ( &$calls ): void {
				$calls[] = [ $stage, $percent ];
			}
		);

		$this->assertStatusGood( $status );
		$this->assertSame( [ DumpProcessor::STAGE_EXTRACTING, 100 ], $calls[1] );
		$this->assertSame( [ DumpProcessor::STAGE_CHECKING, 100 ], end( $calls ) );
	}

	public function testZipIgnoresDirectoriesAndMacMetadata(): void {
		$xml = self::dump( self::page( 1, [ 10 => 0 ] ) );
		$status = $this->newProcessor()->process( $this->newZip( [
			'folder/' => '',
			'folder/dump.xml' => $xml,
			'__MACOSX/folder/._dump.xml' => 'junk',
		] ) );

		$this->assertStatusGood( $status );
		$this->assertTrue( $status->getValue()->wasExtracted() );
	}

	public function testZipWithSeveralFiles(): void {
		$xml = self::dump( self::page( 1, [ 10 => 0 ] ) );
		$status = $this->newProcessor()->process(
			$this->newZip( [ 'a.xml' => $xml, 'b.xml' => $xml ] )
		);

		$this->assertStatusError( 'importdump-archive-not-single', $status );
	}

	public function testZipWithOnlyDirectories(): void {
		$status = $this->newProcessor()->process(
			$this->newZip( [ 'folder/' => '', 'other/' => '' ] )
		);

		$this->assertStatusError( 'importdump-archive-not-single', $status );
	}

	public function testZipWithUnsafeName(): void {
		$xml = self::dump( self::page( 1, [ 10 => 0 ] ) );
		$status = $this->newProcessor()->process( $this->newZip( [ '../dump.xml' => $xml ] ) );
		$this->assertStatusError( 'importdump-archive-unsafe', $status );
	}

	public function testZipWithNestedArchive(): void {
		$inner = $this->newZip( [ 'dump.xml' => self::dump( self::page( 1, [ 10 => 0 ] ) ) ] );
		$status = $this->newProcessor()->process(
			$this->newZip( [ 'inner.zip' => file_get_contents( $inner ) ] )
		);

		$this->assertStatusError( 'importdump-xml-invalid', $status );
	}

	public function testCorruptZip(): void {
		$status = $this->newProcessor()->process(
			$this->newFile( "PK\x03\x04 this is not a zip file" )
		);

		$this->assertStatusError( 'importdump-archive-unreadable', $status );
	}

	public function testGzip(): void {
		$xml = self::dump( self::page( 1, [ 10 => 0, 11 => 10 ] ) );
		$status = $processor->process( $this->newFile( gzencode( $xml ) ) );
		$this->assertStatusGood( $status );

		$dump = $status->getValue();
		$this->assertTrue( $dump->wasExtracted() );
		$this->assertSame( self::HISTORY_FULL, $dump->history );
		$this->assertSame( $xml, file_get_contents( $dump->path ) );
	}

	public function testGzipWithInvalidXml(): void {
		$status = $this->newProcessor()->process( $this->newFile( gzencode( 'content' ) ) );
		$this->assertStatusError( 'importdump-xml-invalid', $status );
	}

	public function testArchiveExpandingPastTheLimit(): void {
		$xml = self::dump( self::page( 1, [ 10 => 0 ] ) );
		$processor = $this->newProcessor( maxSize: strlen( $xml ) - 1 );

		$this->assertStatusError(
			'importdump-archive-too-large',
			$processor->process( $this->newFile( gzencode( $xml ) ) )
		);

		$this->assertStatusError(
			'importdump-archive-too-large',
			$processor->process( $this->newZip( [ 'dump.xml' => $xml ] ) )
		);
	}

	public function testHistoryDetectionOff(): void {
		$xml = self::dump( self::page( 1, [ 12 => 11 ] ) );
		$status = $this->newProcessor( detectHistory: false )->process( $this->newFile( $xml ) );

		$this->assertStatusGood( $status );
		$this->assertNull( $status->getValue()->history );
	}

	public function testHistoryDetectionOffStillRejectsInvalidXml(): void {
		$status = $this->newProcessor( detectHistory: false )->process( $this->newFile( 'content' ) );
		$this->assertStatusError( 'importdump-xml-invalid', $status );
	}
}
