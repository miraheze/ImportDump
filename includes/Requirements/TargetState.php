<?php

declare( strict_types = 1 );

namespace Miraheze\ImportDump\Requirements;

use function array_all;
use function array_map;
use function array_values;
use function in_array;
use function is_array;
use function strval;

/** What the ImportDumpGetTargetState hook reports about the target wiki. */
final readonly class TargetState {

	/** The name that the file extensions of the target wiki have among its settings. */
	public const string FILE_EXTENSIONS_SETTING = 'fileExtensions';

	/**
	 * @param ?string[] $extensions The enabled extensions, or null if they are not known.
	 * @param array<string, bool|list<string>> $settings Whether each setting is enabled, by name,
	 *  and the 'fileExtensions' that the target wiki accepts.
	 * @param string[] $rights The rights that the requester has on the target wiki.
	 */
	public function __construct(
		public ?array $extensions,
		public array $settings,
		public array $rights,
	) {
	}

	/** For a target wiki that does not exist, or that the hook knows nothing about. */
	public static function empty(): self {
		return new self( null, [], [] );
	}

	/** @return ?list<string> The file extensions that the target wiki accepts, or null if not known. */
	public function getFileExtensions(): ?array {
		$extensions = $this->settings[self::FILE_EXTENSIONS_SETTING] ?? null;
		return is_array( $extensions ) ? array_values( array_map( strval( ... ), $extensions ) ) : null;
	}

	/** Whether the rights are known and include every one of $rights. */
	public function hasRights( array $rights ): bool {
		return array_all( $rights, fn ( string $right ): bool => in_array( $right, $this->rights, true ) );
	}
}
