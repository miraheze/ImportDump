<?php

namespace Miraheze\ImportDump\Upload;

use Closure;
use MediaWiki\Config\ServiceOptions;
use MediaWiki\Status\Status;
use MediaWiki\Upload\UploadVerification;
use Miraheze\ImportDump\ConfigNames;
use Miraheze\ImportDump\ExtensionRequirement;
use Miraheze\ImportDump\RequestHistory;
use Wikimedia\FileBackend\FSFile\TempFSFileFactory;
use XMLReader;

/**
 * Unpacks and checks an uploaded dump. The upload may be a MediaWiki XML export,
 * or a ZIP, TAR, GZIP or BZIP2 archive that holds exactly one. A TAR can be compressed with
 * GZIP or BZIP2. XZ and 7z archives are read too if the wiki has set up the programs for them.
 */
class DumpProcessor implements RequestHistory {

	public const array CONSTRUCTOR_OPTIONS = [
		ConfigNames::EnableHistoryDetection,
		ConfigNames::ExtensionRequirements,
		ConfigNames::MaxArchiveEntries,
		ConfigNames::MaxExtractedSize,
	];

	public const array XML_MIME_TYPES = [ 'application/xml', 'text/xml' ];

	/** The archives that PHP can read itself. */
	public const array ARCHIVE_MIME_TYPES = [
		'application/zip',
		'application/gzip',
		'application/x-bzip2',
		'application/x-gzip',
		'application/x-tar',
	];

	/** Plain XML files up to this many bytes are checked while a request is submitted. */
	public const int INLINE_SIZE_LIMIT = 67108864;

	public const string STAGE_EXTRACTING = 'extracting';

	public const string STAGE_CHECKING = 'checking';

	private const string EXPORT_NAMESPACE = 'http://www.mediawiki.org/xml/export-';

	/** Share of pages with a missing parent revision above which the history is called partial. */
	private const float PARTIAL_HISTORY_THRESHOLD = 0.05;

	/**
	 * Exports from this schema version on name the parent of every revision that has one,
	 * so a dump without any parent IDs only has first revisions.
	 */
	private const string PARENT_ID_SCHEMA_VERSION = '0.10';

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

	/** @return list<string> The ArchiveReader::FORMAT_* constants of the archives that can be read here. */
	public function getArchiveFormats(): array {
		return $this->archiveReader->getFormats();
	}

	/** @return list<string> The MIME types of all the archives that can be read on this wiki. */
	public function getArchiveMimeTypes(): array {
		return [ ...self::ARCHIVE_MIME_TYPES, ...$this->archiveReader->getExternalMimeTypes() ];
	}

	/** @return list<string> The MIME types of everything that can be uploaded on this wiki. */
	public function getAcceptedMimeTypes(): array {
		return [ ...self::XML_MIME_TYPES, ...$this->getArchiveMimeTypes() ];
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

		$inspected = $this->inspect( $xmlPath, $onProgress );
		if ( !$inspected->isOK() ) {
			return $inspected;
		}

		[ 'history' => $history, 'requirements' => $requirements ] = $inspected->getValue();
		return Status::newGood( new DumpResult( $xmlPath, $history, $requirements, $tempFile ) );
	}

