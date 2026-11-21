<?php

declare( strict_types = 1 );

namespace Miraheze\ImportDump\Hooks;

use Miraheze\ImportDump\Request\RequestManager;

interface ImportDumpJobAfterImportHook {

	/**
	 * Called after the dump has been imported and the maintenance scripts have run.
	 *
	 * @param string $filePath The path of the file that was imported.
	 * @param RequestManager $requestManager
	 * @return void This hook must not abort, it must return no value.
	 */
	public function onImportDumpJobAfterImport(
		string $filePath,
		RequestManager $requestManager
	): void;
}
