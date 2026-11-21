<?php

declare( strict_types = 1 );

namespace Miraheze\ImportDump\Progress;

use Wikimedia\ObjectCache\BagOStuff;
use function is_array;

/**
 * Where the verification job tells the request page how far it has come.
 * Entries expire quickly so that a job that died does not look busy for long.
 */
class VerificationProgress {

	private const int TTL = 600;

	public function __construct(
		private readonly BagOStuff $stash,
	) {
	}

	/**
	 * @param int $requestID
	 * @param string $stage
	 * @param ?int $percent The percentage done, or null if it is not known.
	 */
	public function set( int $requestID, string $stage, ?int $percent ): void {
		$this->stash->set(
			$this->getKey( $requestID ),
			[ 'stage' => $stage, 'percent' => $percent ],
			self::TTL
		);
	}

	/** @return ?array{stage: string, percent: ?int} */
	public function get( int $requestID ): ?array {
		$value = $this->stash->get( $this->getKey( $requestID ) );
		return is_array( $value ) ? $value : null;
	}

	public function clear( int $requestID ): void {
		$this->stash->delete( $this->getKey( $requestID ) );
	}

	private function getKey( int $requestID ): string {
		return $this->stash->makeGlobalKey( 'ImportDump', 'verification', $requestID );
	}
}
