<?php

namespace Miraheze\ImportDump\Upload;

use Closure;
use MediaWiki\Config\ServiceOptions;
use MediaWiki\Status\Status;
use MediaWiki\Upload\UploadVerification;
use Miraheze\ImportDump\ConfigNames;
use Miraheze\ImportDump\RequestHistory;
use Wikimedia\FileBackend\FSFile\TempFSFileFactory;
use XMLReader;

/**
 * Unpacks and checks an uploaded dump. The upload may be a MediaWiki XML export,
 * or a ZIP or GZIP archive that holds exactly one.
 */
class DumpProcessor implements RequestHistory {

	public const array CONSTRUCTOR_OPTIONS = [
		ConfigNames::EnableHistoryDetection,
		ConfigNames::MaxArchiveEntries,
		ConfigNames::MaxExtractedSize,
	];

	public const array XML_MIME_TYPES = [ 'application/xml', 'text/xml' ];

	public const array ARCHIVE_MIME_TYPES = [ 'application/zip', 'application/gzip', 'application/x-gzip' ];

	public const array ACCEPTED_MIME_TYPES = [ ...self::XML_MIME_TYPES, ...self::ARCHIVE_MIME_TYPES ];

	/** Plain XML files up to this many bytes are checked while a request is submitted. */
	public const int INLINE_SIZE_LIMIT = 67108864;

	public const string STAGE_EXTRACTING = 'extracting';

	public const string STAGE_CHECKING = 'checking';

	private const string EXPORT_NAMESPACE = 'http://www.mediawiki.org/xml/export-';

	/** Share of pages with a missing parent revision above which the history is called partial. */
	private const float PARTIAL_HISTORY_THRESHOLD = 0.05;

	/** Pages with more revisions than this are left out of the history check. */
	private const int MAX_TRACKED_REVISIONS = 1000000;

	public function __construct(
		private readonly ArchiveReader $archiveReader,
		private readonly ServiceOptions $options,
		private readonly TempFSFileFactory $tempFSFileFactory,
		private readonly UploadVerification $uploadVerification,
	) {
		$options->assertRequiredOptions( self::CONSTRUCTOR_OPTIONS );
	}

	/**
	 * Extracts the XML if the upload is an archive, then checks that it is a MediaWiki
	 * export and whether it holds the full history of its pages.
	 *
	 * @param string $path The uploaded file.
	 * @param ?Closure( string, ?int ): void $onProgress Called with a STAGE_* constant and
	 *  the percentage done, which is null when it is not known.
	 * @return Status A good status with a DumpResult as its value.
	 */
	public function process( string $path, ?Closure $onProgress ): Status {
		$tempFile = null;
		$xmlPath = $path;

		$format = $this->archiveReader->detectFormat( $path );
		if ( $format !== null ) {
			$extracted = $this->extract( $path, $format, $onProgress );
			if ( !$extracted->isOK() ) {
				return $extracted;
			}

			$tempFile = $extracted->getValue();
			$xmlPath = $tempFile->getPath();

			$virus = $this->uploadVerification->detectVirus( $xmlPath );
			if ( $virus ) {
				return Status::newFatal( 'uploadvirus', $virus );
			}
		}

		$history = $this->inspect( $xmlPath, $onProgress );
		if ( !$history->isOK() ) {
			return $history;
		}

		return Status::newGood( new DumpResult( $xmlPath, $history->getValue(), $tempFile ) );
	}

	/**
	 * @param ?Closure( string, ?int ): void $onProgress
	 * @return Status A good status with a TempFSFile as its value.
	 */
	private function extract( string $path, string $format, ?Closure $onProgress ): Status {
		$listed = $this->archiveReader->listFiles(
			$path,
			$format,
			(int)$this->options->get( ConfigNames::MaxArchiveEntries )
		);

		if ( !$listed->isOK() ) {
			return $listed;
		}

		$entries = $listed->getValue();
		if ( count( $entries ) !== 1 ) {
			return Status::newFatal( 'importdump-archive-not-single' );
		}

		$tempFile = $this->tempFSFileFactory->newTempFSFile( 'importdump', 'xml' );
		if ( !$tempFile ) {
			return Status::newFatal( 'importdump-archive-unreadable' );
		}

		$status = $this->archiveReader->extractTo(
			$path,
			$format,
			$entries[0],
			$tempFile->getPath(),
			(int)$this->options->get( ConfigNames::MaxExtractedSize ),
			$onProgress ? static fn ( ?int $percent ) => $onProgress( self::STAGE_EXTRACTING, $percent ) : null
		);

		return $status->isOK() ? Status::newGood( $tempFile ) : $status;
	}

