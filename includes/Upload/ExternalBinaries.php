<?php

declare( strict_types = 1 );

namespace Miraheze\ImportDump\Upload;

use Exception;
use MediaWiki\Config\ServiceOptions;
use MediaWiki\Logger\LoggerFactory;
use MediaWiki\Message\Message;
use MediaWiki\Shell\CommandFactory;
use MediaWiki\Shell\Shell;
use MediaWiki\Status\Status;
use Miraheze\ImportDump\ConfigNames;
use Shellbox\Command\BoxedResult;
use function count;
use function ctype_digit;
use function defined;
use function explode;
use function file_exists;
use function file_get_contents;
use function filesize;
use function in_array;
use function is_array;
use function is_int;
use function is_string;
use function max;
use function min;
use function preg_match;
use function preg_split;
use function realpath;
use function str_contains;
use function str_starts_with;
use function strtolower;
use function trim;
use const MW_CONFIG_FILE;
use const PHP_INT_MAX;

/**
 * Runs the programs that unpack the formats PHP cannot read itself. A format only works when
 * the wiki names a program for it in RequestImportExternalBinaries, either as the path alone or as
 * an array with the keys binary and limits. The program runs in a sandbox with limits on memory,
 * time and the size of what it writes, and never sees a path or a name that came from the upload.
 */
class ExternalBinaries {

	public const array CONSTRUCTOR_OPTIONS = [
		ConfigNames::ExternalBinaries,
	];

	public const string XZ = 'xz';

	public const string SEVEN_ZIP = '7z';

	public const string KIND_FILE = 'file';

	public const string KIND_DIRECTORY = 'directory';

	public const string KIND_OTHER = 'other';

	private const array MIME_TYPES = [
		self::XZ => [ 'application/x-xz' ],
		self::SEVEN_ZIP => [ 'application/x-7z-compressed' ],
	];

	private const string XZ_INPUT = 'input.xz';
	private const string SEVEN_ZIP_INPUT = 'input.7z';
	private const string OUTPUT = 'output';

	/**
	 * What the limits are when the wiki does not set them. The memory is for the whole sandbox, the time
	 * is in seconds, and the dictionary is the most memory that the unpacking itself may need.
	 */
	private const array DEFAULT_LIMITS = [
		'memory' => 4294967296,
		'time' => 3600,
		'dictionary' => 1073741824,
	];

	/** Room that a limit on the size of a file gets, because the limit is in blocks. */
	private const int SIZE_LIMIT_SLACK = 4096;

	private const int LISTING_BYTES_PER_ENTRY = 8192;
	private const int LISTING_EXTRA_BYTES = 65536;

	/** Names the commands for the people who run the sandbox. */
	private const string ROUTE_NAME = 'importdump-archive';

	/** What a shell reports for a program that was ended because nothing reads its output anymore. */
	private const int SIGPIPE_EXIT_CODE = 141;

	/** The most that is read to tell whether a compressed file holds a TAR. */
	private const int PEEK_LIMIT = 1024;

	public function __construct(
		private readonly CommandFactory $commandFactory,
		private readonly ServiceOptions $options,
	) {
		$options->assertRequiredOptions( self::CONSTRUCTOR_OPTIONS );
	}

	/** Whether the wiki has a program for this format and may run programs at all. */
	public function isEnabled( string $format ): bool {
		return $this->getBinary( $format ) !== '' && !Shell::isDisabled();
	}

	public function getSettings( string $format ): ExternalBinary {
		$configured = $this->options->get( ConfigNames::ExternalBinaries )[$format] ?? null;
		if ( is_string( $configured ) ) {
			$configured = [ 'binary' => $configured ];
		}

		if ( !is_array( $configured ) ) {
			$configured = [];
		}

		$binary = $configured['binary'] ?? '';
		$limits = is_array( $configured['limits'] ?? null ) ? $configured['limits'] : [];
		$limit = static function ( string $name ) use ( $limits ): int {
			$value = $limits[$name] ?? null;
			return is_int( $value ) && $value > 0 ? $value : self::DEFAULT_LIMITS[$name];
		};

		return new ExternalBinary(
			is_string( $binary ) ? $binary : '',
			$limit( 'memory' ),
			$limit( 'time' ),
			$limit( 'dictionary' )
		);
	}

	/** @return list<string> The MIME types of the formats that can be read. */
	public function getMimeTypes(): array {
		$types = [];
		foreach ( self::MIME_TYPES as $format => $mimeTypes ) {
			if ( $this->isEnabled( $format ) ) {
				$types = [ ...$types, ...$mimeTypes ];
			}
		}

		return $types;
	}

