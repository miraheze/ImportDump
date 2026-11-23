<?php

declare( strict_types = 1 );

namespace Miraheze\ImportDump\Progress;

use Wikimedia\ObjectCache\BagOStuff;
use function is_string;
use function mb_strcut;
use function strlen;

/**
 * What the script of an import printed, so that handlers can follow it from the request page.
 * It is only kept for a while after the last line, which is long enough to read after a job ended.
 */
class ImportOutput {

	private const int TTL = 86400;

	/** The most that is kept, in bytes. The oldest output is dropped first. */
	private const int MAX_LENGTH = 30000;

	public function __construct(
		private readonly BagOStuff $stash,
	) {
	}

	public function append( int $requestID, string $text ): void {
		if ( $text === '' ) {
			return;
		}

		$value = ( $this->get( $requestID ) ?? '' ) . $text;
		if ( strlen( $value ) > self::MAX_LENGTH ) {
			$value = mb_strcut( $value, strlen( $value ) - self::MAX_LENGTH );
		}

		$this->stash->set( $this->getKey( $requestID ), $value, self::TTL );
	}

	public function get( int $requestID ): ?string {
		$value = $this->stash->get( $this->getKey( $requestID ) );
		return is_string( $value ) ? $value : null;
	}

	public function clear( int $requestID ): void {
		$this->stash->delete( $this->getKey( $requestID ) );
	}

	private function getKey( int $requestID ): string {
		return $this->stash->makeGlobalKey( 'ImportDump', 'output', $requestID );
	}
}
