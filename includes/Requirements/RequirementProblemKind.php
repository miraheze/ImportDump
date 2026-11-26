<?php

declare( strict_types = 1 );

namespace Miraheze\ImportDump\Requirements;

enum RequirementProblemKind: string {

	/** The extensions of the target wiki are not known. */
	case Unknown = 'unknown';

	/** The target wiki does not have the extension. */
	case Missing = 'missing';

	/** The target wiki has the extension, but it is not known if the setting is enabled. */
	case SettingUnknown = 'setting-unknown';

	/** The target wiki has the extension, but not the setting that it needs. */
	case Setting = 'setting';

	/** Whether this is something that is certainly wrong, and not only unknown. */
	public function isError(): bool {
		return $this === self::Missing || $this === self::Setting;
	}

	/** The message key that explains this kind of problem. */
	public function getMessageKey(): string {
		return 'requestimport-requirement-' . $this->value;
	}
}
