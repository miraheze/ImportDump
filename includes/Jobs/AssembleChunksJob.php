<?php

declare( strict_types = 1 );

namespace Miraheze\ImportDump\Jobs;

use Exception;
use MediaWiki\Context\RequestContext;
use MediaWiki\Exception\MWExceptionHandler;
use MediaWiki\JobQueue\Job;
use MediaWiki\Logger\LoggerFactory;
use MediaWiki\Request\WebRequestUpload;
use MediaWiki\Status\Status;
use MediaWiki\Upload\Exception\UploadStashException;
use MediaWiki\Upload\UploadBase;
use Miraheze\ImportDump\Upload\RequestImportUploadFromChunks;
use UnexpectedValueException;
use Wikimedia\ScopedCallback;

use function get_class;
use function is_array;

class AssembleChunksJob extends Job {

	public const string JOB_NAME = 'ImportDumpAssembleChunks';

	public function __construct( array $params ) {
		parent::__construct( self::JOB_NAME, $params );
		$this->removeDuplicates = true;
	}

	/** @inheritDoc */
	public function run(): bool {
		$scope = RequestContext::importScopedSession( $this->params['session'] );
		$this->addTeardownCallback( static fn () => ScopedCallback::consume( $scope ) );

		$logger = LoggerFactory::getInstance( 'ImportDump' );
		$context = RequestContext::getMain();
		$user = $context->getUser();
		$fileKey = $this->params['filekey'];

		if ( !$user->isRegistered() ) {
			$this->setLastError( 'Could not load the author user from session.' );
			return false;
		}

		try {
			$startingStatus = UploadBase::getSessionStatus( $user, $fileKey );
			if (
				!$startingStatus ||
				( $startingStatus['result'] ?? '' ) !== 'Poll' ||
				( $startingStatus['stage'] ?? '' ) !== 'queued'
			) {
				$logger->warning( 'Tried to assemble a chunked upload in stage {stage}/{result}', [
					'stage' => $startingStatus['stage'] ?? '-',
					'result' => $startingStatus['result'] ?? '-',
					'filekey' => $fileKey,
					'user' => $user->getName(),
				] );

				if (
					$startingStatus &&
					( $startingStatus['stage'] ?? '' ) === 'assembling' &&
					( $startingStatus['result'] ?? '' ) !== 'Failure'
				) {
					$this->setLastError( __METHOD__ . ' already in progress' );
					return false;
				}
			}

			UploadBase::setSessionStatus( $user, $fileKey, [
				'result' => 'Poll',
				'stage' => 'assembling',
				'status' => Status::newGood(),
			] );

			$upload = new RequestImportUploadFromChunks( $user, $this->params['type'] );
			$upload->continueChunks(
				$upload->getStashName(),
				$fileKey,
				new WebRequestUpload( $context->getRequest(), 'null' )
			);

			if (
				isset( $this->params['filesize'] ) &&
				$this->params['filesize'] !== (int)$upload->getOffset()
			) {
				// The job can run before the API request has been committed.
				throw new UnexpectedValueException(
					'Stashed upload size does not match the job. The transaction may not be committed yet.'
				);
			}

			$status = $upload->concatenateChunks();
			if ( !$status->isGood() ) {
				UploadBase::setSessionStatus( $user, $fileKey, [
					'result' => 'Failure',
					'stage' => 'assembling',
					'status' => $status,
				] );

				$logger->info( 'Chunked upload assembly failed for {filekey}: {status}', [
					'filekey' => $fileKey,
					'user' => $user->getName(),
					'status' => (string)$status,
				] );

				// The chunks did not pass verification. The reason is stored
				// in the session status for the client, so the job itself did not fail.
				return true;
			}

			$newFileKey = $upload->getStashFile()->getFileKey();
			$upload->stash->removeFileNoAuth( $fileKey );
			$upload->cleanupTempFile();

			UploadBase::setSessionStatus( $user, $fileKey, [
				'result' => 'Success',
				'stage' => 'assembling',
				'filekey' => $newFileKey,
				'status' => Status::newGood(),
			] );

			$logger->info( '{filekey} was assembled into {newkey}', [
				'filekey' => $fileKey,
				'newkey' => $newFileKey,
				'user' => $user->getName(),
			] );
		} catch ( UploadStashException $e ) {
			UploadBase::setSessionStatus( $user, $fileKey, [
				'result' => 'Failure',
				'stage' => 'assembling',
				'status' => Status::newFatal( $e->getMessageObject() ),
			] );

			$this->setLastError( get_class( $e ) . ': ' . $e->getMessage() );
			return false;
		} catch ( Exception $e ) {
			UploadBase::setSessionStatus( $user, $fileKey, [
				'result' => 'Failure',
				'stage' => 'assembling',
				'status' => Status::newFatal( 'api-error-stashfailed' ),
			] );

			$this->setLastError( get_class( $e ) . ': ' . $e->getMessage() );
			MWExceptionHandler::rollbackPrimaryChangesAndLog( $e );
			return false;
		}

		return true;
	}

	/** @inheritDoc */
	public function getDeduplicationInfo(): array {
		$info = parent::getDeduplicationInfo();
		if ( is_array( $info['params'] ) ) {
			$info['params'] = [ 'filekey' => $info['params']['filekey'] ];
		}

		return $info;
	}

	/** @inheritDoc */
	public function allowRetries(): false {
		return false;
	}
}
