<?php

declare( strict_types = 1 );

namespace Miraheze\ImportDump\Requirements;

use function array_all;
use function in_array;

/** What the ImportDumpGetTargetState hook reports about the target wiki. */
final readonly class TargetState {

	/**
	 * @param ?string[] $extensions The enabled extensions, or null if they are not known.
	 * @param array<string, bool> $settings Whether each setting is enabled, by name.
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

	/** Whether the rights are known and include every one of $rights. */
	public function hasRights( array $rights ): bool {
		return array_all( $rights, fn ( string $right ): bool => in_array( $right, $this->rights, true ) );
	}
}
