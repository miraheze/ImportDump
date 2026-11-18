<?php

namespace Miraheze\ImportDump\Tests\Upload;

use ZipArchive;

final class ZipFixture {

	/**
	 * @param array<string, string> $entries Entry name to contents. Names ending in a slash are directories.
	 * @return string The bytes of the ZIP.
	 */
	public static function build( array $entries ): string {
		$path = tempnam( sys_get_temp_dir(), 'importdump' );

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
