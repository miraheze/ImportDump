<?php

namespace Miraheze\RequestImport\Hooks;

use Miraheze\RequestImport\ImportDumpRequestManager;

interface ImportDumpJobGetFileHook {
	/**
	 * @param string &$filePath
	 * @param ImportDumpRequestManager $importDumpRequestManager
	 * @return void
	 */
	public function onImportDumpJobGetFile( &$filePath, $importDumpRequestManager ): void;
}
