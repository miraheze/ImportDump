<?php

declare( strict_types = 1 );

namespace Miraheze\ImportDump\Progress;

use Wikimedia\ObjectCache\BagOStuff;
use function time;

class ImportHeartbeat {

	public function __construct(
		private readonly BagOStuff $stash,
	) {
	}

	public function beat( int $requestID, int $ttl ): void {
		$this->stash->set( $this->getKey( $requestID ), time(), $ttl );
	}

	public function isAlive( int $requestID ): bool {
		return $this->stash->get( $this->getKey( $requestID ) ) !== false;
	}

	public function clear( int $requestID ): void {
		$this->stash->delete( $this->getKey( $requestID ) );
	}

	private function getKey( int $requestID ): string {
		return $this->stash->makeGlobalKey( 'ImportDump', 'heartbeat', $requestID );
	}
}
