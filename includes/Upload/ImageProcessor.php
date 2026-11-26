<?php

declare( strict_types = 1 );

namespace Miraheze\ImportDump\Upload;

use Closure;
use MediaWiki\Config\ServiceOptions;
use MediaWiki\MainConfigNames;
use MediaWiki\Message\Message;
use MediaWiki\Status\Status;
use MediaWiki\Title\TitleFactory;
use MediaWiki\Upload\UploadVerification;
use MediaWiki\Utils\MWFileProps;
use Miraheze\ImportDump\ConfigNames;
use Wikimedia\FileBackend\FSFile\TempFSFile;
use Wikimedia\FileBackend\FSFile\TempFSFileFactory;
use Wikimedia\Mime\MimeAnalyzer;
use function array_keys;
use function array_map;
use function array_shift;
use function array_slice;
use function array_values;
use function basename;
use function count;
use function filesize;
use function in_array;
use function intdiv;
use function is_float;
use function is_int;
use function is_scalar;
use function is_string;
use function pathinfo;
use function preg_match;
use function str_starts_with;
use function strlen;
use function strtolower;
use const NS_FILE;
use const PATHINFO_EXTENSION;

/**
 * Unpacks and checks an archive of image files. Only the file types that the wiki accepts
 * for upload are kept, and the files are put in one flat list.
 * Names inside the archive are only used to find the file name, never as a path.
 *
 * A file that cannot be imported is left out and reported, and the rest is kept. That is
 * not done when too many are left out, as it is probably the wrong archive, or when a virus
 * is found, as then none of the archive can be trusted.
 */
class ImageProcessor {

	public const array CONSTRUCTOR_OPTIONS = [
		ConfigNames::MaxArchiveEntries,
		ConfigNames::MaxExtractedSize,
		ConfigNames::MaxSkippedImages,
		MainConfigNames::FileExtensions,
		MainConfigNames::ProhibitedFileExtensions,
	];

	public const string STAGE_EXTRACTING = 'extracting';

	/**
	 * The types that may keep a name with another extension. Formats that can hold script or markup,
	 * such as SVG, are left out, because browsers treat those by content when they are named wrongly.
	 */
	private const array RASTER_TYPES = [
		'image/png',
		'image/apng',
		'image/jpeg',
		'image/gif',
		'image/webp',
		'image/avif',
		'image/bmp',
		'image/x-ms-bmp',
		'image/tiff',
	];

	/** The most bytes that a file name may have, which is what the title of a file allows. */
	private const int MAX_NAME_LENGTH = 240;

	/** The most files that are named when the archive is rejected because of them. */
	private const int MAX_REPORTED = 10;

	private readonly MWFileProps $fileProps;

	public function __construct(
		private readonly ArchiveReader $archiveReader,
		private readonly MimeAnalyzer $mimeAnalyzer,
		private readonly ServiceOptions $options,
		private readonly TempFSFileFactory $tempFSFileFactory,
		private readonly TitleFactory $titleFactory,
		private readonly UploadVerification $uploadVerification,
	) {
		$options->assertRequiredOptions( self::CONSTRUCTOR_OPTIONS );
		$this->fileProps = new MWFileProps( $mimeAnalyzer );
	}

	/**
	 * @param string $path The uploaded archive.
	 * @param ?Closure( string, ?int ): void $onProgress Called with a STAGE_* constant and
	 *  the percentage done, which is null when it is not known.
	 * @param ?list<string> $allowedExtensions The extensions that the target wiki accepts,
	 *  or null to use the ones of this wiki. The extensions that are never accepted are the ones of this wiki.
	 * @return Status A good status with an ImageResult as its value.
	 */
	public function process(
		string $path,
		?Closure $onProgress,
		?array $allowedExtensions
	): Status {
		try {
			return $this->processArchive( $path, $onProgress, $allowedExtensions );
		} finally {
			// A TAR is read in one pass, so it is still open here and holds on to the file.
			$this->archiveReader->close();
		}
	}

