<?php

declare( strict_types = 1 );

namespace Miraheze\ImportDump\Progress;

use Wikimedia\ObjectCache\BagOStuff;
use function is_array;
use function mb_strcut;
use function strlen;

/**
 * What the script of an import is printing, so that handlers can follow it from the request page.
 * It holds one part of the output at a time, which is what the job that is running has printed so far.
 * The parts that are done are stored as files. See RequestManager::storeOutputPart().
 */
class ImportOutput {

	private const int TTL = 86400;

	/** The most that is kept, in bytes. The oldest output is dropped first. */
	private const int MAX_LENGTH = 30000;

	public function __construct(
		private readonly BagOStuff $stash,
	) {
	}

	public function append( int $requestID, int $part, string $text ): void {
		if ( $text === '' ) {
			return;
		}

		$current = $this->get( $requestID );
		$value = ( $current !== null && $current['part'] === $part ? $current['text'] : '' ) . $text;
		if ( strlen( $value ) > self::MAX_LENGTH ) {
			$value = mb_strcut( $value, strlen( $value ) - self::MAX_LENGTH );
		}

		$this->stash->set( $this->getKey( $requestID ), [ 'part' => $part, 'text' => $value ], self::TTL );
	}

	/** @return ?array{part: int, text: string} */
	public function get( int $requestID ): ?array {
		$value = $this->stash->get( $this->getKey( $requestID ) );
		return is_array( $value ) ? $value : null;
	}

	public function clear( int $requestID ): void {
		$this->stash->delete( $this->getKey( $requestID ) );
	}

	private function getKey( int $requestID ): string {
		return $this->stash->makeGlobalKey( 'ImportDump', 'output', $requestID );
	}
}
