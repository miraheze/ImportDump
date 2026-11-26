<?php

declare( strict_types = 1 );

namespace Miraheze\ImportDump\Upload;

use Closure;
use MediaWiki\Message\Message;
use MediaWiki\Status\Status;
use function array_any;
use function array_keys;
use function count_chars;
use function ctype_digit;
use function feof;
use function fread;
use function fwrite;
use function intdiv;
use function max;
use function min;
use function octdec;
use function ord;
use function preg_match;
use function rtrim;
use function str_ends_with;
use function str_repeat;
use function str_starts_with;
use function strlen;
use function strpos;
use function substr;
use function trim;
use const PHP_INT_MAX;

/**
 * Reads entries one after another from a tar stream, which may be decompressed on the fly.
 * Only regular files and directories are accepted. Anything else, such as a link, a device
 * or a sparse file, is an error. The stream is read forward only and every byte that is read
 * counts against the limits, so a stream that expands too much is stopped.
 */
final class TarReader {

	public const int BLOCK_SIZE = 512;

	private const int MAX_EXTENDED_HEADER_SIZE = 65536;
	private const int MAX_LONG_NAME_SIZE = 4096;

	private const int NAME_OFFSET = 0;
	private const int NAME_LENGTH = 100;
	private const int SIZE_OFFSET = 124;
	private const int SIZE_LENGTH = 12;
	private const int CHECKSUM_OFFSET = 148;
	private const int CHECKSUM_LENGTH = 8;
	private const int TYPE_OFFSET = 156;
	private const int MAGIC_OFFSET = 257;
	private const int PREFIX_OFFSET = 345;
	private const int PREFIX_LENGTH = 155;

	/** The part of the header that tells a tar apart. */
	public const string MAGIC = 'ustar';

	private int $consumed = 0;
	private int $bodyBytes = 0;
	private int $headers = 0;
	private int $returned = 0;

	/** Bytes of the last entry that have not been read yet, including its padding. */
	private int $pending = 0;

	private bool $ended = false;

	/**
	 * @param mixed $stream A stream resource that can be read, such as an uncompressed tar.
	 * @param int $compressedSize Size of the file that the stream is read from.
	 * @param int $maxEntries Most headers that may be read.
	 * @param int $maxBodyBytes Most bytes that the data of all entries together may have.
	 */
	public function __construct(
		private mixed $stream,
		private readonly int $compressedSize,
		private readonly int $maxEntries,
		private readonly int $maxBodyBytes,
	) {
	}

	/**
	 * Reads up to $length bytes, which is fewer only at the end of the stream.
	 *
	 * @param mixed $stream A stream resource that can be read.
	 * @param int $length
	 */
	public static function readUpTo( mixed $stream, int $length ): string {
		$data = '';
		while ( strlen( $data ) < $length && !feof( $stream ) ) {
			$chunk = fread( $stream, $length - strlen( $data ) );
			if ( $chunk === false || $chunk === '' ) {
				break;
			}

			$data .= $chunk;
		}

		return $data;
	}

