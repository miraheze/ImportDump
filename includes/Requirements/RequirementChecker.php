<?php

declare( strict_types = 1 );

namespace Miraheze\ImportDump\Requirements;

use MediaWiki\Config\ServiceOptions;
use Miraheze\ImportDump\ConfigNames;
use function array_filter;
use function array_values;
use function in_array;

class RequirementChecker {

	public const array CONSTRUCTOR_OPTIONS = [
		ConfigNames::ExtensionRequirements,
	];

	public function __construct(
		private readonly ServiceOptions $options,
	) {
		$options->assertRequiredOptions( self::CONSTRUCTOR_OPTIONS );
	}

	/**
	 * Compares what the dump needs to what the target wiki has. A problem blocks the import if the
	 * dump certainly needs the extension and the wiki certainly does not have it or its setting.
	 *
	 * @param array<string, bool> $requirements The extensions that the dump needs, and if it is certain.
	 * @return list<RequirementProblem>
	 */
	public function getProblems( array $requirements, TargetState $state ): array {
		if ( !$requirements ) {
			return [];
		}

		$definitions = ExtensionRequirement::fromConfig(
			(array)$this->options->get( ConfigNames::ExtensionRequirements )
		);

		$problems = [];
		foreach ( $requirements as $name => $certain ) {
			$name = (string)$name;
			if ( $state->extensions === null ) {
				$problems[] = new RequirementProblem( $name, RequirementProblemKind::Unknown, '', false );
				continue;
			}

			if ( !in_array( $name, $state->extensions, true ) ) {
				$problems[] = new RequirementProblem( $name, RequirementProblemKind::Missing, '', $certain );
				continue;
			}

			foreach ( isset( $definitions[$name] ) ? $definitions[$name]->settings : [] as $setting ) {
				if ( !isset( $state->settings[$setting] ) ) {
					$problems[] = new RequirementProblem(
						$name, RequirementProblemKind::SettingUnknown, $setting, false
					);
				} elseif ( !$state->settings[$setting] ) {
					$problems[] = new RequirementProblem(
						$name, RequirementProblemKind::Setting, $setting, $certain
					);
				}
			}
		}

		return $problems;
	}

	/**
	 * @param list<RequirementProblem> $problems
	 * @return list<RequirementProblem>
	 */
	public function getBlocking( array $problems ): array {
		return array_values( array_filter(
			$problems,
			static fn ( RequirementProblem $problem ): bool => $problem->blocking
		) );
	}
}