	/**
	 * Reads the first bytes of what an XZ file holds, which is enough to tell whether it is a TAR.
	 *
	 * @param string $path
	 * @param string $destination A file that the bytes are written to.
	 * @return Status A good status with the bytes as its value.
	 */
	public function peekXz( string $path, string $destination ): Status {
		// Only the first bytes are read, and the program is stopped once it has written them.
		$run = $this->run(
			self::XZ,
			$path,
			self::XZ_INPUT,
			$this->limitOutput( $this->xzCommand(), self::PEEK_LIMIT ),
			$destination,
			self::PEEK_LIMIT
		);

		if ( !$run->isOK() ) {
			return $run;
		}

		$received = $run->getValue();
		'@phan-var BoxedResult $received';

		// The program is ended by the pipe that closes once enough bytes were read, which is not an error.
		if (
			!$received->wasReceived( self::OUTPUT ) ||
			!in_array( $received->getExitCode(), [ 0, self::SIGPIPE_EXIT_CODE ], true )
		) {
			return Status::newFatal( 'requestimport-archive-unreadable' );
		}

		$bytes = file_get_contents( $destination, false, null, 0, TarReader::BLOCK_SIZE );
		return $bytes === false ? Status::newFatal( 'requestimport-archive-unreadable' ) : Status::newGood( $bytes );
	}

	/**
	 * Unpacks an XZ file into another file. Stops as soon as more than $maxBytes would be written,
	 * or when the file expands far more than a normal file would.
	 */
	public function decompressXz( string $path, string $destination, int $maxBytes ): Status {
		$allowed = $this->getAllowedOutput( $path, $maxBytes );
		$run = $this->run(
			self::XZ,
			$path,
			self::XZ_INPUT,
			$this->limitOutput( $this->xzCommand(), $allowed + 1 ),
			$destination,
			$allowed + self::SIZE_LIMIT_SLACK
		);

		return $this->checkOutput( $run, $destination, $allowed, $maxBytes );
	}

	/**
	 * Lists the entries of a 7z archive.
	 *
	 * @param string $path
	 * @param string $destination A file that the listing is written to.
	 * @param int $maxEntries
	 * @return Status A good status with a list of arrays with the keys path, size, kind, encrypted
	 *  and memory as its value.
	 */
	public function listSevenZip( string $path, string $destination, int $maxEntries ): Status {
		$limit = $maxEntries * self::LISTING_BYTES_PER_ENTRY + self::LISTING_EXTRA_BYTES;
		$command = Shell::escape(
			$this->getBinary( self::SEVEN_ZIP ),
			'l',
			'-slt',
			'-ba',
			'-bd',
			self::SEVEN_ZIP_INPUT
		);

		$run = $this->run(
			self::SEVEN_ZIP,
			$path,
			self::SEVEN_ZIP_INPUT,
			$this->limitOutput( $command, $limit + 1 ),
			$destination,
			$limit + self::SIZE_LIMIT_SLACK
		);
		if ( !$run->isOK() ) {
			return $run;
		}

		$received = $run->getValue();
		'@phan-var BoxedResult $received';
		$size = file_exists( $destination ) ? (int)filesize( $destination ) : 0;
		if ( $size > $limit ) {
			return Status::newFatal( 'requestimport-archive-too-many-entries', Message::numParam( $maxEntries ) );
		}

		if ( !$received->wasReceived( self::OUTPUT ) || $received->getExitCode() !== 0 ) {
			return Status::newFatal( 'requestimport-archive-unreadable' );
		}

		$entries = $this->parseSevenZipListing( (string)file_get_contents( $destination ) );
		if ( $entries === null ) {
			return Status::newFatal( 'requestimport-archive-unreadable' );
		}

		if ( count( $entries ) > $maxEntries ) {
			return Status::newFatal( 'requestimport-archive-too-many-entries', Message::numParam( $maxEntries ) );
		}

		$dictionaryLimit = $this->getSettings( self::SEVEN_ZIP )->dictionary;
		foreach ( $entries as $entry ) {
			if ( $entry['memory'] > $dictionaryLimit ) {
				return Status::newFatal( 'requestimport-archive-memory', Message::sizeParam( $dictionaryLimit ) );
			}
		}

		return Status::newGood( $entries );
	}

	/**
	 * Unpacks one entry of a 7z archive into a file. The name is matched exactly, with
	 * no wildcards.
	 */
	public function extractSevenZip( string $path, string $name, string $destination, int $maxBytes ): Status {
		$allowed = $this->getAllowedOutput( $path, $maxBytes );
		$command = Shell::escape(
			$this->getBinary( self::SEVEN_ZIP ),
			'e',
			'-so',
			'-spd',
			'-y',
			'-bd',
			self::SEVEN_ZIP_INPUT,
			'--',
			$name
		);

		$run = $this->run(
			self::SEVEN_ZIP,
			$path,
			self::SEVEN_ZIP_INPUT,
			$this->limitOutput( $command, $allowed + 1 ),
			$destination,
			$allowed + self::SIZE_LIMIT_SLACK
		);

		return $this->checkOutput( $run, $destination, $allowed, $maxBytes );
	}

