<?php

namespace Miraheze\ImportDump\Upload;

use Closure;
use MediaWiki\Message\Message;
use MediaWiki\Status\Status;
use ZipArchive;

/**
 * Reads ZIP, TAR, GZIP and BZIP2 archives without trusting what is in them. A GZIP or BZIP2 file
 * holds either a single file or a TAR. Entry names are checked and returned but never used to
 * build a path, and every entry is streamed into a destination chosen by the caller, within
 * a size limit.
 */
final class ArchiveReader {

	public const string FORMAT_ZIP = 'zip';

	public const string FORMAT_GZIP = 'gzip';

	public const string FORMAT_BZIP2 = 'bzip2';

	public const string FORMAT_TAR = 'tar';

	public const int COPY_CHUNK_SIZE = 1048576;

	public const int MAX_COMPRESSION_RATIO = 200;
	public const int RATIO_CHECK_AFTER = 1048576;

	private const string BZIP2_BLOCK_MAGIC = "\x31\x41\x59\x26\x53\x59";
	private const string BZIP2_EMPTY_MAGIC = "\x17\x72\x45\x38\x50\x90";

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

		$magic = TarReader::readUpTo( $handle, TarReader::BLOCK_SIZE );
		fclose( $handle );

		return match ( true ) {
			str_starts_with( $magic, "PK\x03\x04" ) => self::FORMAT_ZIP,
			str_starts_with( $magic, "\x1f\x8b" ) => self::FORMAT_GZIP,
			$this->isBzip2Header( $magic ) => self::FORMAT_BZIP2,
			$this->isTarHeader( $magic ) => self::FORMAT_TAR,
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
	 * @param string $path The archive.
	 * @param string $format One of the FORMAT_* constants.
	 * @param int $maxEntries Most entries, including directories, that the archive may have.
	 * @param int $maxSize Most bytes that the files of a TAR may have together.
	 * @return Status A good status with a list of ArchiveEntry as its value.
	 */
	public function listFiles( string $path, string $format, int $maxEntries, int $maxSize ): Status {
		if ( $format === self::FORMAT_GZIP || $format === self::FORMAT_BZIP2 ) {
			if ( $format === self::FORMAT_BZIP2 && !function_exists( 'bzopen' ) ) {
				return Status::newFatal( 'importdump-archive-bzip2-unavailable' );
			}

			if ( $format === self::FORMAT_GZIP && !$this->hasValidGzipHeader( $path ) ) {
				return Status::newFatal( 'importdump-archive-unreadable' );
			}

			return $this->containsTar( $path, $format ) ?
				$this->listTar( $path, $format, $maxEntries, $maxSize ) :
				Status::newGood( [ new ArchiveEntry( 0, '', null, (int)filesize( $path ) ) ] );
		}

		if ( $format === self::FORMAT_TAR ) {
			return $this->listTar( $path, $format, $maxEntries, $maxSize );
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
	 * @param string $path The archive.
	 * @param string $format One of the FORMAT_* constants.
	 * @param ArchiveEntry $entry The entry to extract.
	 * @param string $destination The file to write it to.
	 * @param int $maxSize Most bytes that may be written.
	 * @param ?Closure( ?int ): void $onProgress Called with the percentage done, or null if it is not known.
	 * @return Status
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

		if ( $this->isTarEntry( $format, $entry ) ) {
			return $this->extractTar( $path, $format, $entry, $destination, $maxSize, $onProgress );
		}

		$zip = null;
		if ( $format === self::FORMAT_ZIP ) {
			$zip = $this->openZip( $path );
			$input = $zip ? $zip->getStreamIndex( $entry->index ) : false;
		} else {
			$input = $this->openStream( $path, $format );
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
	 * @param ArchiveEntry $entry
	 * @param int $maxSize
	 * @param ?Closure( ?int ): void $onProgress
	 * @return Status
	 */
	private function copy( $input, $output, ArchiveEntry $entry, int $maxSize, ?Closure $onProgress ): Status {
		$maxRatioSize = max( 1, $entry->compressedSize ) * self::MAX_COMPRESSION_RATIO;
		$declaredSize = $entry->size ?? 0;

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

			if ( $onProgress && $declaredSize > 0 ) {
				$percent = min( 100, intdiv( $total * 100, $declaredSize ) );
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

	private function isTarEntry( string $format, ArchiveEntry $entry ): bool {
		return $format === self::FORMAT_TAR ||
			( ( $format === self::FORMAT_GZIP || $format === self::FORMAT_BZIP2 ) && $entry->name !== '' );
	}

	/**
	 * Opens a file for reading its contents, decompressed if it is compressed.
	 *
	 * @param string $path
	 * @param string $format
	 * @return resource|false
	 */
	private function openStream( string $path, string $format ): mixed {
		return match ( $format ) {
			self::FORMAT_GZIP => gzopen( $path, 'rb' ),
			self::FORMAT_BZIP2 => function_exists( 'bzopen' ) ? bzopen( $path, 'r' ) : false,
			default => fopen( $path, 'rb' ),
		};
	}

	/** Whether what comes out of a compressed file is a TAR. Only the start of it is read. */
	private function containsTar( string $path, string $format ): bool {
		$input = $this->openStream( $path, $format );
		if ( !$input ) {
			return false;
		}

		$block = TarReader::readUpTo( $input, TarReader::BLOCK_SIZE );
		fclose( $input );

		return $this->isTarHeader( $block );
	}

	private function isTarHeader( string $block ): bool {
		return strlen( $block ) === TarReader::BLOCK_SIZE && substr( $block, 257, 5 ) === TarReader::MAGIC;
	}

	private function isBzip2Header( string $header ): bool {
		if ( strlen( $header ) < 10 || !str_starts_with( $header, 'BZh' ) || !ctype_digit( $header[3] ) ) {
			return false;
		}

		return $header[3] !== '0' &&
			in_array( substr( $header, 4, 6 ), [ self::BZIP2_BLOCK_MAGIC, self::BZIP2_EMPTY_MAGIC ], true );
	}

	/** Lists the regular files of a TAR, whether or not it is compressed. */
	private function listTar( string $path, string $format, int $maxEntries, int $maxSize ): Status {
		$input = $this->openStream( $path, $format );
		if ( !$input ) {
			return Status::newFatal( 'importdump-archive-unreadable' );
		}

		try {
			$reader = new TarReader( $input, (int)filesize( $path ), $maxEntries, $maxSize );
			$entries = [];

			while ( true ) {
				$next = $reader->next();
				if ( !$next->isOK() ) {
					return $next;
				}

				$tarEntry = $next->getValue();
				if ( $tarEntry === null ) {
					return Status::newGood( $entries );
				}

				$name = $this->normalizeTarName( $tarEntry->name );
				if ( $tarEntry->isDirectory ) {
					if ( $name !== '' && !$this->isSafeName( $name ) ) {
						return Status::newFatal( 'importdump-archive-unsafe' );
					}

					continue;
				}

				if ( !$this->isSafeName( $name ) ) {
					return Status::newFatal( 'importdump-archive-unsafe' );
				}

				if ( $this->isMetadata( $name ) ) {
					continue;
				}

				$entries[] = new ArchiveEntry( $tarEntry->index, $name, $tarEntry->size, (int)filesize( $path ) );

				// An archive with more than one file is not accepted, so there is no need to read on.
				if ( count( $entries ) > 1 ) {
					return Status::newGood( $entries );
				}
			}
		} finally {
			fclose( $input );
		}
	}

	private function extractTar(
		string $path,
		string $format,
		ArchiveEntry $entry,
		string $destination,
		int $maxSize,
		?Closure $onProgress
	): Status {
		$input = $this->openStream( $path, $format );
		$output = $input ? fopen( $destination, 'wb' ) : false;
		if ( !$input || !$output ) {
			if ( $input ) {
				fclose( $input );
			}

			return Status::newFatal( 'importdump-archive-unreadable' );
		}

		try {
			$reader = new TarReader( $input, (int)filesize( $path ), PHP_INT_MAX, $maxSize );

			do {
				$next = $reader->next();
				if ( !$next->isOK() ) {
					return $next;
				}

				$tarEntry = $next->getValue();
			} while ( $tarEntry !== null && $tarEntry->index !== $entry->index );

			if (
				$tarEntry === null ||
				$tarEntry->isDirectory ||
				$this->normalizeTarName( $tarEntry->name ) !== $entry->name
			) {
				return Status::newFatal( 'importdump-archive-unreadable' );
			}

			if ( $onProgress ) {
				$onProgress( $tarEntry->size ? 0 : null );
			}

			return $reader->copyBody( $tarEntry, $output, $onProgress );
		} finally {
			fclose( $input );
			fclose( $output );
		}
	}

	/** Tools often write names such as ./dump.xml. The dot is not part of the name. */
	private function normalizeTarName( string $name ): string {
		while ( str_starts_with( $name, './' ) ) {
			$name = substr( $name, 2 );
		}

		return $name === '.' ? '' : $name;
	}

	/** Files that macOS adds next to the ones that were archived. */
	private function isMetadata( string $name ): bool {
		return str_starts_with( $name, '__MACOSX/' ) || str_starts_with( basename( $name ), '._' );
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
