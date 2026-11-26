<?php

declare( strict_types = 1 );

namespace Miraheze\ImportDump\Upload;

use Closure;
use MediaWiki\Config\ServiceOptions;
use MediaWiki\Status\Status;
use MediaWiki\Upload\UploadVerification;
use Miraheze\ImportDump\ConfigNames;
use Miraheze\ImportDump\Request\RequestHistory;
use Miraheze\ImportDump\Requirements\ExtensionRequirement;
use Wikimedia\FileBackend\FSFile\TempFSFile;
use Wikimedia\FileBackend\FSFile\TempFSFileFactory;
use XMLReader;
use function array_any;
use function array_filter;
use function array_unique;
use function array_values;
use function count;
use function intdiv;
use function ksort;
use function libxml_clear_errors;
use function libxml_get_errors;
use function libxml_use_internal_errors;
use function pathinfo;
use function preg_match;
use function str_starts_with;
use function strnatcasecmp;
use function strtolower;
use function usort;
use function version_compare;
use const LIBXML_COMPACT;
use const LIBXML_ERR_ERROR;
use const LIBXML_NONET;
use const PATHINFO_EXTENSION;

/**
 * Unpacks and checks an uploaded dump. The upload may be a MediaWiki XML export,
 * or a ZIP, TAR, GZIP or BZIP2 archive that holds exactly one. A TAR can be compressed with
 * GZIP or BZIP2. XZ and 7z archives are read too if the wiki has set up the programs for them.
 */
class DumpProcessor {

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

	/**
	 * Other names that browsers give to the archives. The file field only keeps a file whose type is in its
	 * list, and Windows names a ZIP something other than the other systems do.
	 */
	private const array CLIENT_MIME_ALIASES = [
		'application/zip' => [ 'application/x-zip-compressed', 'application/x-zip' ],
		'application/gzip' => [ 'application/x-compressed', 'application/x-compressed-tar', 'application/x-gtar' ],
		'application/x-bzip2' => [ 'application/bzip2' ],
		'application/x-xz' => [ 'application/xz' ],
	];

	/** Plain XML files up to this many bytes are checked while a request is submitted. */
	public const int INLINE_SIZE_LIMIT = 67108864;

	public const string STAGE_EXTRACTING = 'extracting';

	public const string STAGE_CHECKING = 'checking';

	public const string STAGE_MERGING = 'merging';

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

	/** @return list<string> The types that a browser may give to an archive that can be read on this wiki. */
	public function getArchiveClientMimeTypes(): array {
		return $this->addClientAliases( $this->getArchiveMimeTypes() );
	}

	/** @return list<string> The types that a browser may give to anything that can be uploaded on this wiki. */
	public function getAcceptedClientMimeTypes(): array {
		return $this->addClientAliases( $this->getAcceptedMimeTypes() );
	}

	/**
	 * @param list<string> $types
	 * @return list<string>
	 */
	private function addClientAliases( array $types ): array {
		$all = $types;
		foreach ( $types as $type ) {
			foreach ( self::CLIENT_MIME_ALIASES[$type] ?? [] as $alias ) {
				$all[] = $alias;
			}
		}

		return array_values( array_unique( $all ) );
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
		$merged = false;

		$format = $this->archiveReader->detectFormat( $path );
		if ( $format !== null ) {
			$extracted = $this->extract( $path, $format, $onProgress );
			if ( !$extracted->isOK() ) {
				return $extracted;
			}

			[ $tempFile, $merged ] = $extracted->getValue();
			'@phan-var TempFSFile $tempFile';
			'@phan-var bool $merged';
			$xmlPath = $tempFile->getPath();

			$virus = $this->uploadVerification->detectVirus( $xmlPath );
			if ( $virus ) {
				return Status::newFatal( 'uploadvirus', $virus );
			}
		}

		$inspected = $this->inspect( $xmlPath, $merged, $onProgress );
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
	 * @return Status A good status with a list as its value. It holds a TempFSFile and whether
	 *  several files were merged into it.
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
		if ( !$entries ) {
			return Status::newFatal( 'requestimport-archive-empty' );
		}

		if ( count( $entries ) > 1 ) {
			return $this->extractAndMerge( $path, $format, $entries, $onProgress );
		}

		$tempFile = $this->tempFSFileFactory->newTempFSFile( 'importdump', 'xml' );
		if ( !$tempFile ) {
			return Status::newFatal( 'requestimport-archive-unreadable' );
		}

		$status = $this->archiveReader->extractTo(
			$path,
			$format,
			$entries[0],
			$tempFile->getPath(),
			(int)$this->options->get( ConfigNames::MaxExtractedSize ),
			$onProgress ? static fn ( ?int $percent ) => $onProgress( self::STAGE_EXTRACTING, $percent ) : null
		);

		return $status->isOK() ? Status::newGood( [ $tempFile, false ] ) : $status;
	}

