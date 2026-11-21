<?php

namespace Miraheze\ImportDump\Hooks;

interface ImportDumpGetTargetStateHook {

	/**
	 * Reports what the target wiki has enabled, so that handlers are warned when a dump needs
	 * an extension or a setting that the wiki does not have. Leave $extensions as null if it is
	 * not known, in which case handlers are only told to check it themselves.
	 *
	 * @param string $target The database name of the target wiki.
	 * @param ?string[] &$extensions The names of the enabled extensions, as ExtensionRegistry knows
	 *  them, matching the names in $wgImportDumpExtensionRequirements.
	 * @param array<string, bool> &$settings Whether each setting that a requirement names is
	 *  enabled, by the name of the setting. Settings that are left out are treated as unknown.
	 * @return void
	 */
	public function onImportDumpGetTargetState(
		string $target,
		?array &$extensions,
		array &$settings
	): void;
}
