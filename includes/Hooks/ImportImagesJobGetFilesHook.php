<?php

declare( strict_types = 1 );

namespace Miraheze\ImportDump\Hooks;

use Miraheze\ImportDump\Request\RequestManager;

interface ImportImagesJobGetFilesHook {

	/**
	 * Called before the images of a request are imported, so that a handler can fetch the stored files.
	 *
	 * @param string $directory An empty local directory, which the handler fills with the stored files.
	 * @param RequestManager $requestManager
	 * @param list<string> $names The object names of the files that are wanted, as the backend lists them.
	 * The handler fills the directory with only these. An empty list means all of the files of the request.
	 * @return void This hook must not abort, it must return no value.
	 */
	public function onImportImagesJobGetFiles(
		string $directory,
		RequestManager $requestManager,
		array $names
	): void;
}
