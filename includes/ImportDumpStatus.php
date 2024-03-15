<?php

namespace Miraheze\ImportDump;

interface ImportDumpStatus {

	public const STATUS_COMPLETE = 'complete';

	public const STATUS_DECLINED = 'declined';

	public const STATUS_FAILED = 'failed';

	public const STATUS_INPROGRESS = 'inprogress';

	public const STATUS_PENDING = 'pending';

	public const STATUS_STARTING = 'starting';
}
