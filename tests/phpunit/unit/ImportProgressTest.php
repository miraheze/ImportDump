<?php

namespace Miraheze\ImportDump\Tests\Unit;

use MediaWikiUnitTestCase;
use Miraheze\ImportDump\ImportProgress;
use Wikimedia\ObjectCache\HashBagOStuff;

/**
 * @covers \Miraheze\ImportDump\ImportProgress
 */
class ImportProgressTest extends MediaWikiUnitTestCase {

	public function testSetAndGet(): void {
		$progress = new ImportProgress( new HashBagOStuff() );
		$this->assertNull( $progress->get( 1 ) );

		$progress->set( 1, 'import', 25 );
		$this->assertSame( [ 'stage' => 'import', 'percent' => 25 ], $progress->get( 1 ) );
		$this->assertNull( $progress->get( 2 ) );

		$progress->set( 1, 'refreshlinks', 80 );
		$this->assertSame( [ 'stage' => 'refreshlinks', 'percent' => 80 ], $progress->get( 1 ) );
	}
}
