<?php

declare( strict_types = 1 );

namespace Miraheze\ImportDump\Upload;

use Wikimedia\FileBackend\FSFile\TempFSFile;
use function count;

final readonly class ImageResult {

	/**
	 * @param array<string, string> $files The name of each image and the path that it was extracted to.
	 * @param list<TempFSFile> $tempFiles The extracted files, which are deleted when this object is destroyed.
	 * @param list<SkippedFile> $skipped The files that are left out of the import.
	 */
	public function __construct(
		public array $files,
		private array $tempFiles,
		public array $skipped,
	) {
	}

	public function count(): int {
		return count( $this->tempFiles );
	}
}
