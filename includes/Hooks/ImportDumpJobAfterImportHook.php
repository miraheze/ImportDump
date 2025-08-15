<?php

namespace Miraheze\ImportDump\Hooks;

use Miraheze\ImportDump\ImportDumpRequestManager;

interface ImportDumpJobAfterImportHook {
	/**
	 * @param string $filePath
	 * @param ImportDumpRequestManager $requestManager
	 * @return void
	 */
	public function onImportDumpJobAfterImport( $filePath, $requestManager ): void;
}
