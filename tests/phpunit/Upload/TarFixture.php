<?php

declare( strict_types = 1 );

namespace Miraheze\ImportDump\Tests\Upload;

use function count_chars;
use function decoct;
use function pack;
use function str_ends_with;
use function str_pad;
use function str_repeat;
use function strlen;
use function substr;
use const STR_PAD_LEFT;

/** Writes TAR files byte by byte, so that the tests do not depend on a tar program. */
final class TarFixture {

	/**
	 * @param array<string, string> $entries Entry name to contents. Names ending in a slash are directories.
	 * @return string The bytes of the TAR.
	 */
	public static function build( array $entries ): string {
		$data = '';
		foreach ( $entries as $name => $contents ) {
			$isDirectory = str_ends_with( $name, '/' );
			$data .= self::entry( $name, $contents, $isDirectory ? '5' : '0' );
		}

		return self::finish( $data );
	}

	public static function entry( string $name, string $contents, string $type ): string {
		return self::header( $name, self::octal( strlen( $contents ), 11 ) . "\0", $type, '' ) .
			self::pad( $contents );
	}

	/** An entry that does not hold a file, such as a link. */
	public static function link( string $name, string $linkName, string $type ): string {
		return self::header( $name, self::octal( 0, 11 ) . "\0", $type, $linkName );
	}

	/** An entry with the size written in the binary form that is used for very large files. */
	public static function entryWithBinarySize( string $name, string $contents ): string {
		$size = "\x80\0\0\0\0\0\0\0" . pack( 'N', strlen( $contents ) );
		return self::header( $name, $size, '0', '' ) . self::pad( $contents );
	}

	/**
	 * @param array<string, string> $records
	 * @return string An extended header with these records, to put before the entry they apply to.
	 */
	public static function pax( array $records ): string {
		$body = '';
		foreach ( $records as $key => $value ) {
			$record = " $key=$value\n";
			$length = strlen( $record ) + 1;
			while ( strlen( $length . $record ) !== $length ) {
				$length = strlen( $length . $record );
			}

			$body .= $length . $record;
		}

		return self::entry( 'PaxHeader', $body, 'x' );
	}

	/** @return string A header with the long name that the entry after it has, in the GNU way. */
	public static function longName( string $name ): string {
		return self::entry( '././@LongLink', $name . "\0", 'L' );
	}

	public static function finish( string $data ): string {
		return $data . str_repeat( "\0", 1024 );
	}

	public static function header( string $name, string $sizeField, string $type, string $linkName ): string {
		$fields = str_pad( substr( $name, 0, 100 ), 100, "\0" ) .
			'0000644' . "\0" .
			'0000000' . "\0" .
			'0000000' . "\0" .
			$sizeField .
			self::octal( 0, 11 ) . "\0" .
			'        ' .
			$type .
			str_pad( $linkName, 100, "\0" ) .
			"ustar\0" . '00' .
			str_repeat( "\0", 32 + 32 + 8 + 8 + 155 + 12 );

		$checksum = 0;
		foreach ( count_chars( $fields, 1 ) as $byte => $count ) {
			$checksum += $byte * $count;
		}

		return substr( $fields, 0, 148 ) . self::octal( $checksum, 6 ) . "\0 " . substr( $fields, 156 );
	}

	private static function octal( int $value, int $width ): string {
		return str_pad( decoct( $value ), $width, '0', STR_PAD_LEFT );
	}

	private static function pad( string $contents ): string {
		return $contents . str_repeat( "\0", ( 512 - strlen( $contents ) % 512 ) % 512 );
	}
}
