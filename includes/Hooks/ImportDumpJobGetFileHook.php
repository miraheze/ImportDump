<?php

namespace Miraheze\ImportDump\Hooks;

use Miraheze\ImportDump\ImportDumpRequestManager;

interface ImportDumpJobGetFileHook {
	/**
	 * @param string &$filePath
	 * @param ImportDumpRequestManager $importDumpRequestManager
	 * @return void
	 */
	public function onImportDumpJobGetFile( &$filePath, $importDumpRequestManager ): void;
}
