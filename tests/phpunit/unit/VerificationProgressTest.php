<?php

namespace Miraheze\ImportDump\Tests\Unit;

use MediaWikiUnitTestCase;
use Miraheze\ImportDump\VerificationProgress;
use Wikimedia\ObjectCache\HashBagOStuff;

/**
 * @covers \Miraheze\ImportDump\VerificationProgress
 */
class VerificationProgressTest extends MediaWikiUnitTestCase {

	public function testSetGetAndClear(): void {
		$progress = new VerificationProgress( new HashBagOStuff() );
		$this->assertNull( $progress->get( 1 ) );

		$progress->set( 1, 'extracting', 25 );
		$this->assertSame( [ 'stage' => 'extracting', 'percent' => 25 ], $progress->get( 1 ) );
		$this->assertNull( $progress->get( 2 ) );

		$progress->set( 1, 'checking', null );
		$this->assertSame( [ 'stage' => 'checking', 'percent' => null ], $progress->get( 1 ) );

		$progress->clear( 1 );
		$this->assertNull( $progress->get( 1 ) );
	}
}
