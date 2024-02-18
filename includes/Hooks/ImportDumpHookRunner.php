<?php

namespace Miraheze\ImportDump\Hooks;

use MediaWiki\HookContainer\HookContainer;

class ImportDumpHookRunner implements ImportDumpJobGetFileHook {

	/**
	 * @var HookContainer
	 */
	private $container;

	/**
	 * @param HookContainer $container
	 */
	public function __construct( HookContainer $container ) {
		$this->container = $container;
	}

	/** @inheritDoc */
	public function onImportDumpJobGetFile( &$filePath, $importDumpRequestManager ): void {
		$this->container->run(
			'ImportDumpJobGetFile',
			[ &$filePath, $importDumpRequestManager ]
		);
	}
}
