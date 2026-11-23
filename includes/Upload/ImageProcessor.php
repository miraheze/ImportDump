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
	 * @return Status A good status with an ImageResult as its value.
	 */
	public function process( string $path, ?Closure $onProgress ): Status {
		$format = $this->archiveReader->detectFormat( $path );
		if ( $format === null ) {
			return Status::newFatal( 'importdump-images-not-archive' );
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

		$checked = $this->checkEntries( $listed->getValue(), $maxSize );
		if ( !$checked->isOK() ) {
			return $checked;
		}

		[ 'entries' => $entries, 'skipped' => $skipped, 'considered' => $considered ] = $checked->getValue();
		'@phan-var array<string, ArchiveEntry> $entries';
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
				return Status::newFatal( 'importdump-archive-unreadable' );
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

			$problem = $extension === '' ?
				new SkippedFile( $name, 'importdump-images-skip-extension', [] ) :
				$this->verifyFile( $name, $extension, $tempFile->getPath() );

			if ( $problem && $problem->reason === 'filetype-mime-mismatch' ) {
				// The name stays as it is, because pages link to it.
				$mime = $this->detectMime( $tempFile->getPath() );
				$real = in_array( $mime, self::RASTER_TYPES, true ) ? $this->findExtension( $mime ) : null;
				if ( $real !== null && !$this->verifyFile( $name, $real, $tempFile->getPath() ) ) {
					$problem = null;
				}
			} elseif ( $problem && $extension === '' ) {
				$fixed = $this->findName( $name, $tempFile->getPath(), $taken );
				if ( $fixed !== null ) {
					$fixedExtension = strtolower( pathinfo( $fixed, PATHINFO_EXTENSION ) );
					if ( !$this->verifyFile( $fixed, $fixedExtension, $tempFile->getPath() ) ) {
						$renamed[$name] = $fixed;
						$name = $fixed;
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

		return Status::newGood( new ImageResult( $files, $tempFiles, $skipped, $size, $renamed ) );
	}

	/**
	 * @param list<SkippedFile> $skipped
	 * @param int $considered
	 */
	private function tooManySkipped( array $skipped, int $considered ): Status {
		$status = Status::newFatal( 'importdump-images-too-many-skipped', count( $skipped ), $considered );
		foreach ( array_slice( $skipped, 0, self::MAX_REPORTED ) as $file ) {
			$status->fatal( 'importdump-images-file-rejected', $file->name, Message::newFromKey(
				$file->reason,
				...$file->params
			) );
		}

		return $status;
	}

	/**
	 * Hidden files are ignored. The others are kept if the wiki would accept them as an upload,
	 * under a name that cannot be mistaken for a path and that no earlier file has. The rest are skipped.
	 *
	 * @param list<ArchiveEntry> $entries
	 * @param int $maxSize
	 * @return Status A good status with an array as its value. It has the entries to extract by the name that
	 *  each file gets, the files that are skipped already, and how many files there are besides the hidden ones.
	 */
	private function checkEntries( array $entries, int $maxSize ): Status {
		$allowed = array_map( strtolower( ... ), $this->options->get( MainConfigNames::FileExtensions ) );
		$prohibited = array_map( strtolower( ... ), $this->options->get( MainConfigNames::ProhibitedFileExtensions ) );

		$keep = [];
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
				$skipped[] = new SkippedFile( $name, 'importdump-images-skip-name', [] );
				continue;
			}

			// Without an extension, the type is found from the content when the file is extracted.
			$extension = strtolower( pathinfo( $name, PATHINFO_EXTENSION ) );
			if (
				$extension !== '' &&
				( in_array( $extension, $prohibited, true ) || !in_array( $extension, $allowed, true ) )
			) {
				$skipped[] = new SkippedFile( $name, 'importdump-images-skip-extension', [] );
				continue;
			}

			// Two names that make the same title would overwrite each other, such as a.png and A.png.
			$key = $this->titleFactory->makeTitleSafe( NS_FILE, $name )?->getDBkey();
			if ( $key === null ) {
				$skipped[] = new SkippedFile( $name, 'importdump-images-skip-name', [] );
				continue;
			}

			if ( isset( $keys[$key] ) ) {
				$skipped[] = new SkippedFile( $name, 'importdump-images-skip-duplicate', [] );
				continue;
			}

			$keys[$key] = true;
			$keep[$name] = $entry;
			$size += $entry->size ?? 0;
			if ( $size > $maxSize ) {
				return Status::newFatal( 'importdump-archive-too-large', Message::sizeParam( $maxSize ) );
			}
		}

		if ( $considered === 0 ) {
			return Status::newFatal( 'importdump-images-empty' );
		}

		return Status::newGood( [ 'entries' => $keep, 'skipped' => $skipped, 'considered' => $considered ] );
	}

	private function detectMime( string $path ): string {
		$mime = $this->fileProps->getPropsFromPath( $path, true )['mime'] ?? '';
		return is_string( $mime ) ? $mime : '';
	}

	/** The first allowed extension that fits a detected type, or null when the wiki has none. */
	private function findExtension( string $mime ): ?string {
		if ( $mime === '' ) {
			return null;
		}

		$allowed = array_map( strtolower( ... ), $this->options->get( MainConfigNames::FileExtensions ) );
		$prohibited = array_map( strtolower( ... ), $this->options->get( MainConfigNames::ProhibitedFileExtensions ) );

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
	 * Adds the extension of the detected type to a file that has none. Null when there is no
	 * allowed one or the new name is already used.
	 *
	 * @param string $name The name of the file.
	 * @param string $path Where the file was extracted to.
	 * @param array<string, true> &$taken The title keys of the names that are in use, which this adds to.
	 * @return ?string
	 */
	private function findName( string $name, string $path, array &$taken ): ?string {
		$extension = $this->findExtension( $this->detectMime( $path ) );
		if ( $extension === null ) {
			return null;
		}

		$fixed = "$name.$extension";
		$key = $this->titleFactory->makeTitleSafe( NS_FILE, $fixed )?->getDBkey();
		if ( $key === null || isset( $taken[$key] ) || !$this->isValidName( $fixed ) ) {
			return null;
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
