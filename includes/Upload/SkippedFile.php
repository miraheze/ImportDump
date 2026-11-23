<?php

declare( strict_types = 1 );

namespace Miraheze\ImportDump\Upload;

/** An image file of an archive that is left out of the import, and why. */
final readonly class SkippedFile {

	/**
	 * @param string $name The name that the file would have had.
	 * @param string $reason The key of the message that gives the reason.
	 * @param list<string|int|float> $params The parameters of the reason.
	 */
	public function __construct(
		public string $name,
		public string $reason,
		public array $params,
	) {
	}
}
