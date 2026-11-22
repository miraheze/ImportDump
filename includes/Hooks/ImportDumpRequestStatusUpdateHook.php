<?php

declare( strict_types = 1 );

namespace Miraheze\ImportDump\Hooks;

use Miraheze\ImportDump\Request\RequestManager;
use Miraheze\ImportDump\Request\RequestStatus;

interface ImportDumpRequestStatusUpdateHook {

	/**
	 * Called after the status of an import request has been changed.
	 * This may run inside a database transaction. Defer any external work.
	 *
	 * @param RequestManager $requestManager
	 * @param RequestStatus $newStatus The new status.
	 * @param RequestStatus $oldStatus The previous status.
	 * @return void This hook must not abort, it must return no value.
	 */
	public function onImportDumpRequestStatusUpdate(
		RequestManager $requestManager,
		RequestStatus $newStatus,
		RequestStatus $oldStatus
	): void;
}
