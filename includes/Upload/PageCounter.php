<?php

declare( strict_types = 1 );

namespace Miraheze\ImportDump\Upload;

use function fclose;
use function feof;
use function fopen;
use function fread;
use function is_file;
use function is_readable;
use function preg_match_all;
use function substr;

/** Counts the pages in an XML export without loading the whole file. */
class PageCounter {

	private const int CHUNK_SIZE = 1048576;

	/** A match is six bytes long, so five bytes can never hold a whole one. */
	private const int CARRY_SIZE = 5;

	/** @return ?int The number of pages, or null if the file could not be read. */
	public static function count( string $path ): ?int {
		if ( !is_file( $path ) || !is_readable( $path ) ) {
			return null;
		}

		$handle = fopen( $path, 'rb' );
		if ( $handle === false ) {
			return null;
		}

		$count = 0;
		$carry = '';

		while ( !feof( $handle ) ) {
			$chunk = fread( $handle, self::CHUNK_SIZE );
			if ( $chunk === false ) {
				fclose( $handle );
				return null;
			}

			$buffer = $carry . $chunk;
			$count += (int)preg_match_all( '/<page[\s>]/', $buffer );
			$carry = substr( $buffer, -self::CARRY_SIZE );
		}

		fclose( $handle );
		return $count;
	}
}
