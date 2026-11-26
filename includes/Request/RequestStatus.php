<?php

declare( strict_types = 1 );

namespace Miraheze\ImportDump\Request;

enum RequestStatus: string {

	case Complete = 'complete';
	case Declined = 'declined';
	case Failed = 'failed';
	case InProgress = 'inprogress';
	case Partial = 'partial';
	case Pending = 'pending';
	case Starting = 'starting';
	case Verifying = 'verifying';

	/** The message key of the name of this status. */
	public function getLabelKey(): string {
		return 'requestimport-label-' . $this->value;
	}

	/** Whether the import is running or about to run. */
	public function isRunning(): bool {
		return $this === self::Starting || $this === self::InProgress;
	}
}
