<?php

namespace Miraheze\ImportDump\Hooks;

use Miraheze\ImportDump\RequestManager;

interface ImportDumpRequestStatusUpdateHook {

	/**
	 * Called after the status of an import request has been changed.
	 * This may run inside a database transaction. Defer any external work.
	 *
	 * @param RequestManager $requestManager
	 * @param string $newStatus
	 * @param string $oldStatus
	 * @return void
	 */
	public function onImportDumpRequestStatusUpdate(
		RequestManager $requestManager,
		string $newStatus,
		string $oldStatus
	): void;
}
