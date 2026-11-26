<?php

declare( strict_types = 1 );

namespace Miraheze\ImportDump\Upload;

use Closure;
use MediaWiki\Message\Message;
use MediaWiki\Status\Status;
use Wikimedia\FileBackend\FSFile\TempFSFile;
use Wikimedia\FileBackend\FSFile\TempFSFileFactory;
use ZipArchive;
use function basename;
use function bzopen;
use function ctype_digit;
use function explode;
use function fclose;
use function feof;
use function filesize;
use function fopen;
use function fread;
use function fseek;
use function function_exists;
use function fwrite;
use function gzopen;
use function in_array;
use function intdiv;
use function max;
use function min;
use function ord;
use function preg_match;
use function rtrim;
use function str_ends_with;
use function str_starts_with;
use function stream_copy_to_stream;
use function strlen;
use function substr;
use const PHP_INT_MAX;

/**
 * Reads ZIP, TAR, GZIP and BZIP2 archives, and XZ and 7z archives if the wiki has the programs
 * for them, without trusting what is in them. A GZIP, BZIP2 or XZ file holds either a single
 * file or a TAR. Entry names are checked and returned but never used to build a path, and every
 * entry is streamed into a destination chosen by the caller, within a size limit.
 */
final class ArchiveReader {

	public const string FORMAT_ZIP = 'zip';

	public const string FORMAT_GZIP = 'gzip';

	public const string FORMAT_BZIP2 = 'bzip2';

	public const string FORMAT_TAR = 'tar';

	public const string FORMAT_XZ = 'xz';

	public const string FORMAT_SEVEN_ZIP = '7z';

	public const int COPY_CHUNK_SIZE = 1048576;

	public const int MAX_COMPRESSION_RATIO = 200;
	public const int RATIO_CHECK_AFTER = 1048576;

	private const string BZIP2_BLOCK_MAGIC = "\x31\x41\x59\x26\x53\x59";
	private const string BZIP2_EMPTY_MAGIC = "\x17\x72\x45\x38\x50\x90";
	private const string XZ_MAGIC = "\xFD7zXZ\x00";
	private const string SEVEN_ZIP_MAGIC = "7z\xBC\xAF\x27\x1C";

	/** Room for the headers of a TAR that is unpacked from a file, on top of what its files may have. */
	private const int TAR_HEADER_ALLOWANCE = 67108864;

	private const int MAX_NAME_LENGTH = 1024;

	private const int GZIP_DEFLATE_METHOD = 8;
	private const int GZIP_RESERVED_FLAGS = 0xE0;
	private const int GZIP_HEADER_LENGTH = 10;

	private const int UNIX_TYPE_MASK = 0170000;
	private const int UNIX_REGULAR_FILE = 0100000;

	/**
	 * The TAR that is being read, so that the entries of a large one are taken out in one pass
	 * and not by reading from the start for every entry.
	 *
	 * @var ?array{path: string, format: string, input: mixed, reader: TarReader, next: int}
	 */
	private ?array $cursor = null;

	/**
	 * What an XZ file holds, unpacked once so that its TAR is read in one pass.
	 *
	 * @var ?array{path: string, file: TempFSFile}
	 */
	private ?array $xz = null;

	/**
	 * What all the files of a 7z archive hold one after the other, unpacked once. The offsets are where
	 * the file with an index starts.
	 *
	 * @var ?array{path: string, offsets: array<int, int>, total: int, limit: int, file: ?TempFSFile}
	 */
	private ?array $sevenZip = null;

	public function __construct(
		private readonly ExternalBinaries $externalBinaries,
		private readonly TempFSFileFactory $tempFSFileFactory,
	) {
	}

	public function __destruct() {
		$this->close();
	}

	/** Lets go of the archive that was being read. Reading another entry opens it again. */
	public function close(): void {
		$this->closeCursor();
		$this->purge( $this->xz['file'] ?? null );
		$this->xz = null;

		$this->purge( $this->sevenZip['file'] ?? null );
		$this->sevenZip = null;
	}

	private function purge( ?TempFSFile $file ): void {
		$file?->purge();
	}

	private function closeCursor(): void {
		if ( $this->cursor ) {
			fclose( $this->cursor['input'] );
			$this->cursor = null;
		}
	}

