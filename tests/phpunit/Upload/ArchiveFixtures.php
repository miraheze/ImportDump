<?php

namespace Miraheze\ImportDump\Tests\Upload;

/** Builds the files that the archive tests read. Used by classes that extend MediaWikiIntegrationTestCase. */
trait ArchiveFixtures {

	private function newFile( string $contents ): string {
		$path = $this->getNewTempFile();
		file_put_contents( $path, $contents );
		return $path;
	}

	/** @param array<string, string> $entries Entry name to contents. Names ending in a slash are directories. */
	private function newZip( array $entries ): string {
		return $this->newFile( ZipFixture::build( $entries ) );
	}
}
