<?php

declare( strict_types = 1 );

namespace Miraheze\ImportDump\Upload;

use MediaWiki\Status\Status;
use XMLReader;
use XMLWriter;
use function in_array;
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
 */
class XmlMerger {

	private const string EXPORT_NAMESPACE = 'http://www.mediawiki.org/xml/export-';

	/** @var list<string> The elements below the root that are copied. */
	private const array COPIED = [ 'page', 'logitem' ];

	/**
	 * @param list<string> $paths The exports, in the order that they are merged.
	 * @param string $destination The file to write.
	 */
	public function merge( array $paths, string $destination ): Status {
		$writer = new XMLWriter();
		if ( !$writer->openUri( $destination ) ) {
			return Status::newFatal( 'importdump-archive-unreadable' );
		}

		$writer->setIndent( false );
		$writer->startDocument( '1.0', 'UTF-8' );

		$previous = libxml_use_internal_errors( true );
		try {
			$namespace = null;
			$version = null;
			foreach ( $paths as $index => $path ) {
				$status = $this->copyFile( $path, $writer, $index === 0, $namespace, $version );
				if ( !$status->isOK() ) {
					return $status;
				}
			}

			if ( $namespace === null ) {
				return Status::newFatal( 'importdump-xml-invalid' );
			}

			$writer->endElement();
			$writer->endDocument();
			$writer->flush();
		} finally {
			libxml_clear_errors();
			libxml_use_internal_errors( $previous );
		}

		return Status::newGood();
	}

	private function copyFile(
		string $path,
		XMLWriter $writer,
		bool $first,
		?string &$namespace,
		?string &$version
	): Status {
		$reader = new XMLReader();
		if ( !$reader->open( $path, null, LIBXML_NONET | LIBXML_COMPACT ) ) {
			return Status::newFatal( 'importdump-xml-invalid' );
		}

		try {
			$sawRoot = false;
			$more = $reader->read();
			while ( $more ) {
				if ( $reader->nodeType === XMLReader::DOC_TYPE ) {
					// A document type can declare entities, which an export never needs.
					return Status::newFatal( 'importdump-xml-doctype' );
				}

				if ( $reader->nodeType === XMLReader::ELEMENT && $reader->depth === 0 ) {
					$status = $this->checkRoot( $reader, $writer, $first, $namespace, $version );
					if ( !$status->isOK() ) {
						return $status;
					}

					$sawRoot = true;
				} elseif ( $reader->nodeType === XMLReader::ELEMENT && $reader->depth === 1 ) {
					$name = $reader->localName;
					if ( ( $first && $name === 'siteinfo' ) || in_array( $name, self::COPIED, true ) ) {
						$writer->writeRaw( $reader->readOuterXml() );
					}

					$more = $reader->next();
					continue;
				}

				$more = $reader->read();
			}

			foreach ( libxml_get_errors() as $error ) {
				if ( $error->level >= LIBXML_ERR_ERROR ) {
					return Status::newFatal( 'importdump-xml-invalid' );
				}
			}

			return $sawRoot ? Status::newGood() : Status::newFatal( 'importdump-xml-invalid' );
		} finally {
			$reader->close();
			libxml_clear_errors();
		}
	}

	private function checkRoot(
		XMLReader $reader,
		XMLWriter $writer,
		bool $first,
		?string &$namespace,
		?string &$version
	): Status {
		$rootNamespace = $reader->namespaceURI;
		if ( $reader->localName !== 'mediawiki' || !str_starts_with( $rootNamespace, self::EXPORT_NAMESPACE ) ) {
			return Status::newFatal( 'importdump-xml-invalid' );
		}

		$rootVersion = (string)$reader->getAttribute( 'version' );
		if ( !$first && ( $rootNamespace !== $namespace || $rootVersion !== $version ) ) {
			return Status::newFatal( 'importdump-xml-version-mismatch' );
		}

		if ( $first ) {
			$namespace = $rootNamespace;
			$version = $rootVersion;

			$writer->startElementNs( null, 'mediawiki', $rootNamespace );
			$writer->writeAttribute( 'version', $rootVersion );
			$language = $reader->getAttributeNs( 'lang', 'http://www.w3.org/XML/1998/namespace' );
			if ( $language !== null ) {
				$writer->writeAttributeNs( 'xml', 'lang', null, $language );
			}
		}

		return Status::newGood();
	}
}
