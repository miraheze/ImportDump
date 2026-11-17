<?php

namespace Miraheze\ImportDump\Upload;

use MediaWiki\MediaWikiServices;
use MediaWiki\Upload\UploadBase;
use MediaWiki\Upload\UploadFromChunks;

class ImportDumpUploadFromChunks extends UploadFromChunks {

	public const string STASH_NAME = 'importdump.xml';

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

		if ( $mime !== 'application/xml' && $mime !== 'text/xml' ) {
			return [
				'status' => self::VERIFICATION_ERROR,
				'details' => [ 'filetype-mime-mismatch', 'xml', $mime ],
			];
		}

		return [ 'status' => self::OK ];
	}
}
