<?php

namespace Miraheze\ImportDump\Hooks;

use Miraheze\ImportDump\Request\RequestManager;

interface ImportDumpJobAfterImportHook {

	/**
	 * @param string $filePath
	 * @param RequestManager $requestManager
	 * @return void
	 */
	public function onImportDumpJobAfterImport(
		string $filePath,
		RequestManager $requestManager
	): void;
}
