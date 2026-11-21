<?php

namespace Miraheze\ImportDump\Hooks;

use Miraheze\ImportDump\RequestManager;
use Miraheze\ImportDump\RequestStatus;

interface ImportDumpRequestStatusUpdateHook {

	/**
	 * Called after the status of an import request has been changed.
	 * This may run inside a database transaction. Defer any external work.
	 *
	 * @param RequestManager $requestManager
	 * @param RequestStatus $newStatus
	 * @param RequestStatus $oldStatus
	 * @return void
	 */
	public function onImportDumpRequestStatusUpdate(
		RequestManager $requestManager,
		RequestStatus $newStatus,
		RequestStatus $oldStatus
	): void;
}
