<?php

declare( strict_types = 1 );

namespace Miraheze\ImportDump\Api;

use MediaWiki\Api\ApiBase;
use MediaWiki\Api\ApiMain;
use Miraheze\ImportDump\Request\RequestManager;
use Wikimedia\ParamValidator\ParamValidator;
use Wikimedia\ParamValidator\TypeDef\IntegerDef;

/** Gives handlers what the script of an import has printed so far. */
class ApiRequestImportOutput extends ApiBase {

	public function __construct(
		ApiMain $mainModule,
		string $moduleName,
		private readonly RequestManager $requestManager,
	) {
		parent::__construct( $mainModule, $moduleName );
	}

	public function execute(): void {
		$this->requestManager->loadFromID( $this->extractRequestParams()['requestid'] );
		if ( !$this->requestManager->exists() ) {
			$this->dieWithError( 'importdump-unknown', 'notfound' );
		}

		$authority = $this->getAuthority();
		$allowed = $authority->isAllowed( 'handle-import-requests' ) &&
			$this->requestManager->isViewableBy( $authority );

		if ( !$allowed ) {
			$this->dieWithError( 'importdump-private', 'permissiondenied' );
		}

		$this->getResult()->addValue( null, $this->getModuleName(), [
			'running' => $this->requestManager->getStatus()->isRunning(),
			'output' => $this->requestManager->getImportOutput() ?? '',
		] );
	}

	/** @inheritDoc */
	public function getAllowedParams(): array {
		return [
			'requestid' => [
				ParamValidator::PARAM_TYPE => 'integer',
				ParamValidator::PARAM_REQUIRED => true,
				IntegerDef::PARAM_MIN => 1,
			],
		];
	}

	/** @inheritDoc */
	public function isInternal(): true {
		return true;
	}
}