	/** @return list<string> The FORMAT_* constants of the archives that can be read on this wiki. */
	public function getFormats(): array {
		$formats = [ self::FORMAT_ZIP, self::FORMAT_TAR, self::FORMAT_GZIP, self::FORMAT_BZIP2 ];

		if ( $this->externalBinaries->isEnabled( ExternalBinaries::XZ ) ) {
			$formats[] = self::FORMAT_XZ;
		}

		if ( $this->externalBinaries->isEnabled( ExternalBinaries::SEVEN_ZIP ) ) {
			$formats[] = self::FORMAT_SEVEN_ZIP;
		}

		return $formats;
	}

	/** @return list<string> The MIME types of the formats that need a program, if they can be read. */
	public function getExternalMimeTypes(): array {
		return $this->externalBinaries->getMimeTypes();
	}

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
			str_starts_with( $magic, self::XZ_MAGIC ) && $this->externalBinaries->isEnabled( ExternalBinaries::XZ ) =>
				self::FORMAT_XZ,
			str_starts_with( $magic, self::SEVEN_ZIP_MAGIC ) &&
				$this->externalBinaries->isEnabled( ExternalBinaries::SEVEN_ZIP ) => self::FORMAT_SEVEN_ZIP,
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
		if ( $format === self::FORMAT_XZ ) {
			return $this->listXz( $path, $maxEntries, $maxSize );
		}

		if ( $format === self::FORMAT_SEVEN_ZIP ) {
			return $this->listSevenZip( $path, $maxEntries, $maxSize );
		}

