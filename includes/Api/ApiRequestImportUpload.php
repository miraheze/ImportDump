<?php

namespace Miraheze\ImportDump\Api;

use MediaWiki\Api\ApiBase;
use MediaWiki\Api\ApiMain;
use MediaWiki\Api\ApiMessage;
use MediaWiki\Api\ApiUpload;
use MediaWiki\Message\Message;
use MediaWiki\Status\Status;
use MediaWiki\Upload\Exception\UploadStashException;
use MediaWiki\Upload\UploadBase;
use MediaWiki\Upload\UploadFromStash;
use MediaWiki\WikiMap\WikiMap;
use Miraheze\ImportDump\ConfigNames;
use Miraheze\ImportDump\Upload\RequestImportUploadFromChunks;
use Wikimedia\ParamValidator\ParamValidator;
use Wikimedia\ParamValidator\TypeDef\IntegerDef;
use Wikimedia\Rdbms\IConnectionProvider;

class ApiRequestImportUpload extends ApiBase {

	public function __construct(
		ApiMain $mainModule,
		string $moduleName,
		private readonly IConnectionProvider $connectionProvider
	) {
		parent::__construct( $mainModule, $moduleName );
	}

	public function execute(): void {
		$this->assertAvailable();

		$params = $this->extractRequestParams();
		$user = $this->getUser();

		$chunk = $this->getMain()->getUpload( 'chunk' );
		if ( !$chunk->exists() || $chunk->getError() ) {
			$this->dieWithError( 'apierror-invalid-chunk' );
		}

		$chunkPath = $chunk->getTempName();
		$chunkSize = $chunk->getSize();
		$offset = $params['offset'];
		$fileSize = $params['filesize'];
		$totalSoFar = $offset + $chunkSize;

		if ( $totalSoFar > $fileSize ) {
			$this->dieWithError( 'apierror-invalid-chunk' );
		}

		$minChunkSize = ApiUpload::getMinUploadChunkSize( $this->getConfig() );
		if ( $totalSoFar !== $fileSize && $chunkSize < $minChunkSize ) {
			$this->dieWithError( [
				'apierror-chunk-too-small',
				Message::numParam( $minChunkSize ),
			] );
		}

		$upload = new RequestImportUploadFromChunks( $user, $params['type'] );
		$fileKey = $params['filekey'];

		try {
			if ( $offset === 0 ) {
				if ( $fileKey !== null ) {
					$this->dieWithError( 'apierror-upload-filekeynotallowed', 'filekeynotallowed' );
				}

				$upload->initialize( $upload->getStashName(), $chunk );
				$status = $upload->tryStashFile( $user, true );
				if ( !$status->isGood() ) {
					$this->dieStatus( $status );
				}

				$fileKey = $status->getValue()->getFileKey();
			} else {
				if ( $fileKey === null ) {
					$this->dieWithError( 'apierror-upload-filekeyneeded', 'filekeyneeded' );
				}

				if ( !UploadFromStash::isValidKey( $fileKey ) ) {
					$this->dieWithError( 'apierror-invalid-file-key' );
				}

				$progress = UploadBase::getSessionStatus( $user, $fileKey );
				if ( !$progress ) {
					$this->dieWithError( 'apierror-stashfailed-nosession', 'stashfailed' );
				}

				if ( $progress['result'] !== 'Continue' || $progress['stage'] !== 'uploading' ) {
					$this->dieWithError( 'apierror-stashfailed-complete', 'stashfailed' );
				}

				if ( ( $progress['type'] ?? null ) !== $params['type'] ) {
					$this->dieWithError( 'importdump-apierror-type-mismatch', 'typemismatch' );
				}

				$upload->continueChunks( $upload->getStashName(), $fileKey, $chunk );
				$status = $upload->addChunk( $chunkPath, $chunkSize, $offset );
				if ( !$status->isGood() ) {
					$this->dieStatus( $status );
				}
			}

			if ( $totalSoFar !== $fileSize ) {
				UploadBase::setSessionStatus( $user, $fileKey, [
					'result' => 'Continue',
					'stage' => 'uploading',
					'type' => $params['type'],
					'offset' => $totalSoFar,
					'status' => Status::newGood(),
				] );

				$this->getResult()->addValue( null, $this->getModuleName(), [
					'result' => 'Continue',
					'offset' => $totalSoFar,
					'filekey' => $fileKey,
				] );

				return;
			}

			$this->finish( $upload, $fileKey );
		} catch ( UploadStashException $e ) {
			$this->dieWithException( $e, [
				'wrap' => new ApiMessage( 'apierror-stashexception', 'stashfailed' ),
			] );
		}
	}

