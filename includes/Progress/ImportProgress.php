<?php

declare( strict_types = 1 );

namespace Miraheze\ImportDump\Progress;

use Wikimedia\ObjectCache\BagOStuff;
use function is_array;

/**
 * Where the import job tells the request page how far the current stage has come.
 * Entries expire quickly so that a job that died does not look busy for long.
 */
class ImportProgress {

	private const int TTL = 600;

	public function __construct(
		private readonly BagOStuff $stash,
	) {
	}

	public function set(
		int $requestID,
		string $stage,
		int $percent,
		?int $done,
		?int $total
	): void {
		$this->stash->set(
			$this->getKey( $requestID ),
			[ 'stage' => $stage, 'percent' => $percent, 'done' => $done, 'total' => $total ],
			self::TTL
		);
	}

	/** @return ?array{stage: string, percent: int, done: ?int, total: ?int} */
	public function get( int $requestID ): ?array {
		$value = $this->stash->get( $this->getKey( $requestID ) );
		return is_array( $value ) ? $value : null;
	}

	private function getKey( int $requestID ): string {
		return $this->stash->makeGlobalKey( 'ImportDump', 'import', $requestID );
	}
}
