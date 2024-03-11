<?php

namespace Miraheze\RequestImport\Hooks;

use Miraheze\RequestImport\ImportDumpRequestManager;

interface ImportDumpJobAfterImportHook {
	/**
	 * @param string $filePath
	 * @param ImportDumpRequestManager $importDumpRequestManager
	 * @return void
	 */
	public function onImportDumpJobAfterImport( $filePath, $importDumpRequestManager ): void;
}