	private function finish( RequestImportUploadFromChunks $upload, string $fileKey ): void {
		$user = $this->getUser();

		UploadBase::setSessionStatus( $user, $fileKey, [
			'result' => 'Poll',
			'stage' => 'assembling',
			'status' => Status::newGood(),
		] );

		$status = $upload->concatenateChunks();
		if ( !$status->isGood() ) {
			UploadBase::setSessionStatus( $user, $fileKey, [
				'result' => 'Failure',
				'stage' => 'assembling',
				'status' => $status,
			] );

			$this->dieStatus( $status );
		}

		$upload->stash->removeFile( $fileKey );
		$finalKey = $upload->getStashFile()->getFileKey();

		UploadBase::setSessionStatus( $user, $fileKey, [
			'result' => 'Success',
			'stage' => 'assembling',
			'filekey' => $finalKey,
			'status' => $status,
		] );

		$this->getResult()->addValue( null, $this->getModuleName(), [
			'result' => 'Success',
			'filekey' => $finalKey,
		] );
	}

	private function assertAvailable(): void {
		if ( !$this->getConfig()->get( ConfigNames::EnableChunkedUploads ) ) {
			$this->dieWithError( 'importdump-apierror-chunking-disabled', 'chunkingdisabled' );
		}

		$dbr = $this->connectionProvider->getReplicaDatabase( 'virtual-importdump' );
		if ( !WikiMap::isCurrentWikiDbDomain( $dbr->getDomainID() ) ) {
			$this->dieWithError( 'importdump-notcentral-text', 'notcentral' );
		}

		$this->checkUserRightsAny( 'request-import' );
		$this->checkUserRightsAny( 'upload' );
		$this->checkUserRightsAny( 'edit' );

		$user = $this->getUser();
		$block = $user->getBlock();
		if ( $block && ( $user->isBlockedFromUpload() || $block->appliesToRight( 'request-import' ) ) ) {
			$this->dieBlocked( $block );
		}

		if ( !UploadBase::isEnabled() ) {
			$this->dieWithError( 'uploaddisabled' );
		}
	}

	/** @inheritDoc */
	public function getAllowedParams(): array {
		$fileSize = [
			ParamValidator::PARAM_TYPE => 'integer',
			ParamValidator::PARAM_REQUIRED => true,
			IntegerDef::PARAM_MIN => 1,
		];

		$maxSize = RequestImportUploadFromChunks::getMaxUploadSize();
		if ( $maxSize < PHP_INT_MAX ) {
			$fileSize[IntegerDef::PARAM_MAX] = $maxSize;
			$fileSize[ApiBase::PARAM_RANGE_ENFORCE] = true;
		}

		return [
			'chunk' => [
				ParamValidator::PARAM_TYPE => 'upload',
			],
			'filekey' => [
				ParamValidator::PARAM_TYPE => 'string',
			],
			'filesize' => $fileSize,
			'offset' => [
				ParamValidator::PARAM_TYPE => 'integer',
				ParamValidator::PARAM_REQUIRED => true,
				IntegerDef::PARAM_MIN => 0,
			],
			'type' => [
				ParamValidator::PARAM_TYPE => RequestImportUploadFromChunks::getTypeNames(),
				ParamValidator::PARAM_REQUIRED => true,
			],
		];
	}

	/** @inheritDoc */
	public function mustBePosted(): true {
		return true;
	}

	/** @inheritDoc */
	public function isWriteMode(): true {
		return true;
	}

	/** @inheritDoc */
	public function isInternal(): true {
		return true;
	}

	/** @inheritDoc */
	public function needsToken(): string {
		return 'csrf';
	}
}
