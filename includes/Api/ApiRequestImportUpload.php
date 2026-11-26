<?php

declare( strict_types = 1 );

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
use MediaWiki\Upload\UploadStashFile;
use MediaWiki\WikiMap\WikiMap;
use Miraheze\ImportDump\ConfigNames;
use Miraheze\ImportDump\Request\RequestManager;
use Miraheze\ImportDump\Upload\RequestImportUploadFromChunks;
use StatusValue;
use Wikimedia\ParamValidator\ParamValidator;
use Wikimedia\ParamValidator\TypeDef\IntegerDef;
use Wikimedia\Rdbms\IConnectionProvider;
use const PHP_INT_MAX;

class ApiRequestImportUpload extends ApiBase {

	/** The stage of an upload that finished and was kept in parts. */
	public const string STAGE_PARTS = 'parts';

	public function __construct(
		ApiMain $mainModule,
		string $moduleName,
		private readonly IConnectionProvider $connectionProvider,
	) {
		parent::__construct( $mainModule, $moduleName );
	}

	public function execute(): void {
		$this->assertAvailable();

		$params = $this->extractRequestParams();
		$user = $this->getUser();

		if ( $params['checkstatus'] ) {
			$this->checkStatus( $params );
			return;
		}

		foreach ( [ 'offset', 'filesize' ] as $required ) {
			if ( $params[$required] === null ) {
				$this->dieWithError( [ 'apierror-missingparam', $required ] );
			}
		}

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

				$stashFile = $status->getValue();
				'@phan-var UploadStashFile $stashFile';
				$fileKey = $stashFile->getFileKey();
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
					$this->dieWithError( 'requestimport-apierror-type-mismatch', 'typemismatch' );
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

			// The parts are joined when the upload is verified.
			$this->finishParts( $upload, $fileKey );
		} catch ( UploadStashException $e ) {
			$this->dieWithException( $e, [
				'wrap' => new ApiMessage( 'apierror-stashexception', 'stashfailed' ),
			] );
		}
	}

	/** @param array<string, mixed> $params */
	private function checkStatus( array $params ): void {
		$fileKey = $params['filekey'];
		if ( $fileKey === null || !UploadFromStash::isValidKey( $fileKey ) ) {
			$this->dieWithError( 'apierror-invalid-file-key' );
		}

		$progress = UploadBase::getSessionStatus( $this->getUser(), $fileKey );
		if ( !$progress ) {
			$this->dieWithError( 'apierror-upload-missingresult', 'missingresult' );
		}

		$status = $progress['status'];
		'@phan-var StatusValue $status';
		if ( !$status->isGood() ) {
			$this->dieStatusWithError( $status, 'stashfailed' );
		}

		$result = [
			'result' => $progress['result'],
			'stage' => $progress['stage'],
		];

		if ( isset( $progress['filekey'] ) ) {
			$result['filekey'] = $progress['filekey'];
		}

		if ( isset( $progress['offset'] ) ) {
			$result['offset'] = $progress['offset'];
		}

		$this->getResult()->addValue( null, $this->getModuleName(), $result );
	}

	private function dieStatusWithError( StatusValue $status, string $code ): never {
		$fatal = StatusValue::newGood();
		foreach ( $status->getMessages() as $error ) {
			$fatal->fatal( ApiMessage::create( $error, $code ) );
		}

		$this->dieStatus( $fatal );
	}

	/** The parts are kept as they are, and the key of the first one stands for all of them. */
	private function finishParts( RequestImportUploadFromChunks $upload, string $fileKey ): void {
		$status = $upload->verifyParts();
		if ( !$status->isOK() ) {
			$upload->purgeChunks();
			$this->dieStatus( $status );
		}

		UploadBase::setSessionStatus( $this->getUser(), $fileKey, [
			'result' => 'Success',
			'stage' => self::STAGE_PARTS,
			'filekey' => $fileKey,
			'status' => Status::newGood(),
		] );

		$this->getResult()->addValue( null, $this->getModuleName(), [
			'result' => 'Success',
			'filekey' => $fileKey,
		] );
	}

	private function assertAvailable(): void {
		if ( !$this->getConfig()->get( ConfigNames::EnableChunkedUploads ) ) {
			$this->dieWithError( 'requestimport-apierror-chunking-disabled', 'chunkingdisabled' );
		}

		$dbr = $this->connectionProvider->getReplicaDatabase( RequestManager::VIRTUAL_DOMAIN );
		if ( !WikiMap::isCurrentWikiDbDomain( $dbr->getDomainID() ) ) {
			$this->dieWithError( 'requestimport-notcentral-text', 'notcentral' );
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
			IntegerDef::PARAM_MIN => 1,
		];

		$maxSize = RequestImportUploadFromChunks::getMaxUploadSize();
		if ( $maxSize < PHP_INT_MAX ) {
			$fileSize[IntegerDef::PARAM_MAX] = $maxSize;
			$fileSize[IntegerDef::PARAM_IGNORE_RANGE] = false;
		}

		return [
			'checkstatus' => [
				ParamValidator::PARAM_TYPE => 'boolean',
				ParamValidator::PARAM_DEFAULT => false,
			],
			'chunk' => [
				ParamValidator::PARAM_TYPE => 'upload',
			],
			'filekey' => [
				ParamValidator::PARAM_TYPE => 'string',
			],
			'filesize' => $fileSize,
			'offset' => [
				ParamValidator::PARAM_TYPE => 'integer',
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