	/**
	 * @param string $path
	 * @param ?Closure( string, ?int ): void $onProgress
	 * @param ?list<string> $allowedExtensions
	 */
	private function processArchive(
		string $path,
		?Closure $onProgress,
		?array $allowedExtensions
	): Status {
		$allowed = $this->getAllowedExtensions( $allowedExtensions );
		$prohibited = $this->getProhibitedExtensions();

		$format = $this->archiveReader->detectFormat( $path );
		if ( $format === null ) {
			return Status::newFatal( 'requestimport-images-not-archive' );
		}

		$maxSize = (int)$this->options->get( ConfigNames::MaxExtractedSize );
		$listed = $this->archiveReader->listFiles(
			$path,
			$format,
			(int)$this->options->get( ConfigNames::MaxArchiveEntries ),
			$maxSize
		);

		if ( !$listed->isOK() ) {
			return $listed;
		}

		$checked = $this->checkEntries( $listed->getValue(), $maxSize, $allowed, $prohibited );
		if ( !$checked->isOK() ) {
			return $checked;
		}

		[
			'entries' => $entries,
			'held' => $heldEntries,
			'skipped' => $skipped,
			'considered' => $considered,
		] = $checked->getValue();
		'@phan-var array<string, ArchiveEntry> $entries';
		'@phan-var array<string, ArchiveEntry> $heldEntries';
		'@phan-var list<SkippedFile> $skipped';
		'@phan-var int $considered';

		$files = [];
		$tempFiles = [];
		$renamed = [];
		$taken = $this->getTitleKeys( array_keys( $entries ) );
		$total = count( $entries );
		$position = 0;
		$extracted = 0;
		$size = 0;

		foreach ( $entries as $name => $entry ) {
			if ( $onProgress ) {
				$onProgress( self::STAGE_EXTRACTING, intdiv( $position++ * 100, $total ) );
			}

			$extension = strtolower( pathinfo( $name, PATHINFO_EXTENSION ) );
			$tempFile = $this->tempFSFileFactory->newTempFSFile( 'importdump-image', $extension );
			if ( !$tempFile ) {
				return Status::newFatal( 'requestimport-archive-unreadable' );
			}

			// Sizes that an archive states can be wrong, so what was written counts against the limit.
			$status = $this->archiveReader->extractTo(
				$path,
				$format,
				$entry,
				$tempFile->getPath(),
				$maxSize - $extracted,
				null
			);

			if ( !$status->isOK() ) {
				return $status;
			}

			$extracted += (int)filesize( $tempFile->getPath() );

			$virus = $this->uploadVerification->detectVirus( $tempFile->getPath() );
			if ( $virus ) {
				return Status::newFatal( 'uploadvirus', $virus );
			}

			$problem = $extension === '' ? null : $this->verifyFile( $name, $extension, $tempFile->getPath() );

			if ( $extension === '' ) {
				$fixed = $this->findName( $name, $tempFile->getPath(), $taken, $allowed, $prohibited );
				if ( $fixed instanceof SkippedFile ) {
					$problem = $fixed;
				} else {
					$fixedExtension = strtolower( pathinfo( $fixed, PATHINFO_EXTENSION ) );
					$problem = $this->verifyFile( $fixed, $fixedExtension, $tempFile->getPath() );
					if ( !$problem ) {
						$renamed[$name] = $fixed;
						$name = $fixed;
					}
				}
			} elseif ( $problem && $problem->reason === 'filetype-mime-mismatch' ) {
				// The name stays as it is, because pages link to it.
				$mime = $this->detectMime( $tempFile->getPath() );
				if ( in_array( $mime, self::RASTER_TYPES, true ) ) {
					$real = $this->findExtension( $mime, $allowed, $prohibited );
					if ( $real === null ) {
						$problem = new SkippedFile( $name, 'requestimport-images-skip-type', [ $mime ] );
					} elseif ( !$this->verifyFile( $name, $real, $tempFile->getPath() ) ) {
						$problem = null;
					}
				}
			}

			if ( $problem ) {
				// Only this file is deleted, and the others go on.
				$tempFile->purge();
				$skipped[] = $problem;
				continue;
			}

			$files[$name] = $tempFile->getPath();
			$tempFiles[] = $tempFile;
			$size += (int)filesize( $tempFile->getPath() );
		}

		$maxSkipped = (int)$this->options->get( ConfigNames::MaxSkippedImages );
		if ( $skipped && ( !$files || count( $skipped ) * 100 > $considered * $maxSkipped ) ) {
			return $this->tooManySkipped( $skipped, $considered );
		}

		$held = $this->extractHeld( $path, $format, $heldEntries, $tempFiles, $maxSize - $extracted );
		if ( !$held->isOK() ) {
			return $held;
		}

		return Status::newGood( new ImageResult( $files, $tempFiles, $skipped, $size, $renamed, $held->getValue() ) );
	}

