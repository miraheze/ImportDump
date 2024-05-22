<?php

namespace Miraheze\ImportDump\Hooks;

use Miraheze\ImportDump\Services\ImportDumpRequestManager;

interface ImportDumpJobAfterImportHook {
	/**
	 * @param string $filePath
	 * @param ImportDumpRequestManager $importDumpRequestManager
	 * @return void
	 */
	public function onImportDumpJobAfterImport( $filePath, $importDumpRequestManager ): void;
}
