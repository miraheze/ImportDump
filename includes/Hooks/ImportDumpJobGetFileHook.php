<?php

namespace Miraheze\ImportDump\Hooks;

use Miraheze\ImportDump\Services\ImportDumpRequestManager;

interface ImportDumpJobGetFileHook {
	/**
	 * @param string &$filePath
	 * @param ImportDumpRequestManager $importDumpRequestManager
	 * @return void
	 */
	public function onImportDumpJobGetFile( &$filePath, $importDumpRequestManager ): void;
}
