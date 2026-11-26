<?php

declare( strict_types = 1 );

namespace Miraheze\ImportDump\Api;

use MediaWiki\Api\ApiBase;
use MediaWiki\Api\ApiMain;
use Miraheze\ImportDump\Request\RequestManager;
use Wikimedia\ParamValidator\ParamValidator;
use Wikimedia\ParamValidator\TypeDef\IntegerDef;

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
			$this->dieWithError( 'requestimport-unknown', 'notfound' );
		}

		$authority = $this->getAuthority();
		$allowed = $authority->isAllowed( 'handle-import-requests' ) &&
			$this->requestManager->isViewableBy( $authority );

		if ( !$allowed ) {
			$this->dieWithError( 'requestimport-private', 'permissiondenied' );
		}

		$part = $this->extractRequestParams()['part'];
		if ( $part !== null ) {
			$this->getResult()->addValue( null, $this->getModuleName(), [
				'part' => $part,
				'text' => $this->requestManager->getOutputPart( $part ) ?? '',
			] );

			return;
		}

		$result = [
			'running' => $this->requestManager->getStatus()->isRunning(),
			'parts' => $this->requestManager->getOutputParts(),
		];

		$live = $this->requestManager->getImportOutput();
		if ( $live !== null ) {
			$result['live'] = $live;
		}

		$this->getResult()->addValue( null, $this->getModuleName(), $result );
	}

	/** @inheritDoc */
	public function getAllowedParams(): array {
		return [
			'part' => [
				ParamValidator::PARAM_TYPE => 'integer',
				IntegerDef::PARAM_MIN => 0,
			],
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
