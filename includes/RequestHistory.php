<?php

namespace Miraheze\ImportDump;

enum RequestHistory: string {

	case Full = 'full';
	case Partial = 'partial';
	case Unknown = 'unknown';

	/** The message key of the explanation of this result. */
	public function getInfoKey(): string {
		return 'importdump-info-history-' . $this->value;
	}
}
