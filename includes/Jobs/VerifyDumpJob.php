<?php

declare( strict_types = 1 );

namespace Miraheze\ImportDump\Jobs;

use Closure;
use MediaWiki\Context\RequestContext;
use MediaWiki\JobQueue\Job;
use MediaWiki\Language\FormatterFactory;
use MediaWiki\Language\MessageLocalizer;
use MediaWiki\Request\WebRequestUpload;
use MediaWiki\Status\Status;
use MediaWiki\Upload\Exception\UploadStashException;
use MediaWiki\User\UserFactory;
use Miraheze\ImportDump\Notifications\AttentionNotifier;
use Miraheze\ImportDump\Progress\VerificationProgress;
use Miraheze\ImportDump\Request\RequestManager;
use Miraheze\ImportDump\Request\RequestStatus;
use Miraheze\ImportDump\Request\RequestType;
use Miraheze\ImportDump\Upload\DumpProcessor;
use Miraheze\ImportDump\Upload\DumpResult;
use Miraheze\ImportDump\Upload\ImageProcessor;
use Miraheze\ImportDump\Upload\ImageResult;
use Miraheze\ImportDump\Upload\RequestImportUploadFromChunks;
use Wikimedia\FileBackend\FSFile\TempFSFile;
use function count;

/**
 * Checks an upload that was too big, or too risky, to check while the request was submitted.
 * Until then the request is 'verifying' and the file waits in the unverified directory.
 */
class VerifyDumpJob extends Job {

	public const string JOB_NAME = 'ImportDumpVerifyDump';

	public const string STAGE_JOINING = 'joining';

	public const string STAGE_STORING = 'storing';

	private readonly MessageLocalizer $messageLocalizer;

	public function __construct(
		array $params,
		private readonly FormatterFactory $formatterFactory,
		private readonly AttentionNotifier $attentionNotifier,
		private readonly DumpProcessor $dumpProcessor,
		private readonly ImageProcessor $imageProcessor,
		private readonly RequestManager $requestManager,
		private readonly UserFactory $userFactory,
		private readonly VerificationProgress $progress,
	) {
		parent::__construct( self::JOB_NAME, $params );
		$this->removeDuplicates = true;
		$this->messageLocalizer = RequestContext::getMain();
	}

	/** @inheritDoc */
	public function run(): true {
		$requestID = (int)$this->params['requestid'];

		$this->requestManager->loadFromID( $requestID );
		if (
			!$this->requestManager->exists() ||
			$this->requestManager->getStatus() !== RequestStatus::Verifying
		) {
			return true;
		}

		if ( $this->requestManager->getType() === RequestType::Images ) {
			$this->verifyImages( $requestID );
			return true;
		}

		$reference = $this->requestManager->getLocalUnverifiedFile();
		$status = $reference ?
			$this->dumpProcessor->process(
				$reference->getPath(),
				fn ( string $stage, ?int $percent ) => $this->progress->set( $requestID, $stage, $percent )
			) :
			Status::newFatal( 'importdump-verify-missing' );

		if ( !$status->isOK() ) {
			$this->reject( $status );
			return true;
		}

		$result = $status->getValue();
		'@phan-var DumpResult $result';
		$published = $result->wasExtracted() ?
			$this->requestManager->publishExtractedFile( $result->path ) :
			$this->requestManager->publishUnverifiedFile();

		if ( !$published->isOK() ) {
			$this->reject( $published );
			return true;
		}

		$this->requestManager->setHistory( $result->history );
		$this->requestManager->setRequirements( $result->requirements );
		$this->requestManager->setStatus( RequestStatus::Pending );
		$this->attentionNotifier->notify( $this->requestManager );
		$this->progress->clear( $requestID );

		return true;
	}