	/**
	 * Extracts the files that are only left out because of their extension, as far as the extraction limit allows.
	 * They are not checked for their type here, which is done again if they are ever imported.
	 *
	 * @param string $path
	 * @param string $format
	 * @param array<string, ArchiveEntry> $entries
	 * @param list<TempFSFile> &$tempFiles
	 * @param int $remaining
	 * @return Status A good status with the name of each held file and the path that it was extracted to.
	 */
	private function extractHeld(
		string $path,
		string $format,
		array $entries,
		array &$tempFiles,
		int $remaining
	): Status {
		$held = [];
		foreach ( $entries as $name => $entry ) {
			$size = $entry->size ?? 0;
			if ( $size > $remaining ) {
				continue;
			}

			$extension = strtolower( pathinfo( $name, PATHINFO_EXTENSION ) );
			$tempFile = $this->tempFSFileFactory->newTempFSFile( 'importdump-held', $extension );
			if ( !$tempFile ) {
				return Status::newFatal( 'requestimport-archive-unreadable' );
			}

			$status = $this->archiveReader->extractTo(
				$path,
				$format,
				$entry,
				$tempFile->getPath(),
				$remaining,
				null
			);

			if ( !$status->isOK() ) {
				return $status;
			}

			$written = (int)filesize( $tempFile->getPath() );
			$virus = $this->uploadVerification->detectVirus( $tempFile->getPath() );
			if ( $virus ) {
				return Status::newFatal( 'uploadvirus', $virus );
			}

			$remaining -= $written;
			$tempFiles[] = $tempFile;
			$held[$name] = $tempFile->getPath();
		}

		return Status::newGood( $held );
	}

	/**
	 * @param list<SkippedFile> $skipped
	 * @param int $considered
	 */
	private function tooManySkipped( array $skipped, int $considered ): Status {
		$status = Status::newFatal( 'requestimport-images-too-many-skipped', count( $skipped ), $considered );
		foreach ( array_slice( $skipped, 0, self::MAX_REPORTED ) as $file ) {
			$status->fatal( 'requestimport-images-file-rejected', $file->name, Message::newFromKey(
				$file->reason,
				...$file->params
			) );
		}

		return $status;
	}

	/**
	 * Checks files that were held back for their extension against the extensions that are accepted now.
	 * They get the same checks as any other file, and are never accepted with a prohibited extension.
	 *
	 * @param array<string, string> $files The name of each file and the path of a local copy.
	 * @param ?list<string> $allowedExtensions The extensions that the target wiki accepts now, or null for the
	 *  ones of this wiki.
	 * @return array{accepted:array<string, string>,rejected:array<string, SkippedFile>} The accepted files
	 *  by name, and the reason for each of the others.
	 */
	public function checkHeld( array $files, ?array $allowedExtensions ): array {
		$allowed = $this->getAllowedExtensions( $allowedExtensions );
		$prohibited = $this->getProhibitedExtensions();

		$accepted = [];
		$rejected = [];
		foreach ( $files as $name => $path ) {
			$extension = strtolower( pathinfo( $name, PATHINFO_EXTENSION ) );
			if (
				$extension === '' ||
				in_array( $extension, $prohibited, true ) ||
				!in_array( $extension, $allowed, true )
			) {
				$rejected[$name] = new SkippedFile( $name, 'requestimport-images-skip-extension', [ $extension ] );
				continue;
			}

			$virus = $this->uploadVerification->detectVirus( $path );
			$problem = $virus ?
				new SkippedFile( $name, 'uploadvirus', [ $virus ] ) :
				$this->verifyFile( $name, $extension, $path );

			if ( $problem ) {
				$rejected[$name] = $problem;
				continue;
			}

			$accepted[$name] = $path;
		}

		return [ 'accepted' => $accepted, 'rejected' => $rejected ];
	}

