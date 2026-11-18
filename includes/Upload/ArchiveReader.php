<?php

namespace Miraheze\ImportDump\Upload;

use Closure;
use MediaWiki\Message\Message;
use MediaWiki\Status\Status;
use ZipArchive;

/**
 * Reads ZIP and GZIP archives without trusting what is in them. Entry names are checked and
 * returned but never used to build a path, and every entry is streamed into a destination
 * chosen by the caller, within a size limit.
 */
final class ArchiveReader {

	public const string FORMAT_ZIP = 'zip';

	public const string FORMAT_GZIP = 'gzip';

	private const int COPY_CHUNK_SIZE = 1048576;

	private const int MAX_COMPRESSION_RATIO = 200;
	private const int RATIO_CHECK_AFTER = 1048576;

	private const int MAX_NAME_LENGTH = 1024;

	private const int GZIP_DEFLATE_METHOD = 8;
	private const int GZIP_RESERVED_FLAGS = 0xE0;
	private const int GZIP_HEADER_LENGTH = 10;

	private const int UNIX_TYPE_MASK = 0170000;
	private const int UNIX_REGULAR_FILE = 0100000;

	public function detectFormat( string $path ): ?string {
		$handle = fopen( $path, 'rb' );
		if ( !$handle ) {
			return null;
		}

		$magic = (string)fread( $handle, 4 );
		fclose( $handle );

		return match ( true ) {
			str_starts_with( $magic, "PK\x03\x04" ) => self::FORMAT_ZIP,
			str_starts_with( $magic, "\x1f\x8b" ) => self::FORMAT_GZIP,
			default => null,
		};
	}

