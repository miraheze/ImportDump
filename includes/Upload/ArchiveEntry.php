<?php

declare( strict_types = 1 );

namespace Miraheze\ImportDump\Upload;

/** A regular file inside an archive. */
final readonly class ArchiveEntry {

	/**
	 * @param int $index Position in the archive.
	 * @param string $name Name inside the archive. Never use it as a path without checking it.
	 * @param ?int $size Declared size when extracted, or null if the format does not store it.
	 * @param int $compressedSize
	 */
	public function __construct(
		public int $index,
		public string $name,
		public ?int $size,
		public int $compressedSize,
	) {
	}
}