		if ( $format === self::FORMAT_GZIP || $format === self::FORMAT_BZIP2 ) {
			if ( $format === self::FORMAT_BZIP2 && !function_exists( 'bzopen' ) ) {
				return Status::newFatal( 'requestimport-archive-bzip2-unavailable' );
			}

			if ( $format === self::FORMAT_GZIP && !$this->hasValidGzipHeader( $path ) ) {
				return Status::newFatal( 'requestimport-archive-unreadable' );
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
			return Status::newFatal( 'requestimport-archive-unreadable' );
		}

		try {
			if ( $zip->numFiles > $maxEntries ) {
				return Status::newFatal( 'requestimport-archive-too-many-entries', Message::numParam( $maxEntries ) );
			}

			$entries = [];
			for ( $index = 0; $index < $zip->numFiles; $index++ ) {
				$stat = $zip->statIndex( $index );
				if ( $stat === false ) {
					return Status::newFatal( 'requestimport-archive-unreadable' );
				}

				if ( str_starts_with( $stat['name'], '__MACOSX/' ) ) {
					continue;
				}

				if ( !$this->isSafeName( $stat['name'] ) ) {
					return Status::newFatal( 'requestimport-archive-unsafe' );
				}

				if ( str_ends_with( $stat['name'], '/' ) ) {
					continue;
				}

				if ( !$this->isPlainFile( $zip, $index, $stat ) ) {
					return Status::newFatal( 'requestimport-archive-unsafe' );
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
			return Status::newFatal( 'requestimport-archive-too-large', Message::sizeParam( $maxSize ) );
		}

		if (
			$entry->size !== null &&
			$entry->size > self::RATIO_CHECK_AFTER &&
			$entry->size > max( 1, $entry->compressedSize ) * self::MAX_COMPRESSION_RATIO
		) {
			return Status::newFatal( 'requestimport-archive-ratio' );
		}

		if ( $format === self::FORMAT_XZ ) {
			return $this->extractXz( $path, $entry, $destination, $maxSize, $onProgress );
		}

		if ( $format === self::FORMAT_SEVEN_ZIP ) {
			return $this->extractSevenZip( $path, $entry, $destination, $maxSize, $onProgress );
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
			return Status::newFatal( 'requestimport-archive-unreadable' );
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
	 * @param mixed $input A stream resource that can be read.
	 * @param mixed $output A stream resource that can be written to.
	 * @param ArchiveEntry $entry
	 * @param int $maxSize
	 * @param ?Closure( ?int ): void $onProgress
	 */
	private function copy(
		mixed $input,
		mixed $output,
		ArchiveEntry $entry,
		int $maxSize,
		?Closure $onProgress
	): Status {
		$maxRatioSize = max( 1, $entry->compressedSize ) * self::MAX_COMPRESSION_RATIO;
		$declaredSize = $entry->size ?? 0;

		$total = 0;
		$reported = 0;
		while ( !feof( $input ) ) {
			$chunk = fread( $input, self::COPY_CHUNK_SIZE );
			if ( $chunk === false ) {
				return Status::newFatal( 'requestimport-archive-unreadable' );
			}

			if ( $chunk === '' ) {
				break;
			}

			$total += strlen( $chunk );
			if ( $total > $maxSize ) {
				return Status::newFatal( 'requestimport-archive-too-large', Message::sizeParam( $maxSize ) );
			}

			if ( $total > self::RATIO_CHECK_AFTER && $total > $maxRatioSize ) {
				return Status::newFatal( 'requestimport-archive-ratio' );
			}

			if ( fwrite( $output, $chunk ) !== strlen( $chunk ) ) {
				return Status::newFatal( 'requestimport-archive-unreadable' );
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
			return Status::newFatal( 'requestimport-archive-unreadable' );
		}

		return Status::newGood();
	}

	/** Lists an XZ file, which holds a single file or a TAR. */
	private function listXz( string $path, int $maxEntries, int $maxSize ): Status {
		if ( !$this->externalBinaries->isEnabled( ExternalBinaries::XZ ) ) {
			return Status::newFatal( 'requestimport-archive-binary-unavailable' );
		}

		$temp = $this->tempFSFileFactory->newTempFSFile( 'importdump-xz', 'tmp' );
		if ( !$temp ) {
			return Status::newFatal( 'requestimport-archive-unreadable' );
		}

		$peek = $this->externalBinaries->peekXz( $path, $temp->getPath() );
		if ( !$peek->isOK() ) {
			return $peek;
		}

		if ( !$this->isTarHeader( $peek->getValue() ) ) {
			return Status::newGood( [ new ArchiveEntry( 0, '', null, (int)filesize( $path ) ) ] );
		}

		$unpacked = $this->externalBinaries->decompressXz(
			$path,
			$temp->getPath(),
			$maxSize + self::TAR_HEADER_ALLOWANCE
		);

		if ( !$unpacked->isOK() ) {
			return $unpacked;
		}

		// The files are taken out of this later, so the file is not unpacked again for each of them.
		$this->close();
		$this->xz = [ 'path' => $path, 'file' => $temp ];

		return $this->listTar( $temp->getPath(), self::FORMAT_TAR, $maxEntries, $maxSize );
	}

	private function extractXz(
		string $path,
		ArchiveEntry $entry,
		string $destination,
		int $maxSize,
		?Closure $onProgress
	): Status {
		if ( !$this->externalBinaries->isEnabled( ExternalBinaries::XZ ) ) {
			return Status::newFatal( 'requestimport-archive-binary-unavailable' );
		}

		if ( $onProgress ) {
			$onProgress( null );
		}

		if ( $entry->name === '' ) {
			$unpacked = $this->externalBinaries->decompressXz( $path, $destination, $maxSize );
			if ( $unpacked->isOK() && $onProgress ) {
				$onProgress( 100 );
			}

			return $unpacked->isOK() ? Status::newGood() : $unpacked;
		}

		if ( !$this->xz || $this->xz['path'] !== $path ) {
			$temp = $this->tempFSFileFactory->newTempFSFile( 'importdump-xz', 'tmp' );
			if ( !$temp ) {
				return Status::newFatal( 'requestimport-archive-unreadable' );
			}

			$unpacked = $this->externalBinaries->decompressXz(
				$path,
				$temp->getPath(),
				$maxSize + self::TAR_HEADER_ALLOWANCE
			);

			if ( !$unpacked->isOK() ) {
				return $unpacked;
			}

			$this->close();
			$this->xz = [ 'path' => $path, 'file' => $temp ];
		}

		return $this->extractTar(
			$this->xz['file']->getPath(),
			self::FORMAT_TAR,
			$entry,
			$destination,
			$maxSize,
			$onProgress
		);
	}

	/** Lists the files of a 7z archive. */
	private function listSevenZip( string $path, int $maxEntries, int $maxSize ): Status {
		if ( !$this->externalBinaries->isEnabled( ExternalBinaries::SEVEN_ZIP ) ) {
			return Status::newFatal( 'requestimport-archive-binary-unavailable' );
		}

		$temp = $this->tempFSFileFactory->newTempFSFile( 'importdump-7z', 'tmp' );
		if ( !$temp ) {
			return Status::newFatal( 'requestimport-archive-unreadable' );
		}

		$listed = $this->externalBinaries->listSevenZip( $path, $temp->getPath(), $maxEntries );
		if ( !$listed->isOK() ) {
			return $listed;
		}

		$entries = [];
		$offsets = [];
		$total = 0;
		foreach ( $listed->getValue() as $index => $record ) {
			if ( $record['kind'] === ExternalBinaries::KIND_DIRECTORY ) {
				if ( !$this->isSafeName( $record['path'] ) ) {
					return Status::newFatal( 'requestimport-archive-unsafe' );
				}

				continue;
			}

			if ( $record['kind'] !== ExternalBinaries::KIND_FILE || $record['encrypted'] ) {
				return Status::newFatal( 'requestimport-archive-unsafe' );
			}

			if ( !$this->isSafeName( $record['path'] ) || str_ends_with( $record['path'], '/' ) ) {
				return Status::newFatal( 'requestimport-archive-unsafe' );
			}

			// The program writes every file in the order of the listing, also the ones that are skipped here.
			$offsets[$index] = $total;
			$total += $record['size'];

			if ( $this->isMetadata( $record['path'] ) ) {
				continue;
			}

			$entries[] = new ArchiveEntry( $index, $record['path'], $record['size'], (int)filesize( $path ) );
		}

		$this->close();
		$this->sevenZip = [
			'path' => $path,
			'offsets' => $offsets,
			'total' => $total,
			'limit' => $maxSize,
			'file' => null,
		];

		return Status::newGood( $entries );
	}

	private function extractSevenZip(
		string $path,
		ArchiveEntry $entry,
		string $destination,
		int $maxSize,
		?Closure $onProgress
	): Status {
		if ( !$this->externalBinaries->isEnabled( ExternalBinaries::SEVEN_ZIP ) ) {
			return Status::newFatal( 'requestimport-archive-binary-unavailable' );
		}

		if ( $onProgress ) {
			$onProgress( null );
		}

		$unpackedAll = $this->unpackSevenZip( $path );
		if ( $unpackedAll && isset( $this->sevenZip['offsets'][$entry->index] ) && $entry->size !== null ) {
			$copied = $this->copySlice(
				$unpackedAll,
				$this->sevenZip['offsets'][$entry->index],
				$entry->size,
				$destination
			);

			if ( $copied && $onProgress ) {
				$onProgress( 100 );
			}

			return $copied ? Status::newGood() : Status::newFatal( 'requestimport-archive-unreadable' );
		}

		$unpacked = $this->externalBinaries->extractSevenZip( $path, $entry->name, $destination, $maxSize );
		if ( !$unpacked->isOK() ) {
			return $unpacked;
		}

		// The archive says how big the file is, so anything else means it was not what was listed.
		if ( $entry->size !== null && $unpacked->getValue() !== $entry->size ) {
			return Status::newFatal( 'requestimport-archive-unreadable' );
		}

		if ( $onProgress ) {
			$onProgress( 100 );
		}

		return Status::newGood();
	}

	/**
	 * Unpacks all the files of the 7z archive that was listed in one go, so that the program does not
	 * have to read through a solid archive again for each file.
	 *
	 * @return ?string The file that they were written to, or null if the entries are to be unpacked one by one.
	 */
	private function unpackSevenZip( string $path ): ?string {
		$state = $this->sevenZip;
		if ( !$state || $state['path'] !== $path ) {
			return null;
		}

		if ( $state['file'] ) {
			return $state['file']->getPath();
		}

		if ( $state['total'] > $state['limit'] ) {
			return null;
		}

		$temp = $this->tempFSFileFactory->newTempFSFile( 'importdump-7z', 'tmp' );
		if ( !$temp ) {
			return null;
		}

		$unpacked = $this->externalBinaries->extractAllSevenZip( $path, $temp->getPath(), $state['limit'] );
		if ( !$unpacked->isOK() || $unpacked->getValue() !== $state['total'] ) {
			$temp->purge();
			$this->sevenZip['limit'] = -1;
			return null;
		}

		$this->sevenZip['file'] = $temp;
		return $temp->getPath();
	}

	private function copySlice( string $source, int $offset, int $length, string $destination ): bool {
		$input = fopen( $source, 'rb' );
		$output = $input ? fopen( $destination, 'wb' ) : false;
		if ( !$input || !$output ) {
			if ( $input ) {
				fclose( $input );
			}

			return false;
		}

		$copied = fseek( $input, $offset ) === 0 &&
			stream_copy_to_stream( $input, $output, $length ) === $length;

		fclose( $input );
		return fclose( $output ) && $copied;
	}

	private function isTarEntry( string $format, ArchiveEntry $entry ): bool {
		return $format === self::FORMAT_TAR || (
			in_array( $format, [ self::FORMAT_GZIP, self::FORMAT_BZIP2 ], true ) && $entry->name !== ''
		);
	}

	/**
	 * Opens a file for reading its contents, decompressed if it is compressed.
	 *
	 * @return mixed A stream resource, or false if the file could not be opened.
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
			return Status::newFatal( 'requestimport-archive-unreadable' );
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
						return Status::newFatal( 'requestimport-archive-unsafe' );
					}

					continue;
				}

				if ( !$this->isSafeName( $name ) ) {
					return Status::newFatal( 'requestimport-archive-unsafe' );
				}

				if ( $this->isMetadata( $name ) ) {
					continue;
				}

				$entries[] = new ArchiveEntry( $tarEntry->index, $name, $tarEntry->size, (int)filesize( $path ) );
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
		$cursor = $this->cursor;
		if (
			!$cursor ||
			$cursor['path'] !== $path ||
			$cursor['format'] !== $format ||
			$cursor['next'] > $entry->index
		) {
			$this->closeCursor();
			$input = $this->openStream( $path, $format );
			if ( !$input ) {
				return Status::newFatal( 'requestimport-archive-unreadable' );
			}

			$cursor = [
				'path' => $path,
				'format' => $format,
				'input' => $input,
				'reader' => new TarReader( $input, (int)filesize( $path ), PHP_INT_MAX, $maxSize ),
				'next' => 0,
			];
		}

		$this->cursor = $cursor;
		$output = fopen( $destination, 'wb' );
		if ( !$output ) {
			$this->closeCursor();
			return Status::newFatal( 'requestimport-archive-unreadable' );
		}

		$status = Status::newFatal( 'requestimport-archive-unreadable' );
		try {
			$status = $this->copyTarEntry( $cursor['reader'], $entry, $output, $onProgress );
		} finally {
			fclose( $output );

			// Where the reader is after a failure is not known, so the next entry starts over.
			if ( !$status->isOK() ) {
				$this->closeCursor();
			}
		}

		return $status;
	}

	/**
	 * @param TarReader $reader
	 * @param ArchiveEntry $entry
	 * @param mixed $output A stream resource that can be written to.
	 * @param ?Closure( ?int ): void $onProgress
	 */
	private function copyTarEntry(
		TarReader $reader,
		ArchiveEntry $entry,
		mixed $output,
		?Closure $onProgress
	): Status {
		do {
			$next = $reader->next();
			if ( !$next->isOK() ) {
				return $next;
			}

			$tarEntry = $next->getValue();
			if ( $tarEntry !== null && $this->cursor ) {
				$this->cursor['next'] = $tarEntry->index + 1;
			}
		} while ( $tarEntry !== null && $tarEntry->index !== $entry->index );

		if (
			$tarEntry === null ||
			$tarEntry->isDirectory ||
			$this->normalizeTarName( $tarEntry->name ) !== $entry->name
		) {
			return Status::newFatal( 'requestimport-archive-unreadable' );
		}

		if ( $onProgress ) {
			$onProgress( $tarEntry->size ? 0 : null );
		}

		return $reader->copyBody( $tarEntry, $output, $onProgress );
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
