<?php

namespace Miraheze\ImportDump\Hooks;

use Miraheze\ImportDump\ImportDumpRequestManager;

interface ImportDumpJobAfterImportHook {
	/**
	 * @param string $filePath
	 * @param ImportDumpRequestManager $importDumpRequestManager
	 * @return void
	 */
	public function onImportDumpJobAfterImport( $filePath, $importDumpRequestManager ): void;
}
