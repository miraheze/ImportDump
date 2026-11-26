<?php

declare( strict_types = 1 );

namespace Miraheze\ImportDump\Request;

enum RequestHistory: string {

	case Full = 'full';
	case Partial = 'partial';
	case Unknown = 'unknown';

	/** The message key of the explanation of this result. */
	public function getInfoKey(): string {
		return 'requestimport-info-history-' . $this->value;
	}
}
