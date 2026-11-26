<?php

declare( strict_types = 1 );

namespace Miraheze\ImportDump\Upload;

use Closure;
use InvalidArgumentException;
use MediaWiki\MediaWikiServices;
use MediaWiki\Status\Status;
use MediaWiki\Upload\Exception\UploadStashException;
use MediaWiki\Upload\UploadFromChunks;
use MediaWiki\User\User;
use Miraheze\ImportDump\ConfigNames;
use StatusValue;
use Wikimedia\FileBackend\FSFile\TempFSFileFactory;
use function array_keys;
use function clearstatcache;
use function count;
use function fclose;
use function filesize;
use function fopen;
use function in_array;
use function intdiv;
use function stream_copy_to_stream;
use const MEDIATYPE_UNKNOWN;
use const PHP_INT_MAX;

class RequestImportUploadFromChunks extends UploadFromChunks {

	private const array TYPES = [
		'xml' => [
			'extension' => 'xml',
			'archivesOnly' => false,
		],
		// Archives of images can be of any of the types that archives can be, so only the content is checked.
		'images' => [
			'extension' => 'archive',
			'archivesOnly' => true,
		],
	];

	public function __construct(
		User $user,
		private readonly string $type
	) {
		if ( !isset( self::TYPES[$type] ) ) {
			throw new InvalidArgumentException( "Unknown upload type: $type" );
		}

		parent::__construct( $user );
	}

	/**
	 * @inheritDoc
	 * @param ?string $forType @phan-unused-param
	 */
	public static function getMaxUploadSize( $forType = null ) {
		$limit = MediaWikiServices::getInstance()
			->getMainConfig()
			->get( ConfigNames::MaxUploadSize );

		return $limit === null ? PHP_INT_MAX : (int)$limit;
	}

	/** @return list<string> */
	public static function getTypeNames(): array {
		return array_keys( self::TYPES );
	}

	public function getType(): string {
		return $this->type;
	}

	public function getStashName(): string {
		return 'requestimport.' . self::TYPES[$this->type]['extension'];
	}

	/**
	 * @inheritDoc
	 * The assembled file is scanned in full when the request is submitted. Scanning the
	 * growing file after each chunk would read it again and again.
	 */
	protected function verifyPartialFile() {
		return true;
	}

	/**
	 * The locations of the stored parts of a file that was uploaded in parts, in order. This is only
	 * known after continueChunks().
	 *
	 * @return list<string>
	 */
	public function getChunkPaths(): array {
		$repo = MediaWikiServices::getInstance()->getRepoGroup()->getLocalRepo();

		$paths = [];
		for ( $index = 0; $index <= (int)( $this->mChunkIndex ?? 0 ); $index++ ) {
			$key = $this->mFileKey . '.' . $index;
			$paths[] = $repo->getVirtualUrl( 'temp' ) . '/' . $repo->getHashPath( $key ) . $key;
		}

		return $paths;
	}

	/**
	 * Joins the stored parts into one local file, taking one part at a time so that the disk only holds
	 * the file and a single part. The parts are left where they are.
	 *
	 * @param ?Closure( int ): void $onProgress Called with the percentage of the parts that are joined.
	 * @return Status A good status with the TempFSFile as its value, which is deleted when it is destroyed.
	 */
	public function joinChunks( ?Closure $onProgress ): Status {
		$services = MediaWikiServices::getInstance();
		$repo = $services->getRepoGroup()->getLocalRepo();

		$factory = $services->get( 'RequestImport.TempFSFileFactory' );
		'@phan-var TempFSFileFactory $factory';
		$file = $factory->newTempFSFile( 'importdump-chunks', '' );
		$target = $file ? fopen( $file->getPath(), 'wb' ) : false;
		if ( !$file || $target === false ) {
			return Status::newFatal( 'requestimport-archive-unreadable' );
		}

		$paths = $this->getChunkPaths();
		$total = count( $paths );
		foreach ( $paths as $position => $path ) {
			$part = $repo->getLocalReference( $path );
			$source = $part ? fopen( $part->getPath(), 'rb' ) : false;
			if ( $source === false ) {
				fclose( $target );
				return Status::newFatal( 'requestimport-verify-missing' );
			}

			$copied = stream_copy_to_stream( $source, $target );
			fclose( $source );
			unset( $part );

			if ( $copied === false ) {
				fclose( $target );
				return Status::newFatal( 'requestimport-archive-unreadable' );
			}

			if ( $onProgress ) {
				$onProgress( intdiv( ( $position + 1 ) * 100, $total ) );
			}
		}

		fclose( $target );
		clearstatcache( true, $file->getPath() );
		if ( filesize( $file->getPath() ) !== (int)$this->getOffset() ) {
			return Status::newFatal( 'requestimport-verify-missing' );
		}

		return Status::newGood( $file );
	}

