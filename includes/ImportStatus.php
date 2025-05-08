<?php

namespace Miraheze\ImportDump;

enum ImportStatus: string {
	case COMPLETE = 'complete';
	case DECLINED = 'declined';
	case FAILED = 'failed';
	case IN_PROGRESS = 'inprogress';
	case PENDING = 'pending';
	case STARTING = 'starting';
}