	/**
	 * An archive with more than one file must only have XML files. They are merged in the
	 * order of their names, which is how a dump that was split up is usually numbered.
	 *
	 * @param string $path
	 * @param string $format
	 * @param list<ArchiveEntry> $entries
	 * @param ?Closure( string, ?int ): void $onProgress
	 * @return Status A good status with a list as its value. See extract().
	 */
	private function extractAndMerge( string $path, string $format, array $entries, ?Closure $onProgress ): Status {
		foreach ( $entries as $entry ) {
			if ( strtolower( pathinfo( $entry->name, PATHINFO_EXTENSION ) ) !== 'xml' ) {
				return Status::newFatal( 'requestimport-archive-mixed' );
			}
		}

		usort( $entries, static fn ( ArchiveEntry $a, ArchiveEntry $b ): int =>
			strnatcasecmp( $a->name, $b->name ) ?: $a->index <=> $b->index );

		$maxSize = (int)$this->options->get( ConfigNames::MaxExtractedSize );
		$total = count( $entries );

		$merged = $this->tempFSFileFactory->newTempFSFile( 'importdump', 'xml' );
		if ( !$merged ) {
			return Status::newFatal( 'requestimport-archive-unreadable' );
		}

		// Each file is added and removed before the next is extracted, so only one is on disk
		// at a time besides the merged file, which may not go over the limit either.
		$merger = new XmlMerger( $merged->getPath(), $maxSize );
		foreach ( $entries as $position => $entry ) {
			if ( $onProgress ) {
				$onProgress( self::STAGE_EXTRACTING, intdiv( $position * 100, $total ) );
			}

			$tempFile = $this->tempFSFileFactory->newTempFSFile( 'importdump', 'xml' );
			if ( !$tempFile ) {
				return Status::newFatal( 'requestimport-archive-unreadable' );
			}

			$status = $this->archiveReader->extractTo(
				$path,
				$format,
				$entry,
				$tempFile->getPath(),
				$maxSize,
				null
			);

			if ( !$status->isOK() ) {
				return $status;
			}

			$virus = $this->uploadVerification->detectVirus( $tempFile->getPath() );
			if ( $virus ) {
				return Status::newFatal( 'uploadvirus', $virus );
			}

			if ( $onProgress ) {
				$onProgress( self::STAGE_MERGING, null );
			}

			$status = $merger->add( $tempFile->getPath() );
			if ( !$status->isOK() ) {
				return $status;
			}
		}

		$status = $merger->finish();
		return $status->isOK() ? Status::newGood( [ $merged, true ] ) : $status;
	}

