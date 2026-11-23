<?php

declare( strict_types = 1 );

namespace Miraheze\ImportDump\Upload;

/** An archive of images that was uploaded in parts, which are joined when it is verified. */
final readonly class UploadedParts {

	/** @param string $fileKey The stash key of the first part, which stands for all of them. */
	public function __construct(
		public string $fileKey,
	) {
	}
}
