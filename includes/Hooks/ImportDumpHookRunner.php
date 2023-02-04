<?php

namespace Miraheze\ImportDump\Hooks;

use MediaWiki\HookContainer\HookContainer;

class ImportDumpHookRunner implements
	SpecialRequestImportDumpModifyFormFieldsHook
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
	public function onSpecialRequestImportDumpModifyFormFields( $user, $formDescriptor ): void {
		$this->container->run(
			'SpecialRequestImportDumpModifyFormFields',
			[ $user, $formDescriptor ]
		);
	}
}
