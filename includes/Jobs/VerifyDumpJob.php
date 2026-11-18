<?php

namespace Miraheze\ImportDump\Jobs;

use MediaWiki\Context\RequestContext;
use MediaWiki\JobQueue\Job;
use MediaWiki\Language\MessageLocalizer;
use MediaWiki\Status\Status;
use MediaWiki\User\User;
use Miraheze\ImportDump\RequestManager;
use Miraheze\ImportDump\RequestStatus;
use Miraheze\ImportDump\Upload\DumpProcessor;
use Miraheze\ImportDump\Upload\DumpResult;
use Miraheze\ImportDump\VerificationProgress;

/**
 * Checks an upload that was too big, or too risky, to check while the request was submitted.
 * Until then the request is 'verifying' and the file waits in the unverified directory.
 */
class VerifyDumpJob extends Job implements RequestStatus {

	public const string JOB_NAME = 'ImportDumpVerifyDump';

	private readonly MessageLocalizer $messageLocalizer;

	public function __construct(
		array $params,
		private readonly DumpProcessor $dumpProcessor,
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
			$this->requestManager->getStatus() !== self::STATUS_VERIFYING
		) {
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

		/** @var DumpResult $result */
		$result = $status->getValue();
		$published = $this->requestManager->publishVerifiedFile(
			$result->wasExtracted() ? $result->path : null
		);

		if ( !$published->isOK() ) {
			$this->reject( $published );
			return true;
		}

		$this->requestManager->setHistory( $result->history );
		$this->requestManager->setStatus( self::STATUS_PENDING );
		$this->progress->clear( $requestID );

		return true;
	}

	private function reject( Status $status ): void {
		$this->requestManager->deleteUnverifiedFile();
		$this->progress->clear( $this->requestManager->getID() );

		$user = User::newSystemUser( 'ImportDump Status Update' );
		$reason = $status->getMessage( false, false, 'en' )->inContentLanguage()->text();
		$comment = $this->messageLocalizer->msg( 'importdump-verification-failed', $reason )
			->inContentLanguage()
			->escaped();

		$this->requestManager->addComment( $comment, $user );
		$this->requestManager->sendNotification( $comment, 'importdump-request-status-update', $user );
		$this->requestManager->setStatus( self::STATUS_DECLINED );
		$this->requestManager->logStatusUpdate( $reason, self::STATUS_DECLINED, $user );
	}
}
