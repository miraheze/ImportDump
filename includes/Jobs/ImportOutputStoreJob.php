<?php

declare( strict_types = 1 );

namespace Miraheze\ImportDump\Jobs;

use MediaWiki\JobQueue\Job;
use Miraheze\ImportDump\Request\RequestManager;

/**
 * Stores a part of what the script of an import printed. It runs on the wiki that stores the requests,
 * because the import itself runs on the wiki that is imported to, which has other storage.
 */
class ImportOutputStoreJob extends Job {

	public const string JOB_NAME = 'ImportOutputStoreJob';

	public function __construct(
		array $params,
		private readonly RequestManager $requestManager,
	) {
		parent::__construct( self::JOB_NAME, $params );
	}

	/** @inheritDoc */
	public function run(): bool {
		$this->requestManager->loadFromID( $this->params['requestid'] );
		if ( !$this->requestManager->exists() ) {
			return true;
		}

		$status = $this->requestManager->storeOutputPart( $this->params['part'], $this->params['text'] );
		if ( !$status->isOK() ) {
			$this->setLastError( 'Could not store the output of request ' . $this->params['requestid'] );
			return false;
		}

		return true;
	}
}
