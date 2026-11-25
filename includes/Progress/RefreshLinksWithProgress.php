<?php

declare( strict_types = 1 );

namespace Miraheze\ImportDump\Progress;

use Closure;
use RefreshLinks;
use function preg_match;

class RefreshLinksWithProgress extends RefreshLinks {

	/** @var ?Closure( int ): void */
	private ?Closure $onPages = null;

	/** @param Closure( int ): void $onPages Called with how many pages this run has refreshed so far. */
	public function setPagesCallback( Closure $onPages ): void {
		$this->onPages = $onPages;
	}

	/** @inheritDoc */
	protected function output( $out, $channel = null ): void {
		parent::output( $out, $channel );

		// The script prints how many pages it has done after every hundred.
		if ( $this->onPages && preg_match( '/^(\d+)\n?$/', (string)$out, $matches ) ) {
			( $this->onPages )( (int)$matches[1] );
		}
	}
}