	/**
	 * @param ?Closure( string, ?int ): void $onProgress
	 * @return Status A good status with one of the HISTORY_* constants as its value,
	 *  or null if history detection is off.
	 */
	private function inspect( string $path, ?Closure $onProgress ): Status {
		$source = $onProgress ?
			ProgressStream::url( $path, static fn ( int $percent ) => $onProgress( self::STAGE_CHECKING, $percent ) ) :
			$path;

		$previous = libxml_use_internal_errors( true );
		libxml_clear_errors();

		$reader = new XMLReader();
		try {
			if ( !$reader->open( $source, null, LIBXML_NONET | LIBXML_COMPACT ) ) {
				return Status::newFatal( 'importdump-xml-invalid' );
			}

			return $this->readDump( $reader );
		} finally {
			$reader->close();
			if ( $onProgress ) {
				ProgressStream::release( $source );
			}

			libxml_clear_errors();
			libxml_use_internal_errors( $previous );
		}
	}

	/**
	 * Every revision in an export names its parent. If the parent is missing from the file,
	 * the export was cut down, for example to only the current revisions.
	 *
	 * @return Status A good status with one of the HISTORY_* constants as its value,
	 *  or null if history detection is off.
	 */
	private function readDump( XMLReader $reader ): Status {
		$detectHistory = (bool)$this->options->get( ConfigNames::EnableHistoryDetection );
		$sawRoot = false;
		$sawParent = false;
		$pages = 0;
		$pagesWithGaps = 0;

		$revisionIds = [];
		$parentIds = [];
		$tracked = $detectHistory;

		$more = $reader->read();
		while ( $more ) {
			if ( $reader->nodeType === XMLReader::DOC_TYPE ) {
				return Status::newFatal( 'importdump-xml-doctype' );
			}

			if ( $reader->nodeType === XMLReader::END_ELEMENT ) {
				if ( $reader->depth === 1 && $reader->localName === 'page' && $tracked ) {
					$pagesWithGaps += $this->hasMissingParent( $revisionIds, $parentIds ) ? 1 : 0;
				}

				$more = $reader->read();
				continue;
			}

			if ( $reader->nodeType !== XMLReader::ELEMENT ) {
				$more = $reader->read();
				continue;
			}

			$depth = $reader->depth;
			$name = $reader->localName;

			if ( $depth === 0 ) {
				if ( $name !== 'mediawiki' || !str_starts_with( $reader->namespaceURI, self::EXPORT_NAMESPACE ) ) {
					return Status::newFatal( 'importdump-xml-not-dump' );
				}

				$sawRoot = true;
				$more = $reader->read();
			} elseif ( $depth === 1 && $name === 'page' ) {
				$pages++;
				$revisionIds = [];
				$parentIds = [];
				$tracked = $detectHistory;
				$more = $reader->read();
			} elseif ( $depth === 2 && $name === 'revision' ) {
				$more = $reader->read();
			} else {
				if ( $detectHistory && $depth === 3 && ( $name === 'id' || $name === 'parentid' ) ) {
					$id = (int)$reader->readString();
					if ( $name === 'parentid' ) {
						$sawParent = true;
					}

					if ( count( $revisionIds ) + count( $parentIds ) >= self::MAX_TRACKED_REVISIONS ) {
						$tracked = false;
					} elseif ( $name === 'id' ) {
						$revisionIds[$id] = true;
					} else {
						$parentIds[] = $id;
					}
				}

				// Skips everything else, including the text of a revision.
				$more = $reader->next();
			}
		}

		foreach ( libxml_get_errors() as $error ) {
			if ( $error->level >= LIBXML_ERR_ERROR ) {
				return Status::newFatal( 'importdump-xml-invalid' );
			}
		}

		if ( !$sawRoot ) {
			return Status::newFatal( 'importdump-xml-not-dump' );
		}

		if ( $pages === 0 ) {
			return Status::newFatal( 'importdump-xml-no-pages' );
		}

		return Status::newGood(
			$detectHistory ? $this->classifyHistory( $sawParent, $pages, $pagesWithGaps ) : null
		);
	}

	/**
	 * @param array<int, true> $revisionIds
	 * @param int[] $parentIds
	 */
	private function hasMissingParent( array $revisionIds, array $parentIds ): bool {
		foreach ( $parentIds as $parentId ) {
			if ( $parentId > 0 && !isset( $revisionIds[$parentId] ) ) {
				return true;
			}
		}

		return false;
	}

	private function classifyHistory( bool $sawParent, int $pages, int $pagesWithGaps ): string {
		if ( !$sawParent ) {
			// Exports from before MediaWiki 1.25 do not name the parent revision.
			return self::HISTORY_UNKNOWN;
		}

		return $pagesWithGaps / $pages > self::PARTIAL_HISTORY_THRESHOLD ?
			self::HISTORY_PARTIAL :
			self::HISTORY_FULL;
	}
}
