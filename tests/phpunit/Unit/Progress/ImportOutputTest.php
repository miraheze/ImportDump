<?php

declare( strict_types = 1 );

namespace Miraheze\ImportDump\Tests\Unit\Progress;

use MediaWikiUnitTestCase;
use Miraheze\ImportDump\Progress\ImportOutput;
use Wikimedia\ObjectCache\HashBagOStuff;
use function str_repeat;
use function strlen;

/**
 * @covers \Miraheze\ImportDump\Progress\ImportOutput
 */
class ImportOutputTest extends MediaWikiUnitTestCase {

	public function testAppendAndGet(): void {
		$output = new ImportOutput( new HashBagOStuff() );
		$this->assertNull( $output->get( 1 ) );

		$output->append( 1, 0, "Importing a.png...done.\n" );
		$output->append( 1, 0, '' );
		$output->append( 1, 0, "Importing b.png...done.\n" );

		$this->assertSame(
			[ 'part' => 0, 'text' => "Importing a.png...done.\nImporting b.png...done.\n" ],
			$output->get( 1 )
		);

		$this->assertNull( $output->get( 2 ) );
	}

	public function testANewPartStartsOver(): void {
		$output = new ImportOutput( new HashBagOStuff() );
		$output->append( 1, 0, 'first' );
		$output->append( 1, 1, 'second' );

		$this->assertSame( [ 'part' => 1, 'text' => 'second' ], $output->get( 1 ) );
	}

	public function testOnlyTheEndIsKept(): void {
		$output = new ImportOutput( new HashBagOStuff() );
		$output->append( 1, 0, str_repeat( 'a', 40000 ) );
		$output->append( 1, 0, 'end' );

		$value = $output->get( 1 );
		$this->assertNotNull( $value );
		$this->assertSame( 30000, strlen( $value['text'] ) );
		$this->assertStringEndsWith( 'aend', $value['text'] );
	}

	public function testClear(): void {
		$output = new ImportOutput( new HashBagOStuff() );
		$output->append( 1, 0, 'text' );
		$output->append( 2, 0, 'other' );

		$output->clear( 1 );

		$this->assertNull( $output->get( 1 ) );
		$this->assertSame( [ 'part' => 0, 'text' => 'other' ], $output->get( 2 ) );
	}
}
