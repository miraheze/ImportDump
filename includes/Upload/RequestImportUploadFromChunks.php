<?php

namespace Miraheze\ImportDump\Upload;

use InvalidArgumentException;
use MediaWiki\MediaWikiServices;
use MediaWiki\Upload\UploadBase;
use MediaWiki\Upload\UploadFromChunks;
use MediaWiki\User\User;

class RequestImportUploadFromChunks extends UploadFromChunks {

	private const array TYPES = [
		'xml' => [
			'extension' => 'xml',
			'mimeTypes' => [ 'application/xml', 'text/xml' ],
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

	/** @inheritDoc */
	protected function verifyPartialFile() {
		$virus = MediaWikiServices::getInstance()
			->getUploadVerification()
			->detectVirus( $this->mTempPath );

		return $virus ? [ 'uploadvirus', $virus ] : true;
	}

	/** @inheritDoc */
	public function verifyUpload() {
		if ( $this->isEmptyFile() ) {
			return [ 'status' => self::EMPTY_FILE ];
		}

		$maxSize = UploadBase::getMaxUploadSize( $this->getSourceType() );
		if ( $this->getFileSize() > $maxSize ) {
			return [
				'status' => self::FILE_TOO_LARGE,
				'max' => $maxSize,
			];
		}

		$mime = MediaWikiServices::getInstance()
			->getMimeAnalyzer()
			->guessMimeType( $this->getTempPath() );

		$definition = self::TYPES[$this->type];
		if ( !in_array( $mime, $definition['mimeTypes'], true ) ) {
			return [
				'status' => self::VERIFICATION_ERROR,
				'details' => [ 'filetype-mime-mismatch', $definition['extension'], $mime ],
			];
		}

		return [ 'status' => self::OK ];
	}
}
