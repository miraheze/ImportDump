<?php

declare( strict_types = 1 );

namespace Miraheze\ImportDump\Hooks;

use Miraheze\ImportDump\Request\RequestManager;

interface ImportDumpRequestStatusUpdateHook {

	/**
	 * Called after the status of an import request has been changed.
	 * This may run inside a database transaction. Defer any external work.
	 *
	 * @param RequestManager $requestManager
	 * @param string $newStatus The value of the new RequestStatus.
	 * @param string $oldStatus The value of the old RequestStatus.
	 * @return void
	 */
	public function onImportDumpRequestStatusUpdate(
		RequestManager $requestManager,
		string $newStatus,
		string $oldStatus
	): void;
}
