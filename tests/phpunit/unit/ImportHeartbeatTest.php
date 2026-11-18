<?php

namespace Miraheze\ImportDump\Tests\Unit;

use MediaWikiUnitTestCase;
use Miraheze\ImportDump\ImportHeartbeat;
use Wikimedia\ObjectCache\HashBagOStuff;

/**
 * @covers \Miraheze\ImportDump\ImportHeartbeat
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
