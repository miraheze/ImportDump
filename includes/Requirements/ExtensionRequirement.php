<?php

declare( strict_types = 1 );

namespace Miraheze\ImportDump\Requirements;

use function array_map;
use function array_values;
use function strval;

/**
 * What a dump needs from the target wiki when it uses a feature of an extension.
 */
final readonly class ExtensionRequirement {

	/**
	 * @param string $name The name of the extension, as ExtensionRegistry knows it. The
	 *  ImportDumpGetTargetState hook lists the enabled extensions by these names.
	 * @param string[] $models Content models that need the extension. A model matches if it
	 *  starts with one of these.
	 * @param string[] $patterns Regular expressions for the text of a page that needs the extension.
	 * @param string[] $settings Names of settings that must be enabled on the target wiki.
	 */
	public function __construct(
		public string $name,
		public array $models,
		public array $patterns,
		public array $settings,
	) {
	}

	/**
	 * @param array<string, array{models?: string[], patterns?: string[], settings?: string[]}> $config
	 * @return array<string, self> The requirements by extension name.
	 */
	public static function fromConfig( array $config ): array {
		$requirements = [];
		foreach ( $config as $name => $data ) {
			$name = (string)$name;
			$requirements[$name] = new self(
				$name,
				array_values( array_map( strval( ... ), $data['models'] ?? [] ) ),
				array_values( array_map( strval( ... ), $data['patterns'] ?? [] ) ),
				array_values( array_map( strval( ... ), $data['settings'] ?? [] ) ),
			);
		}

		return $requirements;
	}
}
