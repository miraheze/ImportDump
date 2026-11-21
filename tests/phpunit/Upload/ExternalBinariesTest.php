<?php

declare( strict_types = 1 );

namespace Miraheze\ImportDump\Tests\Upload;

use Generator;
use MediaWikiIntegrationTestCase;
use Miraheze\ImportDump\Upload\ExternalBinaries;
use function get_object_vars;
use const PHP_INT_MAX;

/**
 * @group ImportDump
 * @group medium
 * @covers \Miraheze\ImportDump\Upload\ExternalBinaries
 */
class ExternalBinariesTest extends MediaWikiIntegrationTestCase {

	use ArchiveFixtures;

	private const array DEFAULTS = [ 'memory' => 4294967296, 'time' => 3600, 'dictionary' => 1073741824 ];

	/**
	 * @dataProvider provideSettings
	 * @param array<string, mixed> $config
	 * @param array<string, mixed> $expected
	 */
	public function testGetSettings( array $config, array $expected ): void {
		$this->assertSame(
			$expected,
			get_object_vars( $this->newExternalBinaries( $config )->getSettings( ExternalBinaries::SEVEN_ZIP ) )
		);
	}

	public static function provideSettings(): Generator {
		yield 'nothing set' => [ [], [ 'binary' => '' ] + self::DEFAULTS ];
		yield 'the path alone' => [
			[ '7z' => '/usr/bin/7z' ],
			[ 'binary' => '/usr/bin/7z' ] + self::DEFAULTS,
		];
		yield 'an array without limits' => [
			[ '7z' => [ 'binary' => '/usr/bin/7z' ] ],
			[ 'binary' => '/usr/bin/7z' ] + self::DEFAULTS,
		];
		yield 'some limits' => [
			[ '7z' => [ 'binary' => '/usr/bin/7z', 'limits' => [ 'time' => 60, 'dictionary' => 4096 ] ] ],
			[ 'binary' => '/usr/bin/7z', 'memory' => 4294967296, 'time' => 60, 'dictionary' => 4096 ],
		];
		yield 'limits that are not usable' => [
			[ '7z' => [
				'binary' => '/usr/bin/7z',
				'limits' => [ 'memory' => -1, 'time' => '60', 'dictionary' => 0 ],
			] ],
			[ 'binary' => '/usr/bin/7z' ] + self::DEFAULTS,
		];
		yield 'a binary that is not a string' => [
			[ '7z' => [ 'binary' => [ 'x' ] ] ],
			[ 'binary' => '' ] + self::DEFAULTS,
		];
		yield 'settings that are not usable' => [ [ '7z' => 5 ], [ 'binary' => '' ] + self::DEFAULTS ];
	}

	public function testEachFormatHasItsOwnSettings(): void {
		$binaries = $this->newExternalBinaries( [
			'xz' => [ 'binary' => '/usr/bin/xz', 'limits' => [ 'time' => 10 ] ],
			'7z' => '/usr/bin/7z',
		] );

		$this->assertSame( 10, $binaries->getSettings( ExternalBinaries::XZ )->time );
		$this->assertSame( 3600, $binaries->getSettings( ExternalBinaries::SEVEN_ZIP )->time );
		$this->assertTrue( $binaries->isEnabled( ExternalBinaries::XZ ) );
		$this->assertTrue( $binaries->isEnabled( ExternalBinaries::SEVEN_ZIP ) );
	}

	public function testFormatWithOnlyLimitsIsNotEnabled(): void {
		$binaries = $this->newExternalBinaries( [ '7z' => [ 'limits' => [ 'time' => 10 ] ] ] );
		$this->assertFalse( $binaries->isEnabled( ExternalBinaries::SEVEN_ZIP ) );
		$this->assertSame( [], $binaries->getMimeTypes() );
	}

	/** @dataProvider provideMethods */
	public function testParseSevenZipListingMemory( string $method, int $memory ): void {
		$listing = "Path = a.xml\nSize = 5\nAttributes = A -rw-r--r--\nEncrypted = -\nMethod = $method\n";
		$entries = $this->newExternalBinaries( [] )->parseSevenZipListing( $listing );

		$this->assertNotNull( $entries );
		$this->assertSame( $memory, $entries[0]['memory'] );
	}

	public static function provideMethods(): Generator {
		yield 'no method' => [ '', 0 ];
		yield 'a power of two' => [ 'LZMA2:26', 67108864 ];
		yield 'lzma' => [ 'LZMA:24', 16777216 ];
		yield 'with a filter' => [ 'BCJ LZMA2:27', 134217728 ];
		yield 'megabytes' => [ 'LZMA2:96m', 100663296 ];
		yield 'kilobytes' => [ 'LZMA2:512k', 524288 ];
		yield 'gigabytes' => [ 'LZMA2:2g', 2147483648 ];
		yield 'ppmd' => [ 'PPMD:o6:mem26', 67108864 ];
		yield 'methods without a dictionary' => [ 'BZip2', 0 ];
		yield 'copy' => [ 'Copy', 0 ];
		yield 'a size that is far too big' => [ 'LZMA2:200', PHP_INT_MAX ];
		yield 'something unknown' => [ 'Strange:x', 0 ];
	}

	public function testParseSevenZipListingWithoutAMethod(): void {
		$listing = "Path = d\nSize = 0\nAttributes = D drwxr-xr-x\nEncrypted = -\nMethod = \n";
		$entries = $this->newExternalBinaries( [] )->parseSevenZipListing( $listing );

		$this->assertNotNull( $entries );
		$this->assertSame( ExternalBinaries::KIND_DIRECTORY, $entries[0]['kind'] );
		$this->assertSame( 0, $entries[0]['memory'] );
	}
}
