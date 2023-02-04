<?php

namespace Miraheze\ImportDump\Hooks;

use User;

interface SpecialRequestImportDumpModifyFormFieldsHook {
	/**
	 * @param User $user Current user making the request
	 * @param array &$formDescriptor Current HTMLForm field descriptors
	 * @return void
	 */
	public function onSpecialRequestImportDumpModifyFormFields( $user, &$formDescriptor ): void;
}