	/**
	 * Unpacks every file of a 7z archive into one file, one after the other in the order of the listing.
	 *
	 * @return Status A good status with the number of bytes that were written as its value.
	 */
	public function extractAllSevenZip( string $path, string $destination, int $maxBytes ): Status {
		$allowed = $this->getAllowedOutput( $path, $maxBytes );
		$command = Shell::escape(
			$this->getBinary( self::SEVEN_ZIP ),
			'e',
			'-so',
			'-spd',
			'-y',
			'-bd',
			self::SEVEN_ZIP_INPUT
		);

		$run = $this->run(
			self::SEVEN_ZIP,
			$path,
			self::SEVEN_ZIP_INPUT,
			$this->limitOutput( $command, $allowed + 1 ),
			$destination,
			$allowed + self::SIZE_LIMIT_SLACK
		);

		return $this->checkOutput( $run, $destination, $allowed, $maxBytes );
	}

	/**
	 * Reads what the 7z program prints when it lists an archive in technical form. Anything that
	 * does not look exactly as expected makes the whole listing unusable, because a name that
	 * contains a line break could otherwise pass for another entry.
	 *
	 * @return ?list<array{path: string, size: int, kind: string, encrypted: bool, memory: int}>
	 */
	public function parseSevenZipListing( string $listing ): ?array {
		$entries = [];
		$record = [];

		foreach ( explode( "\n", $listing . "\n" ) as $line ) {
			if ( $line === '' ) {
				if ( $record ) {
					$entry = $this->buildSevenZipEntry( $record );
					if ( $entry === null ) {
						return null;
					}

					$entries[] = $entry;
					$record = [];
				}

				continue;
			}

			if ( !preg_match( '/^([A-Za-z][A-Za-z ]*?) = (.*)$/', $line, $matches ) ) {
				return null;
			}

			if ( isset( $record[$matches[1]] ) ) {
				return null;
			}

			$record[$matches[1]] = $matches[2];
		}

		return $entries;
	}

	/**
	 * @param array<string, string> $record
	 * @return ?array{path: string, size: int, kind: string, encrypted: bool, memory: int}
	 */
	private function buildSevenZipEntry( array $record ): ?array {
		foreach ( [ 'Path', 'Size', 'Attributes', 'Encrypted' ] as $key ) {
			if ( !isset( $record[$key] ) ) {
				return null;
			}
		}

		if ( $record['Path'] === '' || !in_array( $record['Encrypted'], [ '+', '-' ], true ) ) {
			return null;
		}

		// For example "A -rw-r--r--", with the letters first and the file mode after them.
		$attributes = preg_split( '/\s+/', trim( $record['Attributes'] ) ) ?: [];
		$letters = $attributes[0] ?? '';
		$mode = $attributes[1] ?? '';

		$kind = match ( true ) {
			str_contains( $letters, 'D' ) || str_starts_with( $mode, 'd' ) || ( $record['Folder'] ?? '' ) === '+' =>
				self::KIND_DIRECTORY,
			str_contains( $letters, 'L' ) || ( $mode !== '' && $mode[0] !== '-' ) => self::KIND_OTHER,
			default => self::KIND_FILE,
		};

		if ( $kind === self::KIND_FILE && !ctype_digit( $record['Size'] ) ) {
			return null;
		}

		return [
			'path' => $record['Path'],
			'size' => (int)$record['Size'],
			'kind' => $kind,
			'encrypted' => $record['Encrypted'] === '+',
			'memory' => $this->getSevenZipMemory( $record['Method'] ?? '' ),
		];
	}

	/**
	 * Works out the memory that is needed to unpack with the methods that an entry names, such as
	 * "LZMA2:26" or "BCJ LZMA2:96m" or "PPMD:o6:mem26". A bare number is the size as a power of two, and
	 * a number with k, m or g after it is the size in those units. Methods that are not known to need
	 * much memory, and anything that is not understood, count as none.
	 *
	 * @return int The most that any of the methods needs, in bytes.
	 */
	private function getSevenZipMemory( string $methods ): int {
		$most = 0;

		foreach ( preg_split( '/\s+/', trim( $methods ) ) ?: [] as $method ) {
			if ( !preg_match( '/^(?:LZMA2?:|PPMD:o\d+:mem)(\d+)([kmg]?)$/i', $method, $matches ) ) {
				continue;
			}

			$number = (int)$matches[1];
			$unit = strtolower( $matches[2] );
			if ( $unit === '' ) {
				$bytes = $number < 62 ? 1 << $number : PHP_INT_MAX;
			} else {
				$bytes = $number * match ( $unit ) {
					'k' => 1024,
					'm' => 1048576,
					default => 1073741824,
				};
			}

			$most = max( $most, $bytes );
		}

		return $most;
	}

