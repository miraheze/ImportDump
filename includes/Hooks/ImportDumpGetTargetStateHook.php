<?php

declare( strict_types = 1 );

namespace Miraheze\ImportDump\Hooks;

interface ImportDumpGetTargetStateHook {

	/**
	 * Reports what the target wiki has enabled, so that handlers are warned when a dump needs
	 * an extension or a setting that the wiki does not have. Leave $extensions as null if it is
	 * not known, in which case handlers are only told to check it themselves.
	 *
	 * It also reports the rights that the requester has on the target wiki. Requesters are only
	 * shown what the target wiki is missing if they have the rights that ImportDump needs there,
	 * because it would otherwise reveal how a wiki that they have no access to is set up.
	 *
	 * @param string $target The database name of the target wiki.
	 * @param string[] $requesterGroups The groups that the requester has on the target wiki,
	 *  without implicit ones such as '*' and 'user'. Empty if they have no account there.
	 * @param ?string[] &$extensions The names of the enabled extensions, as ExtensionRegistry knows
	 *  them, matching the names in $wgRequestImportExtensionRequirements.
	 * @param array<string, bool> &$settings Whether each setting that a requirement names is
	 *  enabled, by the name of the setting. Settings that are left out are treated as unknown.
	 * @param string[] &$rights The rights that the requester has on the target wiki, including
	 *  the ones from implicit groups. Leave it empty if they are not known.
	 * @return void
	 */
	public function onImportDumpGetTargetState(
		string $target,
		array $requesterGroups,
		?array &$extensions,
		array &$settings,
		array &$rights
	): void;
}