	/**
	 * Moves on to the next entry, skipping whatever is left of the one before.
	 *
	 * @return Status A good status with a TarEntry as its value, or null at the end.
	 */
	public function next(): Status {
		$skipped = $this->skip( $this->pending );
		$this->pending = 0;
		if ( !$skipped->isOK() ) {
			return $skipped;
		}

		$longName = null;
		$paxPath = null;
		$paxSize = null;

		while ( !$this->ended ) {
			$block = $this->readBlock();
			if ( $block === null ) {
				$this->ended = true;
				return Status::newGood( null );
			}

			if ( $block === false ) {
				return Status::newFatal( 'requestimport-archive-unreadable' );
			}

			$limit = $this->account( self::BLOCK_SIZE );
			if ( !$limit->isOK() ) {
				return $limit;
			}

			if ( !$this->countHeader() ) {
				return Status::newFatal(
					'requestimport-archive-too-many-entries',
					Message::numParam( $this->maxEntries )
				);
			}

			if ( !$this->hasValidChecksum( $block ) ) {
				return Status::newFatal( 'requestimport-archive-unreadable' );
			}

			$size = $this->parseSize( substr( $block, self::SIZE_OFFSET, self::SIZE_LENGTH ) );
			if ( $size === null ) {
				return Status::newFatal( 'requestimport-archive-unreadable' );
			}

			$type = $block[self::TYPE_OFFSET];
			if ( $type === 'x' || $type === 'g' || $type === 'L' || $type === 'K' ) {
				$limit = $type === 'x' || $type === 'g' ?
					self::MAX_EXTENDED_HEADER_SIZE :
					self::MAX_LONG_NAME_SIZE;

				if ( $size > $limit ) {
					return Status::newFatal( 'requestimport-archive-unsafe' );
				}

				$body = $this->readBody( $size );
				if ( !$body->isOK() ) {
					return $body;
				}

				if ( $type === 'L' ) {
					$longName = rtrim( $body->getValue(), "\0" );
				} elseif ( $type === 'x' ) {
					$records = $this->parsePax( $body->getValue() );
					if ( $records === null || $this->isSparse( $records ) ) {
						return Status::newFatal( 'requestimport-archive-unsafe' );
					}

					$paxPath = $records['path'] ?? $paxPath;
					$paxSize = $records['size'] ?? $paxSize;
				}

				continue;
			}

			$isDirectory = $type === '5';
			if ( !$isDirectory && $type !== '0' && $type !== "\0" ) {
				return Status::newFatal( 'requestimport-archive-unsafe' );
			}

			$name = $paxPath ?? $longName ?? $this->readName( $block );
			if ( $paxSize !== null ) {
				if ( !ctype_digit( $paxSize ) || strlen( $paxSize ) > 18 ) {
					return Status::newFatal( 'requestimport-archive-unreadable' );
				}

				$size = (int)$paxSize;
			}

			// Old archives mark directories only with a slash at the end of the name.
			if ( !$isDirectory && str_ends_with( $name, '/' ) ) {
				$isDirectory = true;
			}

			$this->bodyBytes += $size;
			if ( $this->bodyBytes > $this->maxBodyBytes ) {
				return Status::newFatal( 'requestimport-archive-too-large', Message::sizeParam( $this->maxBodyBytes ) );
			}

			$this->pending = $size + $this->paddingFor( $size );
			return Status::newGood( new TarEntry( $this->returned++, $name, $size, $isDirectory ) );
		}

		return Status::newGood( null );
	}

	/**
	 * Writes the data of an entry that was just returned by next() into a file.
	 *
	 * @param TarEntry $entry
	 * @param mixed $output A stream resource that can be written to.
	 * @param ?Closure( ?int ): void $onProgress Called with the percentage that is done.
	 */
	public function copyBody( TarEntry $entry, mixed $output, ?Closure $onProgress ): Status {
		$remaining = $entry->size;
		$reported = 0;

		while ( $remaining > 0 ) {
			$chunk = self::readUpTo( $this->stream, min( $remaining, ArchiveReader::COPY_CHUNK_SIZE ) );
			if ( $chunk === '' ) {
				return Status::newFatal( 'requestimport-archive-unreadable' );
			}

			$limit = $this->account( strlen( $chunk ) );
			if ( !$limit->isOK() ) {
				return $limit;
			}

			if ( fwrite( $output, $chunk ) !== strlen( $chunk ) ) {
				return Status::newFatal( 'requestimport-archive-unreadable' );
			}

			$remaining -= strlen( $chunk );
			$this->pending -= strlen( $chunk );

			if ( $onProgress && $entry->size > 0 ) {
				$percent = min( 100, intdiv( ( $entry->size - $remaining ) * 100, $entry->size ) );
				if ( $percent !== $reported ) {
					$reported = $percent;
					$onProgress( $percent );
				}
			}
		}

		return Status::newGood();
	}

	/** @return non-empty-string|false|null The block, null at a clean end of the stream, or false if it was cut off. */
	private function readBlock(): string|false|null {
		$block = self::readUpTo( $this->stream, self::BLOCK_SIZE );
		if ( $block === '' ) {
			return null;
		}

		if ( strlen( $block ) !== self::BLOCK_SIZE ) {
			return false;
		}

		return $block === str_repeat( "\0", self::BLOCK_SIZE ) ? null : $block;
	}

	/** @return Status A good status with the contents, which are only for short entries. */
	private function readBody( int $size ): Status {
		$body = self::readUpTo( $this->stream, $size );
		$padding = $this->paddingFor( $size );
		$padded = self::readUpTo( $this->stream, $padding );
		if ( strlen( $body ) !== $size || strlen( $padded ) !== $padding ) {
			return Status::newFatal( 'requestimport-archive-unreadable' );
		}

		$limit = $this->account( $size + $padding );
		return $limit->isOK() ? Status::newGood( $body ) : $limit;
	}

	private function skip( int $bytes ): Status {
		while ( $bytes > 0 ) {
			$chunk = self::readUpTo( $this->stream, min( $bytes, ArchiveReader::COPY_CHUNK_SIZE ) );
			if ( $chunk === '' ) {
				return Status::newFatal( 'requestimport-archive-unreadable' );
			}

			$limit = $this->account( strlen( $chunk ) );
			if ( !$limit->isOK() ) {
				return $limit;
			}

			$bytes -= strlen( $chunk );
		}

		return Status::newGood();
	}

