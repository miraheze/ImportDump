<?php

declare( strict_types = 1 );

namespace Miraheze\ImportDump\Hooks;

use Miraheze\ImportDump\Request\RequestManager;

interface ImportDumpJobGetFileHook {

	/**
	 * Called before the dump is imported, so that a handler can change where the file is read from.
	 *
	 * @param string &$filePath The path of the file to import. For an images request this is
	 * an empty local directory instead, which the handler fills with the stored files.
	 * @param RequestManager $requestManager
	 * @return void This hook must not abort, it must return no value.
	 */
	public function onImportDumpJobGetFile(
		string &$filePath,
		RequestManager $requestManager
	): void;
}
