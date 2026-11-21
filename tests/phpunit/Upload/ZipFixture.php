<?php

declare( strict_types = 1 );

namespace Miraheze\ImportDump\Tests\Upload;

use RuntimeException;
use ZipArchive;
use function file_get_contents;
use function str_ends_with;
use function sys_get_temp_dir;
use function tempnam;
use function unlink;

final class ZipFixture {

	/**
	 * @param array<string, string> $entries Entry name to contents. Names ending in a slash are directories.
	 * @return string The bytes of the ZIP.
	 */
	public static function build( array $entries ): string {
		$path = tempnam( sys_get_temp_dir(), 'importdump' );
		if ( $path === false ) {
			throw new RuntimeException( 'Could not create a temporary file.' );
		}

		$zip = new ZipArchive();
		$zip->open( $path, ZipArchive::CREATE | ZipArchive::OVERWRITE );
		foreach ( $entries as $name => $contents ) {
			if ( str_ends_with( $name, '/' ) ) {
				$zip->addEmptyDir( $name );
				continue;
			}

			$zip->addFromString( $name, $contents );
		}

		$zip->close();

		$bytes = (string)file_get_contents( $path );
		unlink( $path );
		return $bytes;
	}
}
