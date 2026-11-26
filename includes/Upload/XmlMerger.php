<?php

declare( strict_types = 1 );

namespace Miraheze\ImportDump\Upload;

use MediaWiki\Message\Message;
use MediaWiki\Status\Status;
use XMLReader;
use XMLWriter;
use function clearstatcache;
use function filesize;
use function libxml_clear_errors;
use function libxml_get_errors;
use function libxml_use_internal_errors;
use function str_starts_with;
use const LIBXML_COMPACT;
use const LIBXML_ERR_ERROR;
use const LIBXML_NONET;

/**
 * Puts the pages and log items of several MediaWiki exports in one export. The site information
 * is the one of the first file, so all of the files must use the same version of the export format.
 *
 * The files are added one at a time, and a page is copied one revision at a time, so the most
 * that is held in memory is a single revision. Create one for each merge.
 */
class XmlMerger {

	private const string EXPORT_NAMESPACE = 'http://www.mediawiki.org/xml/export-';

	private ?XMLWriter $writer = null;
	private ?string $namespace = null;
	private ?string $version = null;

	/**
	 * @param string $destination The file to write.
	 * @param int $maxSize The most bytes that the merged file may have.
	 */
	public function __construct(
		private readonly string $destination,
		private readonly int $maxSize,
	) {
	}

	/** @param string $path The next export to merge. */
	public function add( string $path ): Status {
		$first = $this->writer === null;
		if ( $first ) {
			$writer = new XMLWriter();
			if ( !$writer->openUri( $this->destination ) ) {
				return Status::newFatal( 'requestimport-archive-unreadable' );
			}

			$writer->setIndent( false );
			$writer->startDocument( '1.0', 'UTF-8' );
			$this->writer = $writer;
		}

		$status = $this->copyQuietly( $path, $first );
		if ( !$status->isOK() ) {
			return $status;
		}

		$this->writer?->flush();
		clearstatcache( true, $this->destination );
		if ( (int)filesize( $this->destination ) > $this->maxSize ) {
			return Status::newFatal( 'requestimport-archive-too-large', Message::sizeParam( $this->maxSize ) );
		}

		return Status::newGood();
	}

	/** Finishes the merged file, which has to have at least one export in it. */
	public function finish(): Status {
		if ( $this->writer === null ) {
			return Status::newFatal( 'requestimport-xml-invalid' );
		}

		$this->writer->endElement();
		$this->writer->endDocument();
		$this->writer->flush();

		return Status::newGood();
	}

	/** Reads with libxml keeping its errors to itself, so that they can be checked afterwards. */
	private function copyQuietly( string $path, bool $first ): Status {
		$previous = libxml_use_internal_errors( true );
		try {
			return $this->copyFile( $path, $first );
		} finally {
			libxml_clear_errors();
			libxml_use_internal_errors( $previous );
		}
	}

	private function copyFile( string $path, bool $first ): Status {
		$reader = new XMLReader();
		if ( !$reader->open( $path, null, LIBXML_NONET | LIBXML_COMPACT ) ) {
			return Status::newFatal( 'requestimport-xml-invalid' );
		}

		try {
			$sawRoot = false;
			$more = $reader->read();
			while ( $more ) {
				if ( $reader->nodeType === XMLReader::DOC_TYPE ) {
					// A document type can declare entities, which an export never needs.
					return Status::newFatal( 'requestimport-xml-doctype' );
				}

				if ( $reader->nodeType === XMLReader::ELEMENT && $reader->depth === 1 ) {
					$name = $reader->localName;
					if ( $name === 'page' ) {
						if ( !$this->copyPage( $reader ) ) {
							return Status::newFatal( 'requestimport-xml-invalid' );
						}
					} elseif ( ( $first && $name === 'siteinfo' ) || $name === 'logitem' ) {
						$this->writer?->writeRaw( $reader->readOuterXml() );
					}

					$more = $reader->next();
				} else {
					if ( $reader->nodeType === XMLReader::ELEMENT && $reader->depth === 0 ) {
						$status = $this->startRoot( $reader, $first );
						if ( !$status->isOK() ) {
							return $status;
						}

						$sawRoot = true;
					}

					$more = $reader->read();
				}
			}

			foreach ( libxml_get_errors() as $error ) {
				if ( $error->level >= LIBXML_ERR_ERROR ) {
					return Status::newFatal( 'requestimport-xml-invalid' );
				}
			}

			return $sawRoot ? Status::newGood() : Status::newFatal( 'requestimport-xml-invalid' );
		} finally {
			$reader->close();
		}
	}

	/**
	 * Copies the page that the reader is on, one child at a time. The revisions are the children
	 * that can be big, so they are never held together.
	 *
	 * @return bool Whether the page was complete. The reader is left on its end.
	 */
	private function copyPage( XMLReader $reader ): bool {
		$depth = $reader->depth;
		$this->writer?->startElement( 'page' );
		if ( !$reader->isEmptyElement ) {
			$more = $reader->read();
			while ( $more && !( $reader->nodeType === XMLReader::END_ELEMENT && $reader->depth === $depth ) ) {
				if ( $reader->nodeType === XMLReader::ELEMENT && $reader->depth === $depth + 1 ) {
					$this->writer?->writeRaw( $reader->readOuterXml() );
					$more = $reader->next();
					continue;
				}

				$more = $reader->read();
			}

			if ( !$more ) {
				return false;
			}
		}

		$this->writer?->endElement();
		return true;
	}

	private function startRoot( XMLReader $reader, bool $first ): Status {
		$namespace = $reader->namespaceURI;
		if ( $reader->localName !== 'mediawiki' || !str_starts_with( $namespace, self::EXPORT_NAMESPACE ) ) {
			return Status::newFatal( 'requestimport-xml-invalid' );
		}

		$version = (string)$reader->getAttribute( 'version' );
		if ( !$first ) {
			return $namespace === $this->namespace && $version === $this->version ?
				Status::newGood() :
				Status::newFatal( 'requestimport-xml-version-mismatch' );
		}

		$this->namespace = $namespace;
		$this->version = $version;

		$this->writer?->startElement( 'mediawiki' );
		$this->writer?->writeAttribute( 'xmlns', $namespace );
		$this->writer?->writeAttribute( 'version', $version );
		$language = $reader->getAttributeNs( 'lang', 'http://www.w3.org/XML/1998/namespace' );
		if ( $language !== null ) {
			$this->writer?->writeAttributeNs( 'xml', 'lang', null, $language );
		}

		return Status::newGood();
	}
}
