<?php

namespace Miraheze\ImportDump;

use UploadBase as CoreUploadBase;

class UploadBase extends CoreUploadBase {

	/**
	 * @param string $tempPath
	 * @param int|null $fileSize
	 */
	public function setTempFile( string $tempPath, int $fileSize = null ) {
		parent::setTempFile( $tempPath, $fileSize );
	}

	/**
	 * @param WebRequest &$request
	 */
	public function initializeFromRequest( &$request ) {
		parent::initializeFromRequest( $request );
	}
}
