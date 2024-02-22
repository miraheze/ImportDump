<?php

namespace Miraheze\ImportDump\Hooks;

use MediaWiki\HookContainer\HookContainer;

class ImportDumpHookRunner implements
	ImportDumpJobGetFileHook,
	ImportDumpJobAfterImportHook
{

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

	/** @inheritDoc */
	public function onImportDumpJobAfterImport( $filePath, $importDumpRequestManager ): void {
		$this->container->run(
			'ImportDumpJobAfterImport',
			[ $filePath, $importDumpRequestManager ]
		);
	}
}