	private function getBinary( string $format ): string {
		return $this->getSettings( $format )->binary;
	}

	private function xzCommand(): string {
		return Shell::escape(
			$this->getBinary( self::XZ ),
			'--decompress',
			'--stdout',
			'--memlimit-decompress=' . $this->getSettings( self::XZ )->dictionary,
			'--',
			self::XZ_INPUT
		);
	}

	/**
	 * Cuts what a command writes off at a number of bytes, so that the size of the output is known
	 * to be over a limit as soon as it is one byte more, without all of it being written. The
	 * command still counts as failed if it fails before that.
	 *
	 * @param string $command A command line that is already escaped.
	 * @param int $bytes
	 */
	private function limitOutput( string $command, int $bytes ): string {
		return Shell::escape(
			'/bin/bash',
			'-c',
			'set -o pipefail; ' . $command . ' < /dev/null | head -c ' . $bytes . ' > ' . self::OUTPUT
		);
	}

	/** The most that may be written, which is the lower of the limit and what a normal file would expand to. */
	private function getAllowedOutput( string $path, int $maxBytes ): int {
		return min(
			$maxBytes,
			max( ArchiveReader::RATIO_CHECK_AFTER, (int)filesize( $path ) * ArchiveReader::MAX_COMPRESSION_RATIO )
		);
	}

	/**
	 * Runs a command in the sandbox with a copy of the file as its only input.
	 *
	 * @param string $format The format, which decides the limits.
	 * @param string $path The file that is copied in.
	 * @param string $inputName
	 * @param string $command A command line that is already escaped.
	 * @param string $destination Where the file that the command writes is copied to.
	 * @param int $maxOutput Most bytes that the command may write.
	 * @return Status A good status with a BoxedResult as its value.
	 */
	private function run(
		string $format,
		string $path,
		string $inputName,
		string $command,
		string $destination,
		int $maxOutput
	): Status {
		$settings = $this->getSettings( $format );

		try {
			$boxed = $this->commandFactory->createBoxed()
				->routeName( self::ROUTE_NAME )
				->cpuTimeLimit( $settings->time )
				->wallTimeLimit( $settings->time )
				->memoryLimit( $settings->memory )
				->fileSizeLimit( $maxOutput )
				->disableNetwork()
				->privateUserNamespace()
				->firejailDefaultSeccomp()
				->noNewPrivs()
				->privateDev()
				->inputFileFromFile( $inputName, $path )
				->outputFileToFile( self::OUTPUT, $destination )
				->unsafeCommand( $command );

			if ( defined( 'MW_CONFIG_FILE' ) && realpath( MW_CONFIG_FILE ) !== false ) {
				$boxed->disallowedPaths( [ realpath( MW_CONFIG_FILE ) ] );
			}

			return Status::newGood( $boxed->execute() );
		} catch ( Exception $e ) {
			LoggerFactory::getInstance( 'ImportDump' )->error(
				'Could not run a program to read an archive: {message}',
				[ 'message' => $e->getMessage(), 'exception' => $e ]
			);

			return Status::newFatal( 'requestimport-archive-binary-unavailable' );
		}
	}

	/**
	 * Decides what a run that wrote a file means.
	 *
	 * @param Status $run
	 * @param string $destination
	 * @param int $allowed Most bytes that a normal file would have.
	 * @param int $maxBytes The limit that the wiki has set.
	 * @return Status A good status with the number of bytes as its value.
	 */
	private function checkOutput( Status $run, string $destination, int $allowed, int $maxBytes ): Status {
		if ( !$run->isOK() ) {
			return $run;
		}

		$result = $run->getValue();
		'@phan-var BoxedResult $result';

		$size = $result->wasReceived( self::OUTPUT ) && file_exists( $destination ) ?
			(int)filesize( $destination ) : 0;

		if ( $size > $allowed ) {
			return $allowed < $maxBytes ?
				Status::newFatal( 'requestimport-archive-ratio' ) :
				Status::newFatal( 'requestimport-archive-too-large', Message::sizeParam( $maxBytes ) );
		}

		if ( $result->getExitCode() !== 0 || !$result->wasReceived( self::OUTPUT ) ) {
			return Status::newFatal( 'requestimport-archive-unreadable' );
		}

		return Status::newGood( $size );
	}
}
