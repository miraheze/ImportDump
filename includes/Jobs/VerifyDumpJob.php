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
use MediaWiki\User\User;
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
use Miraheze\ImportDump\Upload\SkippedFile;
use Wikimedia\FileBackend\FSFile\TempFSFile;
use function array_chunk;
use function array_filter;
use function array_flip;
use function array_keys;
use function array_map;
use function count;
use function filesize;
use function intdiv;
use function max;

/**
 * Checks an upload that was too big, or too risky, to check while the request was submitted.
 * Until then the request is 'verifying' and the file waits in the unverified directory, or
 * it is kept in the parts that it was uploaded in, which are joined here.
 */
class VerifyDumpJob extends Job {

	public const string JOB_NAME = 'ImportDumpVerifyDump';

	public const string STAGE_JOINING = 'joining';

	public const string STAGE_STORING = 'storing';

	private const int RECHECK_BATCH = 100;

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

		$this->verify( $requestID );
		return true;
	}

	private function processDump(
		int $requestID,
		?string $path,
		?RequestImportUploadFromChunks $upload,
		Closure $onProgress
	): void {
		$status = $path !== null ?
			$this->dumpProcessor->process( $path, $onProgress ) :
			Status::newFatal( 'requestimport-verify-missing' );

		if ( !$status->isOK() ) {
			$this->reject( $status );
			$upload?->purgeChunks();
			return;
		}

		$result = $status->getValue();
		'@phan-var DumpResult $result';

		// A file that was joined from parts is a local file, so it is stored like one that was extracted.
		$published = $upload || $result->wasExtracted() ?
			$this->requestManager->publishExtractedFile( $result->path ) :
			$this->requestManager->publishUnverifiedFile();

		if ( !$published->isOK() ) {
			$this->reject( $published );
			$upload?->purgeChunks();
			return;
		}

		// The parts are kept until the file is stored, so that a job that died can be run again.
		$upload?->purgeChunks();
		$this->requestManager->setHistory( $result->history );
		$this->requestManager->setRequirements( $result->requirements );
		$this->requestManager->setStatus( RequestStatus::Pending );
		$this->attentionNotifier->notify( $this->requestManager );
		$this->progress->clear( $requestID );
	}

	private function verify( int $requestID ): void {
		// Without an upload to check, this is the check of the files that were held back.
		if (
			$this->requestManager->getType() === RequestType::Images &&
			!$this->requestManager->hasUnverifiedUpload() &&
			$this->requestManager->getHeldImageCount() > 0
		) {
			$this->recheckHeldImages( $requestID );
			return;
		}

		$onProgress = fn ( string $stage, ?int $percent ) => $this->progress->set( $requestID, $stage, $percent );

		$parts = $this->requestManager->getPartsReference();
		$upload = $parts ? $this->getPartsUpload( $parts ) : null;
		if ( $parts && !$upload ) {
			$this->reject( Status::newFatal( 'requestimport-verify-missing' ) );
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

			if ( $this->requestManager->getType() === RequestType::Images ) {
				$this->processImages( $requestID, $path, $upload, $onProgress );
			} else {
				$this->processDump( $requestID, $path, $upload, $onProgress );
			}
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
			Status::newFatal( 'requestimport-verify-missing' );

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

		$held = 0;
		if ( $result->held && $this->requestManager->publishHeldImages( $result->held )->isOK() ) {
			$held = count( $result->held );
		} elseif ( $result->held ) {
			$this->requestManager->deleteAllHeldImages();
		}

		// The files that are left out are shown on the request, which the notification points to.
		$this->requestManager->setSkippedImages( $result->skipped, $held, count( $result->skipped ) );
		$this->requestManager->setStatus( RequestStatus::Pending );
		$this->attentionNotifier->notify( $this->requestManager );
		$this->progress->clear( $requestID );
	}

	/**
	 * Checks the files that were held back for their extension, with the extensions that the target
	 * wiki accepts now. The files that pass are added to the images of the request, the rest stay held.
	 */
	private function recheckHeldImages( int $requestID ): void {
		$allowed = $this->requestManager->getTargetFileExtensions();
		$names = $this->requestManager->getHeldImageNames();

		$count = (int)$this->requestManager->getFileCount();
		$stored = $this->requestManager->getFileSize();

		$released = [];
		$rejected = [];
		$size = 0;
		$total = count( $names );
		$done = 0;
		foreach ( array_chunk( $names, self::RECHECK_BATCH ) as $batch ) {
			$copies = $this->requestManager->getHeldImageCopies( $batch );
			$paths = array_map( static fn ( TempFSFile $copy ): string => $copy->getPath(), $copies );
			$checked = $this->imageProcessor->checkHeld( $paths, $allowed );

			foreach ( $checked['accepted'] as $name => $path ) {
				$size += (int)filesize( $path );
				$released[] = (string)$name;
			}

			$rejected += $checked['rejected'];
			foreach ( $copies as $copy ) {
				$copy->purge();
			}

			$done += count( $batch );
			$this->progress->set( $requestID, self::STAGE_STORING, intdiv( $done * 100, max( 1, $total ) ) );
		}

		if ( $released ) {
			$status = $this->requestManager->releaseHeldImages( $released );
			if ( !$status->isOK() ) {
				$this->requestManager->setStatus( RequestStatus::Pending );
				$this->progress->clear( $requestID );
				return;
			}
		}

		// Files that fail a check now are not worth keeping, as another extension would not change that.
		$remove = array_keys( array_filter(
			$rejected,
			static fn ( SkippedFile $file ): bool => $file->reason !== 'requestimport-images-skip-extension'
		) );

		if ( $remove ) {
			$this->requestManager->deleteHeldImages( $remove );
		}

		$gone = array_flip( $released );
		$stillSkipped = [];
		foreach ( $this->requestManager->getSkippedImages()['files'] as $file ) {
			if ( isset( $gone[$file->name] ) ) {
				continue;
			}

			$stillSkipped[] = $rejected[$file->name] ?? $file;
		}

		$skipped = $this->requestManager->getSkippedImages();
		$this->requestManager->setSkippedImages(
			$stillSkipped,
			max( 0, $this->requestManager->getHeldImageCount() - count( $released ) - count( $remove ) ),
			$skipped['total'] - count( $released )
		);

		if ( $released ) {
			$this->requestManager->setFileStats(
				$count + count( $released ),
				$stored + $size
			);
		}

		$this->requestManager->setStatus( RequestStatus::Pending );
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

		$type = $this->requestManager->getType() === RequestType::Images ? 'images' : 'xml';
		$upload = new RequestImportUploadFromChunks( $user, $type );
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
		$comment = $this->messageLocalizer->msg( 'requestimport-verification-failed', $reason )
			->inContentLanguage()
			->escaped();

		$this->requestManager->addComment( $comment, $user );
		$this->requestManager->sendNotification( $comment, 'requestimport-request-status-update', $user );
		$this->requestManager->setStatus( RequestStatus::Declined );
		$this->requestManager->logStatusUpdate( $reason, RequestStatus::Declined, $user );
	}
}