	private function verifyImages( int $requestID ): void {
		$onProgress = fn ( string $stage, ?int $percent ) => $this->progress->set( $requestID, $stage, $percent );

		$parts = $this->requestManager->getPartsReference();
		$upload = $parts ? $this->getPartsUpload( $parts ) : null;
		if ( $parts && !$upload ) {
			$this->reject( Status::newFatal( 'importdump-verify-missing' ) );
			return;
		}

		$joined = null;
		try {
			if ( $upload ) {
				$joining = $upload->joinChunks(
					static fn ( int $percent ) => $onProgress( self::STAGE_JOINING, $percent )
				);

				if ( !$joining->isOK() ) {
					$this->reject( $joining );
					$upload->purgeChunks();
					return;
				}

				$joined = $joining->getValue();
				'@phan-var TempFSFile $joined';
				$path = $joined->getPath();
			} else {
				$reference = $this->requestManager->getLocalUnverifiedFile();
				$path = $reference?->getPath();
			}

			$this->processImages( $requestID, $path, $upload, $onProgress );
		} finally {
			// The joined file has to outlive the processing, and is not needed after it.
			$joined?->purge();
		}
	}

	private function processImages(
		int $requestID,
		?string $path,
		?RequestImportUploadFromChunks $upload,
		Closure $onProgress
	): void {
		$status = $path !== null ?
			$this->imageProcessor->process(
				$path,
				$onProgress,
				// The target wiki decides which extensions are accepted, when it tells us.
				$this->requestManager->getTargetFileExtensions()
			) :
			Status::newFatal( 'importdump-verify-missing' );

		if ( !$status->isOK() ) {
			$this->reject( $status );
			$upload?->purgeChunks();
			return;
		}

		$result = $status->getValue();
		'@phan-var ImageResult $result';
		$published = $this->requestManager->publishExtractedImages(
			$result->files,
			fn ( int $percent ) => $this->progress->set( $requestID, self::STAGE_STORING, $percent )
		);
		if ( !$published->isOK() ) {
			$this->reject( $published );
			$upload?->purgeChunks();
			return;
		}

		// The parts are kept until the images are stored, so that a job that died can be run again.
		$upload?->purgeChunks();
		$this->requestManager->setFileStats( count( $result->files ), $result->size );

		// The files that are left out are shown on the request, which the notification points to.
		$this->requestManager->setSkippedImages( $result->skipped );
		$this->requestManager->setStatus( RequestStatus::Pending );
		$this->attentionNotifier->notify( $this->requestManager );
		$this->progress->clear( $requestID );
	}

	/**
	 * @param array{filekey: string, user: int} $parts
	 * @return ?RequestImportUploadFromChunks The parts of the upload, or null if they are gone.
	 */
	private function getPartsUpload( array $parts ): ?RequestImportUploadFromChunks {
		$user = $this->userFactory->newFromId( $parts['user'] );
		if ( !$user->isRegistered() ) {
			return null;
		}

		$upload = new RequestImportUploadFromChunks( $user, 'images' );
		try {
			$upload->continueChunks(
				$upload->getStashName(),
				$parts['filekey'],
				new WebRequestUpload( RequestContext::getMain()->getRequest(), 'null' )
			);
		} catch ( UploadStashException ) {
			return null;
		}

		return $upload;
	}

	private function reject( Status $status ): void {
		if ( $this->requestManager->getType() === RequestType::Images ) {
			$this->requestManager->deleteFile();
		}

		$this->requestManager->deleteUnverifiedFile();
		$this->progress->clear( $this->requestManager->getID() );

		$user = User::newSystemUser( RequestManager::STATUS_UPDATE_USER );
		$reason = $this->formatterFactory->getStatusFormatter( $this->messageLocalizer )
			->getMessage( $status, [] )
			->inContentLanguage()
			->text();
		$comment = $this->messageLocalizer->msg( 'importdump-verification-failed', $reason )
			->inContentLanguage()
			->escaped();

		$this->requestManager->addComment( $comment, $user );
		$this->requestManager->sendNotification( $comment, 'requestimport-request-status-update', $user );
		$this->requestManager->setStatus( RequestStatus::Declined );
		$this->requestManager->logStatusUpdate( $reason, RequestStatus::Declined, $user );
	}
}
