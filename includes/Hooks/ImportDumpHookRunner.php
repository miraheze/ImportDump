<?php

namespace Miraheze\ImportDump\Hooks;

use MediaWiki\HookContainer\HookContainer;

class ImportDumpHookRunner implements
	ImportDumpJobAfterImportHook,
	ImportDumpJobGetFileHook
{

	public function __construct(
		private readonly HookContainer $container
	) {
	}

	/** @inheritDoc */
	public function onImportDumpJobAfterImport( $filePath, $requestManager ): void {
		$this->container->run(
			'ImportDumpJobAfterImport',
			[ $filePath, $requestManager ],
			[ 'abortable' => false ]
		);
	}

	/** @inheritDoc */
	public function onImportDumpJobGetFile( &$filePath, $requestManager ): void {
		$this->container->run(
			'ImportDumpJobGetFile',
			[ &$filePath, $requestManager ],
			[ 'abortable' => false ]
		);
	}
}