	/**
	 * Hidden files are ignored. The others are kept if the wiki would accept them as an upload,
	 * under a name that cannot be mistaken for a path and that no earlier file has. The rest are skipped.
	 *
	 * @param list<ArchiveEntry> $entries
	 * @param int $maxSize
	 * @param list<string> $allowed The extensions that are accepted, in lowercase.
	 * @param list<string> $prohibited The extensions that are never accepted, in lowercase.
	 * @return Status A good status with an array as its value. It has the entries to extract by the name that
	 *  each file gets, the files that are only held for their extension, the files that are skipped already,
	 *  and how many files there are besides the hidden ones.
	 */
	private function checkEntries( array $entries, int $maxSize, array $allowed, array $prohibited ): Status {
		$keep = [];
		$hold = [];
		$skipped = [];
		$keys = [];
		$size = 0;
		$considered = 0;
		foreach ( $entries as $entry ) {
			$name = basename( $entry->name );
			if ( str_starts_with( $name, '.' ) ) {
				continue;
			}

			$considered++;
			if ( !$this->isValidName( $name ) ) {
				$skipped[] = new SkippedFile( $name, 'requestimport-images-skip-name', [] );
				continue;
			}

			// Without an extension, the type is found from the content when the file is extracted.
			$extension = strtolower( pathinfo( $name, PATHINFO_EXTENSION ) );
			if (
				$extension !== '' &&
				( in_array( $extension, $prohibited, true ) || !in_array( $extension, $allowed, true ) )
			) {
				$skipped[] = new SkippedFile( $name, 'requestimport-images-skip-extension', [ $extension ] );
				// Only an extension that is not prohibited can become accepted later.
				$holdKey = $this->titleFactory->makeTitleSafe( NS_FILE, $name )?->getDBkey();
				if ( !in_array( $extension, $prohibited, true ) && $holdKey !== null && !isset( $keys[$holdKey] ) ) {
					$keys[$holdKey] = true;
					$hold[$name] = $entry;
				}

				continue;
			}

			// Two names that make the same title would overwrite each other, such as a.png and A.png.
			$key = $this->titleFactory->makeTitleSafe( NS_FILE, $name )?->getDBkey();
			if ( $key === null ) {
				$skipped[] = new SkippedFile( $name, 'requestimport-images-skip-name', [] );
				continue;
			}

			if ( isset( $keys[$key] ) ) {
				$skipped[] = new SkippedFile( $name, 'requestimport-images-skip-duplicate', [] );
				continue;
			}

			$keys[$key] = true;
			$keep[$name] = $entry;
			$size += $entry->size ?? 0;
			if ( $size > $maxSize ) {
				return Status::newFatal( 'requestimport-archive-too-large', Message::sizeParam( $maxSize ) );
			}
		}

		if ( $considered === 0 ) {
			return Status::newFatal( 'requestimport-images-empty' );
		}

		return Status::newGood( [
			'entries' => $keep,
			'held' => $hold,
			'skipped' => $skipped,
			'considered' => $considered,
		] );
	}

	/**
	 * @param ?list<string> $allowedExtensions
	 * @return list<string>
	 */
	private function getAllowedExtensions( ?array $allowedExtensions ): array {
		return array_map(
			strtolower( ... ),
			$allowedExtensions ?? $this->options->get( MainConfigNames::FileExtensions )
		);
	}

	/** @return list<string> */
	private function getProhibitedExtensions(): array {
		return array_map(
			strtolower( ... ),
			$this->options->get( MainConfigNames::ProhibitedFileExtensions )
		);
	}

