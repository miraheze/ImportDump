<?php

namespace Miraheze\ImportDump\Api;

use MediaWiki\Api\ApiBase;
use MediaWiki\Api\ApiMain;
use Miraheze\ImportDump\RequestManager;
use Miraheze\ImportDump\RequestStage;
use Miraheze\ImportDump\RequestStatus;
use Wikimedia\ParamValidator\ParamValidator;
use Wikimedia\ParamValidator\TypeDef\IntegerDef;

class ApiRequestImportProgress extends ApiBase {

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

		if ( !$this->requestManager->isViewableBy( $this->getAuthority() ) ) {
			$this->dieWithError( 'importdump-private', 'permissiondenied' );
		}

		$status = $this->requestManager->getStatus();
		if ( $status === RequestStatus::STATUS_STARTING || $status === RequestStatus::STATUS_INPROGRESS ) {
			$stage = $this->requestManager->getStage();
			$position = array_search( $stage, RequestStage::STAGES, true );

			$this->getResult()->addValue( null, $this->getModuleName(), [
				'status' => $status,
				'stage' => $stage,
				'step' => $position === false ? 0 : $position + 1,
				'steps' => count( RequestStage::STAGES ),
				'percent' => $this->requestManager->getImportPercent(),
			] );

			return;
		}

		$progress = $this->requestManager->getVerificationProgress();
		$this->getResult()->addValue( null, $this->getModuleName(), [
			'status' => $this->requestManager->getStatus(),
			'stage' => $progress['stage'] ?? 'waiting',
			'percent' => $progress['percent'] ?? null,
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
