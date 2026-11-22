<?php

declare( strict_types = 1 );

namespace Miraheze\ImportDump\Requirements;

final readonly class RequirementProblem {

	/**
	 * @param string $name The name of the extension that the dump needs.
	 * @param RequirementProblemKind $kind What is wrong or not known.
	 * @param string $setting The setting that is the problem, or empty if it does not apply.
	 * @param bool $blocking Whether the import cannot start until this is solved.
	 */
	public function __construct(
		public string $name,
		public RequirementProblemKind $kind,
		public string $setting,
		public bool $blocking,
	) {
	}
}
