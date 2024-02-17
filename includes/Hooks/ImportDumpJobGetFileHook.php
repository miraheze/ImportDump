<?php

namespace Miraheze\ImportDump\Hooks;

interface ImportDumpJobGetFileHook {
	/**
	 * @param string &$filePath
	 * @return void
	 */
	public function onImportDumpJobGetFile( &$filePath ): void;
}
