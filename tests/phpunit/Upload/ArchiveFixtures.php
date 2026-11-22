<?php

declare( strict_types = 1 );

// @phan-file-suppress PhanUndeclaredMethod, PhanPluginUnknownObjectMethodCall

namespace Miraheze\ImportDump\Tests\Upload;

use MediaWiki\Config\ServiceOptions;
use MediaWiki\Utils\ExecutableFinder;
use MediaWikiIntegrationTestCase;
use Miraheze\ImportDump\ConfigNames;
use Miraheze\ImportDump\Upload\ArchiveReader;
use Miraheze\ImportDump\Upload\ExternalBinaries;
use Wikimedia\FileBackend\FSFile\TempFSFileFactory;
use function file_put_contents;
use function is_string;

/**
 * Builds the files that the archive tests read.
 *
 * @require-extends MediaWikiIntegrationTestCase
 */
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

	/** @param array<string, string> $entries Entry name to contents. Names ending in a slash are directories. */
	private function newTar( array $entries ): string {
		return $this->newFile( TarFixture::build( $entries ) );
	}

	/** @param array<string, string|array<string, mixed>> $binaries Format to its program, or to its settings. */
	private function newExternalBinaries( array $binaries ): ExternalBinaries {
		return new ExternalBinaries(
			$this->getServiceContainer()->getShellCommandFactory(),
			new ServiceOptions( ExternalBinaries::CONSTRUCTOR_OPTIONS, [
				ConfigNames::ExternalBinaries => $binaries,
			] )
		);
	}

	/** @param array<string, string|array<string, mixed>> $binaries Format to its program, or to its settings. */
	private function newReader( array $binaries ): ArchiveReader {
		return new ArchiveReader(
			$this->newExternalBinaries( $binaries ),
			new TempFSFileFactory( $this->getNewTempDirectory() )
		);
	}

	/** Finds the program for a format, and skips the test if the server does not have it. */
	private function requireBinary( string $format ): string {
		$names = $format === ExternalBinaries::XZ ? [ 'xz' ] : [ '7zz', '7z', '7za' ];
		$path = ExecutableFinder::findInDefaultPaths( $names );
		if ( !is_string( $path ) ) {
			$this->markTestSkipped( "The program for $format is not installed." );
		}

		return $path;
	}
}
