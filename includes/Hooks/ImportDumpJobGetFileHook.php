<?php

namespace Miraheze\ImportDump\Hooks;

use Miraheze\ImportDump\RequestManager;

interface ImportDumpJobGetFileHook {

	/**
	 * @param string &$filePath
	 * @param RequestManager $requestManager
	 * @return void
	 */
	public function onImportDumpJobGetFile(
		string &$filePath,
		RequestManager $requestManager
	): void;
}
