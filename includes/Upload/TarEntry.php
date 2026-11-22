<?php

declare( strict_types = 1 );

namespace Miraheze\ImportDump\Upload;

/** A header read from a tar stream. The name is exactly what the archive says and is not checked. */
final readonly class TarEntry {

	/**
	 * @param int $index Position among the entries that are returned, starting at zero.
	 * @param string $name
	 * @param int $size Bytes of data after the header.
	 * @param bool $isDirectory
	 */
	public function __construct(
		public int $index,
		public string $name,
		public int $size,
		public bool $isDirectory,
	) {
	}
}
