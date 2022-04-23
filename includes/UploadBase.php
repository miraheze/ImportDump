<?php

namespace Miraheze\ImportDump;

use UploadBase as CoreUploadBase;

class UploadBase extends CoreUploadBase {

	/**
	 * @param string $tempPath
	 */
	public function setTempPath( string $tempPath ) {
		$this->mTempPath = $tempPath;
	}
}
