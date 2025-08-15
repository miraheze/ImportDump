<?php

namespace Miraheze\ImportDump\Hooks;

use Miraheze\ImportDump\ImportDumpRequestManager;

interface ImportDumpJobGetFileHook {

	/**
	 * @param string &$filePath
	 * @param ImportDumpRequestManager $requestManager
	 * @return void
	 */
	public function onImportDumpJobGetFile(
		string &$filePath,
		ImportDumpRequestManager $requestManager
	): void;
}
