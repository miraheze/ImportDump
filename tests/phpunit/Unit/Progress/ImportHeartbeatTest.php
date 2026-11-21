<?php

declare( strict_types = 1 );

namespace Miraheze\ImportDump\Tests\Unit\Progress;

use MediaWikiUnitTestCase;
use Miraheze\ImportDump\Progress\ImportHeartbeat;
use Wikimedia\ObjectCache\HashBagOStuff;

/**
 * @covers \Miraheze\ImportDump\Progress\ImportHeartbeat
 */
class ImportHeartbeatTest extends MediaWikiUnitTestCase {

	public function testBeatAndClear(): void {
		$heartbeat = new ImportHeartbeat( new HashBagOStuff() );
		$this->assertFalse( $heartbeat->isAlive( 1 ) );

		$heartbeat->beat( 1, 3600 );
		$this->assertTrue( $heartbeat->isAlive( 1 ) );
		$this->assertFalse( $heartbeat->isAlive( 2 ) );

		$heartbeat->clear( 1 );
		$this->assertFalse( $heartbeat->isAlive( 1 ) );
	}
}
