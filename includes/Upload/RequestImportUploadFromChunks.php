<?php

namespace Miraheze\ImportDump\Upload;

use InvalidArgumentException;
use MediaWiki\MediaWikiServices;
use MediaWiki\Upload\UploadFromChunks;
use MediaWiki\User\User;
use Miraheze\ImportDump\ConfigNames;

class RequestImportUploadFromChunks extends UploadFromChunks {

	private const array TYPES = [
		'xml' => [
			'extension' => 'xml',
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

	/** @return string[] */
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

		$services = MediaWikiServices::getInstance();
		$mime = $services->getMimeAnalyzer()->guessMimeType( $this->getTempPath() );

		$definition = self::TYPES[$this->type];
		$processor = $services->getService( 'ImportDumpDumpProcessor' );
		'@phan-var DumpProcessor $processor';

		if ( !in_array( $mime, $processor->getAcceptedMimeTypes(), true ) ) {
			return [
				'status' => self::VERIFICATION_ERROR,
				'details' => [ 'filetype-mime-mismatch', $definition['extension'], $mime ],
			];
		}

		return [ 'status' => self::OK ];
	}
}