	/**
	 * @param string $path
	 * @param bool $splitPages Whether a page may be split over several elements.
	 * @param ?Closure( string, ?int ): void $onProgress
	 * @return Status A good status with an array as its value. See readDump().
	 */
	private function inspect( string $path, bool $splitPages, ?Closure $onProgress ): Status {
		$source = $onProgress ?
			ProgressStream::url( $path, static fn ( int $percent ) => $onProgress( self::STAGE_CHECKING, $percent ) ) :
			$path;

		$previous = libxml_use_internal_errors( true );
		libxml_clear_errors();

		$reader = new XMLReader();
		try {
			if ( !$reader->open( $source, null, LIBXML_NONET | LIBXML_COMPACT ) ) {
				return Status::newFatal( 'requestimport-xml-invalid' );
			}

			return $this->readDump( $reader, $splitPages );
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
	 * An export puts all revisions of a page in one element, so each is settled as it ends and
	 * nothing is kept. Merged files can have a page in several elements, one for each file that
	 * has it. Only for those, pages are told apart by title and settled at the end, which costs
	 * memory for every revision, so history is unknown for a file that has too many.
	 *
	 * @param XMLReader $reader
	 * @param bool $splitPages Whether a page may be split over several elements.
	 * @return Status A good status with an array as its value. Its history is a RequestHistory,
	 *  or null if history detection is off. Its requirements are the names
	 *  of the extensions that the latest revision of any page needs, each with whether it is
	 *  certain. It is for a content model, but not for text that only looks like a use.
	 */
	private function readDump( XMLReader $reader, bool $splitPages ): Status {
		$detectHistory = (bool)$this->options->get( ConfigNames::EnableHistoryDetection );
		$sawRoot = false;
		$sawParent = false;
		$schemaNamesParents = false;
		$pages = 0;
		$pagesWithGaps = 0;
		$needed = [];

		$requirements = ExtensionRequirement::fromConfig(
			(array)$this->options->get( ConfigNames::ExtensionRequirements )
		);
		$scanText = array_filter( $requirements, static fn ( ExtensionRequirement $requirement ): bool =>
			$requirement->patterns !== [] );
		$neededByRevision = [];
		$sawRevision = false;
		$pageKey = '';

		$latestNeeds = [];
		$revisionIds = [];
		$parentIdsByPage = [];
		$tracked = $detectHistory;
		$trackedCount = 0;

		$more = $reader->read();
		while ( $more ) {
			if ( $reader->nodeType === XMLReader::DOC_TYPE ) {
				return Status::newFatal( 'requestimport-xml-doctype' );
			}

			$skipChildren = false;
			if ( $reader->nodeType === XMLReader::END_ELEMENT ) {
				if ( $reader->depth === 1 && $reader->localName === 'page' ) {
					// Only the last revision counts, because it is the one that is current.
					if ( !$splitPages ) {
						foreach ( $neededByRevision as $name => $certain ) {
							$needed[$name] = ( $needed[$name] ?? false ) || $certain;
						}
					} elseif ( $sawRevision ) {
						// A later element of the same page holds later revisions, so it replaces an earlier one.
						if ( $neededByRevision ) {
							$latestNeeds[$pageKey] = $neededByRevision;
						} else {
							unset( $latestNeeds[$pageKey] );
						}
					}

					$neededByRevision = [];
					if ( $tracked ) {
						$parentIdsByPage[$pageKey] ??= [];
						if ( !$splitPages ) {
							$pagesWithGaps += $this->countPagesWithGaps( $revisionIds, $parentIdsByPage );
						}
					}

					if ( !$splitPages ) {
						$revisionIds = [];
						$parentIdsByPage = [];
					}
				}
			} elseif ( $reader->nodeType === XMLReader::ELEMENT ) {
				$depth = $reader->depth;
				$name = $reader->localName;

				if ( $depth === 0 ) {
					if ( $name !== 'mediawiki' || !str_starts_with( $reader->namespaceURI, self::EXPORT_NAMESPACE ) ) {
						return Status::newFatal( 'requestimport-xml-not-dump' );
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
					$sawRevision = false;
					// Used only until the title is read. A title cannot hold a null byte.
					$pageKey = "\0" . $pages;
					if ( !$splitPages ) {
						$trackedCount = 0;
						$tracked = $detectHistory;
					}
				} elseif ( $splitPages && $depth === 2 && $name === 'title' ) {
					$pageKey = $reader->readString();
					$skipChildren = true;
				} elseif ( $depth === 2 && $name === 'revision' ) {
					$neededByRevision = [];
					$sawRevision = true;
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

						if ( $tracked ) {
							if ( $trackedCount >= self::MAX_TRACKED_REVISIONS ) {
								// Too much to hold. For a merged file nothing can be said about the history then.
								$tracked = false;
								$revisionIds = [];
								$parentIdsByPage = [];
							} elseif ( $name === 'id' ) {
								$revisionIds[$id] = true;
								$trackedCount++;
							} else {
								$parentIdsByPage[$pageKey][] = $id;
								$trackedCount++;
							}
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
				return Status::newFatal( 'requestimport-xml-invalid' );
			}
		}

		if ( !$sawRoot ) {
			return Status::newFatal( 'requestimport-xml-not-dump' );
		}

		if ( $pages === 0 ) {
			return Status::newFatal( 'requestimport-xml-no-pages' );
		}

		foreach ( $latestNeeds as $pageNeeds ) {
			foreach ( $pageNeeds as $name => $certain ) {
				$needed[$name] = ( $needed[$name] ?? false ) || $certain;
			}
		}

		ksort( $needed );
		$names = [];
		foreach ( $needed as $name => $certain ) {
			$names[(string)$name] = $certain;
		}

		$history = null;
		if ( $detectHistory ) {
			if ( !$splitPages ) {
				$history = $this->classifyHistory( $sawParent, $schemaNamesParents, $pages, $pagesWithGaps );
			} elseif ( $tracked ) {
				$history = $this->classifyHistory(
					$sawParent,
					$schemaNamesParents,
					count( $parentIdsByPage ),
					$this->countPagesWithGaps( $revisionIds, $parentIdsByPage )
				);
			} else {
				$history = RequestHistory::Unknown;
			}
		}

		return Status::newGood( [
			'history' => $history,
			'requirements' => $names,
		] );
	}

	/**
	 * @param array<int, true> $revisionIds Every revision in the file, whichever element it is in.
	 * @param array<string, int[]> $parentIdsByPage
	 */
	private function countPagesWithGaps( array $revisionIds, array $parentIdsByPage ): int {
		$gaps = 0;
		foreach ( $parentIdsByPage as $parentIds ) {
			$gaps += array_any(
				$parentIds,
				static fn ( int $parentId ): bool => $parentId > 0 && !isset( $revisionIds[$parentId] )
			) ? 1 : 0;
		}

		return $gaps;
	}

	private function classifyHistory(
		bool $sawParent,
		bool $schemaNamesParents,
		int $pages,
		int $pagesWithGaps
	): RequestHistory {
		if ( !$sawParent ) {
			// Older exports never name the parent revision, so nothing can be said. Newer ones
			// leave it out only for first revisions, which means that no page has more than one.
			return $schemaNamesParents ? RequestHistory::Full : RequestHistory::Unknown;
		}

		return $pagesWithGaps / $pages > self::PARTIAL_HISTORY_THRESHOLD ?
			RequestHistory::Partial :
			RequestHistory::Full;
	}
}
