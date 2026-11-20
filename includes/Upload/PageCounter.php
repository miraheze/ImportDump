<?php

namespace Miraheze\ImportDump\Upload;

/** Counts the pages in an XML export without loading the whole file. */
class PageCounter {

	private const int CHUNK_SIZE = 1048576;

	/** A match is six bytes long, so five bytes can never hold a whole one. */
	private const int CARRY_SIZE = 5;

	/** @return ?int The number of pages, or null if the file could not be read. */
	public static function count( string $path ): ?int {
		$handle = @fopen( $path, 'rb' );
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
