<?php

declare( strict_types = 1 );

namespace Miraheze\ImportDump\Jobs;

use MediaWiki\Context\RequestContext;
use MediaWiki\JobQueue\Job;
use MediaWiki\Language\FormatterFactory;
use MediaWiki\Language\MessageLocalizer;
use MediaWiki\Status\Status;
use MediaWiki\User\User;
use Miraheze\ImportDump\Notifications\AttentionNotifier;
use Miraheze\ImportDump\Progress\VerificationProgress;
use Miraheze\ImportDump\Request\RequestManager;
use Miraheze\ImportDump\Request\RequestStatus;
use Miraheze\ImportDump\Request\RequestType;
use Miraheze\ImportDump\Upload\DumpProcessor;
use Miraheze\ImportDump\Upload\DumpResult;
use Miraheze\ImportDump\Upload\ImageProcessor;
use Miraheze\ImportDump\Upload\ImageResult;

/**
 * Checks an upload that was too big, or too risky, to check while the request was submitted.
 * Until then the request is 'verifying' and the file waits in the unverified directory.
 */
class VerifyDumpJob extends Job {

	public const string JOB_NAME = 'ImportDumpVerifyDump';

	private readonly MessageLocalizer $messageLocalizer;

	public function __construct(
		array $params,
		private readonly FormatterFactory $formatterFactory,
		private readonly AttentionNotifier $attentionNotifier,
		private readonly DumpProcessor $dumpProcessor,
		private readonly ImageProcessor $imageProcessor,
		private readonly RequestManager $requestManager,
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
		$reference = $this->requestManager->getLocalUnverifiedFile();
		$status = $reference ?
			$this->imageProcessor->process(
				$reference->getPath(),
				fn ( string $stage, ?int $percent ) => $this->progress->set( $requestID, $stage, $percent )
			) :
			Status::newFatal( 'importdump-verify-missing' );

		if ( !$status->isOK() ) {
			$this->reject( $status );
			return;
		}

		$result = $status->getValue();
		'@phan-var ImageResult $result';
		$published = $this->requestManager->publishExtractedImages( $result->files );
		if ( !$published->isOK() ) {
			$this->reject( $published );
			return;
		}

		// The files that are left out are shown on the request, which the notification points to.
		$this->requestManager->setSkippedImages( $result->skipped );
		$this->requestManager->setStatus( RequestStatus::Pending );
		$this->attentionNotifier->notify( $this->requestManager );
		$this->progress->clear( $requestID );
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
