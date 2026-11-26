<?php

declare( strict_types = 1 );

namespace Miraheze\ImportDump\Api;

use MediaWiki\Api\ApiBase;
use MediaWiki\Api\ApiMain;
use Miraheze\ImportDump\Request\RequestManager;
use Wikimedia\ParamValidator\ParamValidator;
use Wikimedia\ParamValidator\TypeDef\IntegerDef;
use function array_search;
use function count;

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
			$this->dieWithError( 'requestimport-unknown', 'notfound' );
		}

		if ( !$this->requestManager->isViewableBy( $this->getAuthority() ) ) {
			$this->dieWithError( 'requestimport-private', 'permissiondenied' );
		}

		$status = $this->requestManager->getStatus();
		if ( $status->isRunning() ) {
			$stage = $this->requestManager->getStage();
			$pages = $this->requestManager->getImportPages();
			$stages = $this->requestManager->getStages();

			$this->getResult()->addValue( null, $this->getModuleName(), [
				'status' => $status->value,
				'stage' => $stage?->value ?? '',
				'step' => $stage ? (int)array_search( $stage, $stages, true ) + 1 : 0,
				'steps' => count( $stages ),
				'percent' => $this->requestManager->getImportPercent(),
				'done' => $pages['done'] ?? null,
				'total' => $pages['total'] ?? null,
			] );

			return;
		}

		$progress = $this->requestManager->getVerificationProgress();
		$this->getResult()->addValue( null, $this->getModuleName(), [
			'status' => $status->value,
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
