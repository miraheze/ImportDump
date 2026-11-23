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

		$output->append( 1, "Importing a.png...done.\n" );
		$output->append( 1, '' );
		$output->append( 1, "Importing b.png...done.\n" );

		$this->assertSame( "Importing a.png...done.\nImporting b.png...done.\n", $output->get( 1 ) );
		$this->assertNull( $output->get( 2 ) );
	}

	public function testOnlyTheEndIsKept(): void {
		$output = new ImportOutput( new HashBagOStuff() );
		$output->append( 1, str_repeat( 'a', 40000 ) );
		$output->append( 1, 'end' );

		$value = (string)$output->get( 1 );
		$this->assertSame( 30000, strlen( $value ) );
		$this->assertStringEndsWith( 'aend', $value );
	}

	public function testClear(): void {
		$output = new ImportOutput( new HashBagOStuff() );
		$output->append( 1, 'text' );
		$output->append( 2, 'other' );

		$output->clear( 1 );

		$this->assertNull( $output->get( 1 ) );
		$this->assertSame( 'other', $output->get( 2 ) );
	}
}
