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
	 * @param int $offset How many of the stored files to leave out first, in the order the storage lists them.
	 * @param int $limit How many files to fetch from there. 0 means all of the files of the request.
	 * @return void This hook must not abort, it must return no value.
	 */
	public function onImportImagesJobGetFiles(
		string $directory,
		RequestManager $requestManager,
		int $offset,
		int $limit
	): void;
}
