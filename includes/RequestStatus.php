<?php

namespace Miraheze\ImportDump;

interface RequestStatus {

	public const string STATUS_COMPLETE = 'complete';

	public const string STATUS_DECLINED = 'declined';

	public const string STATUS_FAILED = 'failed';

	public const string STATUS_INPROGRESS = 'inprogress';

	public const string STATUS_PARTIAL = 'partial';

	public const string STATUS_PENDING = 'pending';

	public const string STATUS_STARTING = 'starting';
}
