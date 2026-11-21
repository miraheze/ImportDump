<?php

declare( strict_types = 1 );

namespace Miraheze\ImportDump\Tests\Unit\Progress;

use MediaWikiUnitTestCase;
use Miraheze\ImportDump\Progress\ImportProgress;
use Wikimedia\ObjectCache\HashBagOStuff;

/**
 * @covers \Miraheze\ImportDump\Progress\ImportProgress
 */
class ImportProgressTest extends MediaWikiUnitTestCase {

	public function testSetAndGet(): void {
		$progress = new ImportProgress( new HashBagOStuff() );
		$this->assertNull( $progress->get( 1 ) );

		$progress->set( 1, 'import', 25, done: null, total: null );
		$this->assertSame(
			[ 'stage' => 'import', 'percent' => 25, 'done' => null, 'total' => null ],
			$progress->get( 1 )
		);
		$this->assertNull( $progress->get( 2 ) );

		$progress->set( 1, 'import', 42, 1200, 2850 );
		$this->assertSame(
			[ 'stage' => 'import', 'percent' => 42, 'done' => 1200, 'total' => 2850 ],
			$progress->get( 1 )
		);

		$progress->set( 1, 'refreshlinks', 80, done: null, total: null );
		$this->assertSame(
			[ 'stage' => 'refreshlinks', 'percent' => 80, 'done' => null, 'total' => null ],
			$progress->get( 1 )
		);
	}
}
