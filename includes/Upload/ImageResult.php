<?php

declare( strict_types = 1 );

namespace Miraheze\ImportDump\Upload;

use Wikimedia\FileBackend\FSFile\TempFSFile;
use function count;

final readonly class ImageResult {

	/**
	 * @param array<string, string> $files The name of each image and the path that it was extracted to.
	 * @param list<TempFSFile> $tempFiles The extracted files, which are deleted when this object is destroyed.
	 *  They include the held ones.
	 * @param list<SkippedFile> $skipped The files that are left out of the import.
	 * @param int $size The total size in bytes of the files that are kept.
	 * @param array<string, string> $renamed The new name of each file that had no extension.
	 * @param array<string, string> $held The name of each file that is kept only because its extension is
	 *  not accepted yet, and the path that it was extracted to. They are not part of the files that are kept.
	 */
	public function __construct(
		public array $files,
		private array $tempFiles,
		public array $skipped,
		public int $size,
		public array $renamed,
		public array $held,
	) {
	}

	public function count(): int {
		return count( $this->files );
	}
}
