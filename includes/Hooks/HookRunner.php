<?php

namespace Miraheze\ImportDump\Hooks;

use MediaWiki\HookContainer\HookContainer;
use Miraheze\ImportDump\RequestManager;

class HookRunner implements
	ImportDumpJobAfterImportHook,
	ImportDumpJobGetFileHook
{

	public function __construct(
		private readonly HookContainer $container
	) {
	}

	/** @inheritDoc */
	public function onImportDumpJobAfterImport(
		string $filePath,
		RequestManager $requestManager
	): void {
		$this->container->run(
			'ImportDumpJobAfterImport',
			[ $filePath, $requestManager ],
			[ 'abortable' => false ]
		);
	}

	/** @inheritDoc */
	public function onImportDumpJobGetFile(
		string &$filePath,
		RequestManager $requestManager
	): void {
		$this->container->run(
			'ImportDumpJobGetFile',
			[ &$filePath, $requestManager ],
			[ 'abortable' => false ]
		);
	}
}
