<?php

declare( strict_types = 1 );

namespace Miraheze\ImportDump\Tests\Upload;

use MediaWikiIntegrationTestCase;
use Miraheze\ImportDump\Upload\ProgressStream;
use Wikimedia\AtEase\AtEase;
use XMLReader;
use function end;
use function file_get_contents;
use function file_put_contents;
use function sort;
use function str_repeat;
use const LIBXML_NONET;

/**
 * @group ImportDump
 * @group medium
 * @covers \Miraheze\ImportDump\Upload\ProgressStream
 */
class ProgressStreamTest extends MediaWikiIntegrationTestCase {

	private function newFile( string $contents ): string {
		$path = $this->getNewTempFile();
		file_put_contents( $path, $contents );
		return $path;
	}

	public function testReadsFileAndReportsProgress(): void {
		$contents = str_repeat( 'content ', 5000 );
		$percentages = [];

		$url = ProgressStream::url(
			$this->newFile( $contents ),
			static function ( int $percent ) use ( &$percentages ): void {
				$percentages[] = $percent;
			}
		);

		$this->assertSame( $contents, file_get_contents( $url ) );
		$this->assertSame( 100, end( $percentages ) );

		$sorted = $percentages;
		sort( $sorted );
		$this->assertSame( $sorted, $percentages );
	}

	public function testXmlReaderCanOpenIt(): void {
		$percentages = [];
		$url = ProgressStream::url(
			$this->newFile( '<root><child>text</child></root>' ),
			static function ( int $percent ) use ( &$percentages ): void {
				$percentages[] = $percent;
			}
		);

		$reader = new XMLReader();
		$this->assertTrue( $reader->open( $url, null, LIBXML_NONET ) );

		$elements = 0;
		while ( $reader->read() ) {
			if ( $reader->nodeType === XMLReader::ELEMENT ) {
				$elements++;
			}
		}

		$reader->close();

		$this->assertSame( 2, $elements );
		$this->assertSame( 100, end( $percentages ) );
	}

	public function testEmptyFile(): void {
		// @phan-suppress-next-line PhanUnusedPublicNoOverrideMethodParameter
		$url = ProgressStream::url( $this->newFile( '' ), static fn ( int $percent ) => null );

		$this->assertSame( '', file_get_contents( $url ) );
	}

	public function testUnknownUrlCannotBeOpened(): void {
		// @phan-suppress-next-line PhanUnusedPublicNoOverrideMethodParameter
		ProgressStream::url( $this->newFile( 'content' ), static fn ( int $percent ) => null );

		$this->assertFalse( AtEase::quietCall( 'file_get_contents', 'importdump-progress://unknown' ) );
	}

	public function testReleasedUrlCannotBeOpened(): void {
		// @phan-suppress-next-line PhanUnusedPublicNoOverrideMethodParameter
		$url = ProgressStream::url( $this->newFile( 'content' ), static fn ( int $percent ) => null );

		ProgressStream::release( $url );
		$this->assertFalse( AtEase::quietCall( 'file_get_contents', $url ) );
	}

	public function testUrlCannotBeOpenedTwice(): void {
		// @phan-suppress-next-line PhanUnusedPublicNoOverrideMethodParameter
		$url = ProgressStream::url( $this->newFile( 'content' ), static fn ( int $percent ) => null );

		$this->assertSame( 'content', file_get_contents( $url ) );
		$this->assertFalse( AtEase::quietCall( 'file_get_contents', $url ) );
	}
}
