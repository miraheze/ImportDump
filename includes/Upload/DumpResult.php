<?php

declare( strict_types = 1 );

namespace Miraheze\ImportDump\Upload;

use Miraheze\ImportDump\Request\RequestHistory;
use Wikimedia\FileBackend\FSFile\TempFSFile;

final readonly class DumpResult {

	/**
	 * @param string $path The XML file that will be imported.
	 * @param ?RequestHistory $history What the check found, or null if history detection is off.
	 * @param array<string, bool> $requirements The extensions that the dump needs, such as
	 *  WikibaseRepository, going by the latest revision of each page. The value is whether it is
	 *  certain that it needs it, which is only the case for a content model.
	 * @param ?TempFSFile $tempFile The file that was extracted from an archive, if there was one.
	 *  It is deleted when this object is destroyed.
	 */
	public function __construct(
		public string $path,
		public ?RequestHistory $history,
		public array $requirements,
		private ?TempFSFile $tempFile,
	) {
	}

	public function wasExtracted(): bool {
		return $this->tempFile !== null;
	}
}