	/**
	 * Whether an entry name is a plain relative path that cannot leave the directory
	 * it is extracted into. Directory entries may end with a slash.
	 */
	public function isSafeName( string $name ): bool {
		$name = rtrim( $name, '/' );
		if (
			$name === '' ||
			strlen( $name ) > self::MAX_NAME_LENGTH ||
			$name[0] === '/' ||
			preg_match( '/[\x00-\x1f\x7f\\\\]|^[A-Za-z]:/', $name )
		) {
			return false;
		}

		foreach ( explode( '/', $name ) as $part ) {
			if ( $part === '' || $part === '.' || $part === '..' ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Lists the regular files of an archive. Directories and macOS metadata are skipped.
	 * Anything else that is not a plain file, such as a link or an encrypted entry,
	 * makes the whole archive unacceptable.
	 *
	 * @return Status A good status with a list of ArchiveEntry as its value.
	 */
	public function listFiles( string $path, string $format, int $maxEntries ): Status {
		if ( $format === self::FORMAT_GZIP ) {
			return $this->hasValidGzipHeader( $path ) ?
				Status::newGood( [ new ArchiveEntry( 0, '', null, (int)filesize( $path ) ) ] ) :
				Status::newFatal( 'importdump-archive-unreadable' );
		}

		$zip = $this->openZip( $path );
		if ( !$zip ) {
			return Status::newFatal( 'importdump-archive-unreadable' );
		}

		try {
			if ( $zip->numFiles > $maxEntries ) {
				return Status::newFatal( 'importdump-archive-too-many-entries', Message::numParam( $maxEntries ) );
			}

			$entries = [];
			for ( $index = 0; $index < $zip->numFiles; $index++ ) {
				$stat = $zip->statIndex( $index );
				if ( $stat === false ) {
					return Status::newFatal( 'importdump-archive-unreadable' );
				}

				if ( str_starts_with( $stat['name'], '__MACOSX/' ) ) {
					continue;
				}

				if ( !$this->isSafeName( $stat['name'] ) ) {
					return Status::newFatal( 'importdump-archive-unsafe' );
				}

				if ( str_ends_with( $stat['name'], '/' ) ) {
					continue;
				}

				if ( !$this->isPlainFile( $zip, $index, $stat ) ) {
					return Status::newFatal( 'importdump-archive-unsafe' );
				}

				$entries[] = new ArchiveEntry( $index, $stat['name'], (int)$stat['size'], (int)$stat['comp_size'] );
			}

			return Status::newGood( $entries );
		} finally {
			$zip->close();
		}
	}

	/**
	 * Streams one entry into a file. Stops as soon as more than $maxSize bytes come out,
	 * or when the data expands far more than a normal file would.
	 *
	 * @param ?Closure( ?int ): void $onProgress Called with the percentage done, or null if it is not known.
	 */
	public function extractTo(
		string $path,
		string $format,
		ArchiveEntry $entry,
		string $destination,
		int $maxSize,
		?Closure $onProgress
	): Status {
		if ( $entry->size !== null && $entry->size > $maxSize ) {
			return Status::newFatal( 'importdump-archive-too-large', Message::sizeParam( $maxSize ) );
		}

		if (
			$entry->size !== null &&
			$entry->size > self::RATIO_CHECK_AFTER &&
			$entry->size > max( 1, $entry->compressedSize ) * self::MAX_COMPRESSION_RATIO
		) {
			return Status::newFatal( 'importdump-archive-ratio' );
		}

		$zip = null;
		$input = false;
		if ( $format === self::FORMAT_ZIP ) {
			$zip = $this->openZip( $path );
			$input = $zip ? $zip->getStreamIndex( $entry->index ) : false;
		} else {
			$input = gzopen( $path, 'rb' );
		}

		$output = $input ? fopen( $destination, 'wb' ) : false;
		if ( !$input || !$output ) {
			if ( $input ) {
				fclose( $input );
			}

			$zip?->close();
			return Status::newFatal( 'importdump-archive-unreadable' );
		}

		try {
			if ( $onProgress ) {
				$onProgress( $entry->size ? 0 : null );
			}

			return $this->copy( $input, $output, $entry, $maxSize, $onProgress );
		} finally {
			fclose( $input );
			fclose( $output );
			$zip?->close();
		}
	}

	/**
	 * @param resource $input
	 * @param resource $output
	 * @param ?Closure( ?int ): void $onProgress
	 */
	private function copy( $input, $output, ArchiveEntry $entry, int $maxSize, ?Closure $onProgress ): Status {
		$maxRatioSize = max( 1, $entry->compressedSize ) * self::MAX_COMPRESSION_RATIO;

		$total = 0;
		$reported = 0;
		while ( !feof( $input ) ) {
			$chunk = fread( $input, self::COPY_CHUNK_SIZE );
			if ( $chunk === false ) {
				return Status::newFatal( 'importdump-archive-unreadable' );
			}

			if ( $chunk === '' ) {
				break;
			}

			$total += strlen( $chunk );
			if ( $total > $maxSize ) {
				return Status::newFatal( 'importdump-archive-too-large', Message::sizeParam( $maxSize ) );
			}

			if ( $total > self::RATIO_CHECK_AFTER && $total > $maxRatioSize ) {
				return Status::newFatal( 'importdump-archive-ratio' );
			}

			if ( fwrite( $output, $chunk ) !== strlen( $chunk ) ) {
				return Status::newFatal( 'importdump-archive-unreadable' );
			}

			if ( $onProgress && $entry->size ) {
				$percent = min( 100, intdiv( $total * 100, $entry->size ) );
				if ( $percent !== $reported ) {
					$reported = $percent;
					$onProgress( $percent );
				}
			}
		}

		if ( $entry->size !== null && $total !== $entry->size ) {
			return Status::newFatal( 'importdump-archive-unreadable' );
		}

		return Status::newGood();
	}

	/** Opens a ZIP and makes libzip compare the central directory with the local headers. */
	private function openZip( string $path ): ?ZipArchive {
		$zip = new ZipArchive();
		if ( $zip->open( $path, ZipArchive::RDONLY | ZipArchive::CHECKCONS ) !== true ) {
			return null;
		}

		return $zip;
	}

	private function hasValidGzipHeader( string $path ): bool {
		$handle = fopen( $path, 'rb' );
		if ( !$handle ) {
			return false;
		}

		$header = (string)fread( $handle, self::GZIP_HEADER_LENGTH );
		fclose( $handle );

		return strlen( $header ) === self::GZIP_HEADER_LENGTH &&
			ord( $header[2] ) === self::GZIP_DEFLATE_METHOD &&
			( ord( $header[3] ) & self::GZIP_RESERVED_FLAGS ) === 0;
	}

	private function isPlainFile( ZipArchive $zip, int $index, array $stat ): bool {
		if (
			$stat['encryption_method'] !== ZipArchive::EM_NONE ||
			!in_array( $stat['comp_method'], [ ZipArchive::CM_STORE, ZipArchive::CM_DEFLATE ], true )
		) {
			return false;
		}

		if ( !$zip->getExternalAttributesIndex( $index, $operatingSystem, $attributes ) ) {
			return false;
		}

		if ( $operatingSystem !== ZipArchive::OPSYS_UNIX ) {
			return true;
		}

		$type = ( $attributes >> 16 ) & self::UNIX_TYPE_MASK;
		return $type === 0 || $type === self::UNIX_REGULAR_FILE;
	}
}