	/** Deletes the stored parts and the stash entry of the file that was uploaded in parts. */
	public function purgeChunks(): void {
		$repo = MediaWikiServices::getInstance()->getRepoGroup()->getLocalRepo();
		$repo->quickPurgeBatch( $this->getChunkPaths() );

		try {
			$this->stash->removeFileNoAuth( $this->mFileKey );
		} catch ( UploadStashException ) {
			// It is already gone, which is what was wanted.
		}
	}

	/**
	 * Checks the first part of a file that is kept in parts, which is where the type of the file shows.
	 * The whole file is never read here.
	 *
	 * @return StatusValue Good if the file can be taken, or what is wrong with it.
	 */
	public function verifyParts(): StatusValue {
		$maxSize = static::getMaxUploadSize();
		$size = (int)$this->getOffset();
		if ( $size <= 0 ) {
			return $this->convertVerifyErrorToStatus( [ 'status' => self::EMPTY_FILE ] );
		}

		if ( $size > $maxSize ) {
			return $this->convertVerifyErrorToStatus( [ 'status' => self::FILE_TOO_LARGE, 'max' => $maxSize ] );
		}

		$repo = MediaWikiServices::getInstance()->getRepoGroup()->getLocalRepo();
		$first = $repo->getLocalReference( $this->getChunkPaths()[0] );
		if ( !$first ) {
			return StatusValue::newFatal( 'requestimport-verify-missing' );
		}

		$error = $this->getTypeError( $first->getPath() );
		return $error ? $this->convertVerifyErrorToStatus( $error ) : StatusValue::newGood();
	}

	/** @return ?array{status: int, details: list<string>} What is wrong with the type of the file, if anything. */
	private function getTypeError( string $path ): ?array {
		$services = MediaWikiServices::getInstance();
		$mime = $services->getMimeAnalyzer()->guessMimeType( $path );

		$definition = self::TYPES[$this->type];
		$processor = $services->getService( 'RequestImport.DumpProcessor' );
		'@phan-var DumpProcessor $processor';

		$accepted = $definition['archivesOnly'] ?
			$processor->getArchiveMimeTypes() :
			$processor->getAcceptedMimeTypes();

		if ( in_array( $mime, $accepted, true ) ) {
			return null;
		}

		return [
			'status' => self::VERIFICATION_ERROR,
			'details' => [ 'filetype-mime-mismatch', $definition['extension'], $mime ],
		];
	}

	/** @inheritDoc */
	public function verifyUpload() {
		if ( $this->isEmptyFile() ) {
			return [ 'status' => self::EMPTY_FILE ];
		}

		$maxSize = static::getMaxUploadSize();
		if ( $this->getFileSize() > $maxSize ) {
			return [
				'status' => self::FILE_TOO_LARGE,
				'max' => $maxSize,
			];
		}

		$error = $this->getTypeError( $this->getTempPath() );
		if ( $error ) {
			return $error;
		}

		$mime = MediaWikiServices::getInstance()->getMimeAnalyzer()->guessMimeType( $this->getTempPath() );

		// Core hashes and inspects the whole file for these when they are missing, which takes
		// minutes of CPU for a large archive, and nothing here uses them.
		$this->mFileProps = [
			'fileExists' => true,
			'size' => $this->getFileSize(),
			'file-mime' => $mime,
			'mime' => $mime,
			'media_type' => MEDIATYPE_UNKNOWN,
			'metadata' => [],
			'sha1' => '',
			'width' => 0,
			'height' => 0,
			'bits' => 0,
		];

		return [ 'status' => self::OK ];
	}
}
