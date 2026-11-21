<?php

declare( strict_types = 1 );

namespace Miraheze\ImportDump\Upload;

/** The program that unpacks a format, and what it may use. */
final readonly class ExternalBinary {

	/**
	 * @param string $binary The path of the program, or empty if the wiki has none for the format.
	 * @param int $memory The memory of the whole sandbox, in bytes.
	 * @param int $time The seconds that the program may run.
	 * @param int $dictionary The most memory in bytes that the unpacking itself may need.
	 */
	public function __construct(
		public string $binary,
		public int $memory,
		public int $time,
		public int $dictionary,
	) {
	}
}