	/** Adds to the bytes that came out of the stream and checks that it has not expanded too much. */
	private function account( int $bytes ): Status {
		$this->consumed += $bytes;
		if (
			$this->consumed > ArchiveReader::RATIO_CHECK_AFTER &&
			$this->consumed > max( 1, $this->compressedSize ) * ArchiveReader::MAX_COMPRESSION_RATIO
		) {
			return Status::newFatal( 'requestimport-archive-ratio' );
		}

		return Status::newGood();
	}

	private function countHeader(): bool {
		return ++$this->headers <= $this->maxEntries;
	}

	private function paddingFor( int $size ): int {
		return ( self::BLOCK_SIZE - $size % self::BLOCK_SIZE ) % self::BLOCK_SIZE;
	}

	private function hasValidChecksum( string $block ): bool {
		$stored = trim( substr( $block, self::CHECKSUM_OFFSET, self::CHECKSUM_LENGTH ), " \0" );
		if ( $stored === '' || !preg_match( '/^[0-7]+$/', $stored ) ) {
			return false;
		}

		// The checksum is the sum of all the bytes, with the checksum field itself counted as spaces.
		$sum = $this->byteSum( $block ) -
			$this->byteSum( substr( $block, self::CHECKSUM_OFFSET, self::CHECKSUM_LENGTH ) ) +
			self::CHECKSUM_LENGTH * ord( ' ' );

		return octdec( $stored ) === $sum;
	}

	private function byteSum( string $data ): int {
		$sum = 0;
		foreach ( count_chars( $data, 1 ) as $byte => $count ) {
			$sum += $byte * $count;
		}

		return $sum;
	}

	private function readName( string $block ): string {
		$name = $this->cString( substr( $block, self::NAME_OFFSET, self::NAME_LENGTH ) );

		// Only the POSIX format has a prefix. In the GNU format the same bytes mean something else.
		if ( substr( $block, self::MAGIC_OFFSET, 6 ) === "ustar\0" ) {
			$prefix = $this->cString( substr( $block, self::PREFIX_OFFSET, self::PREFIX_LENGTH ) );
			if ( $prefix !== '' ) {
				return $prefix . '/' . $name;
			}
		}

		return $name;
	}

	private function cString( string $field ): string {
		$end = strpos( $field, "\0" );
		return $end === false ? $field : substr( $field, 0, $end );
	}

	/** Reads a size that is written in octal, or in the binary form that is used for very large files. */
	private function parseSize( string $field ): ?int {
		if ( ( ord( $field[0] ) & 0x80 ) !== 0 ) {
			if ( ord( $field[0] ) === 0xFF ) {
				return null;
			}

			$value = ord( $field[0] ) & 0x7F;
			for ( $i = 1; $i < strlen( $field ); $i++ ) {
				if ( $value > ( PHP_INT_MAX >> 8 ) ) {
					return null;
				}

				$value = ( $value << 8 ) | ord( $field[$i] );
			}

			return $value;
		}

		$digits = trim( $field, " \0" );
		if ( $digits === '' ) {
			return 0;
		}

		return preg_match( '/^[0-7]{1,12}$/', $digits ) ? (int)octdec( $digits ) : null;
	}

	/** @return ?array<string, string> The records, or null if they are not well formed. */
	private function parsePax( string $body ): ?array {
		$records = [];
		$offset = 0;
		$total = strlen( $body );

		while ( $offset < $total ) {
			$space = strpos( $body, ' ', $offset );
			if ( $space === false ) {
				return null;
			}

			$length = substr( $body, $offset, $space - $offset );
			if ( !ctype_digit( $length ) || (int)$length <= $space - $offset + 1 || $offset + (int)$length > $total ) {
				return null;
			}

			$record = substr( $body, $space + 1, (int)$length - ( $space - $offset ) - 1 );
			if ( !str_ends_with( $record, "\n" ) ) {
				return null;
			}

			$equals = strpos( $record, '=' );
			if ( $equals === false ) {
				return null;
			}

			$records[substr( $record, 0, $equals )] = substr( $record, $equals + 1, -1 );
			$offset += (int)$length;
		}

		return $records;
	}

	/** @param array<string, string> $records */
	private function isSparse( array $records ): bool {
		return array_any(
			array_keys( $records ),
			static fn ( int|string $key ): bool => str_starts_with( (string)$key, 'GNU.sparse.' )
		);
	}
}