	/**
	 * @param string $path
	 * @param string $format
	 * @param ?Closure( string, ?int ): void $onProgress
	 * @return Status A good status with a TempFSFile as its value.
	 */
	private function extract( string $path, string $format, ?Closure $onProgress ): Status {
		// Reading the list of a compressed TAR can take as long as extracting it.
		if ( $onProgress ) {
			$onProgress( self::STAGE_EXTRACTING, null );
		}

		$listed = $this->archiveReader->listFiles(
			$path,
			$format,
			(int)$this->options->get( ConfigNames::MaxArchiveEntries ),
			(int)$this->options->get( ConfigNames::MaxExtractedSize )
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
	 * @param string $path
	 * @param ?Closure( string, ?int ): void $onProgress
	 * @return Status A good status with an array as its value. See readDump().
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
	 * @return Status A good status with an array as its value. Its history is one of the
	 *  HISTORY_* constants, or null if history detection is off. Its requirements are the names
	 *  of the extensions that the latest revision of any page needs, each with whether it is
	 *  certain. It is for a content model, but not for text that only looks like a use.
	 */
	private function readDump( XMLReader $reader ): Status {
		$detectHistory = (bool)$this->options->get( ConfigNames::EnableHistoryDetection );
		$sawRoot = false;
		$sawParent = false;
		$schemaNamesParents = false;
		$pages = 0;
		$pagesWithGaps = 0;

		$requirements = ExtensionRequirement::fromConfig(
			(array)$this->options->get( ConfigNames::ExtensionRequirements )
		);
		$scanText = array_filter( $requirements, static fn ( ExtensionRequirement $requirement ): bool =>
			$requirement->patterns !== [] );
		$needed = [];
		$neededByRevision = [];

		$revisionIds = [];
		$parentIds = [];
		$tracked = $detectHistory;

		$more = $reader->read();
		while ( $more ) {
			if ( $reader->nodeType === XMLReader::DOC_TYPE ) {
				return Status::newFatal( 'importdump-xml-doctype' );
			}

			$skipChildren = false;
			if ( $reader->nodeType === XMLReader::END_ELEMENT ) {
				if ( $reader->depth === 1 && $reader->localName === 'page' ) {
					// Only the last revision counts, because it is the one that is current.
					foreach ( $neededByRevision as $name => $certain ) {
						$needed[$name] = ( $needed[$name] ?? false ) || $certain;
					}

					$neededByRevision = [];

					if ( $tracked ) {
						$pagesWithGaps += $this->hasMissingParent( $revisionIds, $parentIds ) ? 1 : 0;
					}
				}
			} elseif ( $reader->nodeType === XMLReader::ELEMENT ) {
				$depth = $reader->depth;
				$name = $reader->localName;

				if ( $depth === 0 ) {
					if ( $name !== 'mediawiki' || !str_starts_with( $reader->namespaceURI, self::EXPORT_NAMESPACE ) ) {
						return Status::newFatal( 'importdump-xml-not-dump' );
					}

					$sawRoot = true;
					$schemaNamesParents = version_compare(
						(string)$reader->getAttribute( 'version' ),
						self::PARENT_ID_SCHEMA_VERSION,
						'>='
					);
				} elseif ( $depth === 1 && $name === 'page' ) {
					$pages++;
					$neededByRevision = [];
					$revisionIds = [];
					$parentIds = [];
					$tracked = $detectHistory;
				} elseif ( $depth === 2 && $name === 'revision' ) {
					$neededByRevision = [];
				} else {
					if ( $depth === 3 && $name === 'model' ) {
						$model = $reader->readString();
						foreach ( $requirements as $requirement ) {
							foreach ( $requirement->models as $prefix ) {
								if ( $prefix !== '' && str_starts_with( $model, $prefix ) ) {
									// A content model is certain, unlike text that only looks like a use.
									$neededByRevision[$requirement->name] = true;
								}
							}
						}
					}

					if ( $depth === 3 && $name === 'text' && $scanText ) {
						$text = $reader->readString();
						foreach ( $scanText as $requirement ) {
							foreach ( $requirement->patterns as $pattern ) {
								if ( preg_match( $pattern, $text ) === 1 ) {
									$neededByRevision[$requirement->name] ??= false;
								}
							}
						}
					}

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
					$skipChildren = true;
				}
			}

			$more = $skipChildren ? $reader->next() : $reader->read();
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

		ksort( $needed );
		$names = [];
		foreach ( $needed as $name => $certain ) {
			$names[(string)$name] = $certain;
		}

		return Status::newGood( [
			'history' => $detectHistory ?
				$this->classifyHistory( $sawParent, $schemaNamesParents, $pages, $pagesWithGaps ) :
				null,
			'requirements' => $names,
		] );
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

	private function classifyHistory(
		bool $sawParent,
		bool $schemaNamesParents,
		int $pages,
		int $pagesWithGaps
	): string {
		if ( !$sawParent ) {
			// Older exports never name the parent revision, so nothing can be said. Newer ones
			// leave it out only for first revisions, which means that no page has more than one.
			return $schemaNamesParents ? self::HISTORY_FULL : self::HISTORY_UNKNOWN;
		}

		return $pagesWithGaps / $pages > self::PARTIAL_HISTORY_THRESHOLD ?
			self::HISTORY_PARTIAL :
			self::HISTORY_FULL;
	}
}
