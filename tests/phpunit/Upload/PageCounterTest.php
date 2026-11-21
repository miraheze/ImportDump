<?php

declare( strict_types = 1 );

namespace Miraheze\ImportDump\Tests\Upload;

use MediaWikiIntegrationTestCase;
use Miraheze\ImportDump\Upload\PageCounter;
use function file_put_contents;
use function str_repeat;

/**
 * @covers \Miraheze\ImportDump\Upload\PageCounter
 */
class PageCounterTest extends MediaWikiIntegrationTestCase {

	private function write( string $contents ): string {
		$path = $this->getNewTempFile();
		file_put_contents( $path, $contents );
		return $path;
	}

	public function testCountsPages(): void {
		$path = $this->write(
			'<mediawiki><siteinfo><sitename>Test</sitename></siteinfo>' .
			'<page><title>A</title></page><page><title>B</title></page></mediawiki>'
		);

		$this->assertSame( 2, PageCounter::count( $path ) );
	}

	public function testIgnoresOtherElementsThatStartWithPage(): void {
		$path = $this->write( '<mediawiki><pages></pages><pagination/><page><title>A</title></page></mediawiki>' );

		$this->assertSame( 1, PageCounter::count( $path ) );
	}

	public function testCountsNothingInAnEmptyFile(): void {
		$this->assertSame( 0, PageCounter::count( $this->write( '' ) ) );
	}

	/** @dataProvider provideOffsets */
	public function testCountsAPageThatCrossesAChunk( int $offset ): void {
		$chunkSize = 1048576;
		$path = $this->write(
			str_repeat( ' ', $chunkSize - $offset ) . '<page>' . str_repeat( ' ', 10 ) . '<page>'
		);

		$this->assertSame( 2, PageCounter::count( $path ) );
	}

	public static function provideOffsets(): array {
		return [
			'before the first byte' => [ 6 ],
			'after one byte' => [ 5 ],
			'in the middle' => [ 3 ],
			'after five bytes' => [ 1 ],
			'at the very end' => [ 0 ],
		];
	}

	public function testReturnsNullWhenTheFileCannotBeRead(): void {
		$this->assertNull( PageCounter::count( $this->getNewTempFile() . '.missing' ) );
	}
}