	private function detectMime( string $path ): string {
		$mime = $this->fileProps->getPropsFromPath( $path, true )['mime'] ?? '';
		return is_string( $mime ) ? $mime : '';
	}

	/**
	 * The first allowed extension that fits a detected type, or null when the wiki has none.
	 *
	 * @param string $mime
	 * @param list<string> $allowed The extensions that are accepted, in lowercase.
	 * @param list<string> $prohibited The extensions that are never accepted, in lowercase.
	 */
	private function findExtension( string $mime, array $allowed, array $prohibited ): ?string {
		if ( $mime === '' ) {
			return null;
		}

		$preferred = $this->mimeAnalyzer->getExtensionFromMimeTypeOrNull( $mime );
		$candidates = $preferred === null ? $allowed : [ strtolower( $preferred ), ...$allowed ];
		foreach ( $candidates as $extension ) {
			if (
				!in_array( $extension, $prohibited, true ) &&
				in_array( $extension, $allowed, true ) &&
				$this->mimeAnalyzer->isMatchingExtension( $extension, $mime )
			) {
				return $extension;
			}
		}

		return null;
	}

	/**
	 * Adds the extension of the detected type to a file that has none.
	 *
	 * @param string $name The name of the file.
	 * @param string $path Where the file was extracted to.
	 * @param array<string, true> &$taken The title keys of the names that are in use, which this adds to.
	 * @param list<string> $allowed The extensions that are accepted, in lowercase.
	 * @param list<string> $prohibited The extensions that are never accepted, in lowercase.
	 * @return string|SkippedFile The new name, or why the file has none, which is that no allowed
	 *  extension fits its content or that the name with the extension is already used.
	 */
	private function findName(
		string $name,
		string $path,
		array &$taken,
		array $allowed,
		array $prohibited
	): string|SkippedFile {
		$extension = $this->findExtension( $this->detectMime( $path ), $allowed, $prohibited );
		if ( $extension === null ) {
			return new SkippedFile( $name, 'requestimport-images-skip-no-extension', [] );
		}

		$fixed = "$name.$extension";
		$key = $this->titleFactory->makeTitleSafe( NS_FILE, $fixed )?->getDBkey();
		if ( $key === null || !$this->isValidName( $fixed ) ) {
			return new SkippedFile( $name, 'requestimport-images-skip-name', [] );
		}

		if ( isset( $taken[$key] ) ) {
			return new SkippedFile( $name, 'requestimport-images-skip-duplicate', [] );
		}

		$taken[$key] = true;
		return $fixed;
	}

	/**
	 * @param list<string> $names
	 * @return array<string, true>
	 */
	private function getTitleKeys( array $names ): array {
		$keys = [];
		foreach ( $names as $name ) {
			$key = $this->titleFactory->makeTitleSafe( NS_FILE, (string)$name )?->getDBkey();
			if ( $key !== null ) {
				$keys[$key] = true;
			}
		}

		return $keys;
	}

	/** Names are used as the file name in storage and by the import script, so they must be plain. */
	private function isValidName( string $name ): bool {
		return $name !== '' &&
			strlen( $name ) <= self::MAX_NAME_LENGTH &&
			!preg_match( '/[\x00-\x1f\x7f\\\\\/:]/', $name ) &&
			preg_match( '//u', $name ) === 1;
	}

	private function verifyFile( string $name, string $extension, string $path ): ?SkippedFile {
		$props = $this->fileProps->getPropsFromPath( $path, $extension );
		$result = $this->uploadVerification->verifyFile( $path, $extension, $props );
		if ( $result === true ) {
			return null;
		}

		$reason = (string)array_shift( $result );
		$params = array_map(
			static fn ( mixed $param ): string|int|float => is_int( $param ) || is_float( $param ) ?
				$param :
				( is_scalar( $param ) ? (string)$param : '' ),
			$result
		);

		return new SkippedFile( $name, $reason, array_values( $params ) );
	}
}
