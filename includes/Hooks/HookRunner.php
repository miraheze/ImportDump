<?php

namespace Miraheze\ImportDump\Hooks;

use MediaWiki\HookContainer\HookContainer;
use Miraheze\ImportDump\RequestManager;

class HookRunner implements
	ImportDumpJobAfterImportHook,
	ImportDumpGetTargetStateHook,
	ImportDumpJobGetFileHook,
	ImportDumpRequestStatusUpdateHook
{

	public function __construct(
		private readonly HookContainer $container,
	) {
	}

	/** @inheritDoc */
	public function onImportDumpGetTargetState(
		string $target,
		?array &$extensions,
		array &$settings
	): void {
		$this->container->run(
			'ImportDumpGetTargetState',
			[ $target, &$extensions, &$settings ],
			[ 'abortable' => false ]
		);
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

	/** @inheritDoc */
	public function onImportDumpRequestStatusUpdate(
		RequestManager $requestManager,
		string $newStatus,
		string $oldStatus
	): void {
		$this->container->run(
			'ImportDumpRequestStatusUpdate',
			[ $requestManager, $newStatus, $oldStatus ],
			[ 'abortable' => false ]
		);
	}
}
