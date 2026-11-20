<?php

namespace Miraheze\ImportDump\Jobs;

use MediaWiki\Config\Config;
use MediaWiki\Context\RequestContext;
use MediaWiki\JobQueue\Job;
use MediaWiki\Language\MessageLocalizer;
use MediaWiki\Notification\NotificationService;
use MediaWiki\Notification\RecipientSet;
use MediaWiki\SpecialPage\SpecialPage;
use MediaWiki\User\User;
use MediaWiki\User\UserFactory;
use Miraheze\ImportDump\ConfigNames;
use Miraheze\ImportDump\Notifications\RequestNotification;
use Miraheze\ImportDump\RequestManager;
use Miraheze\ImportDump\RequestStatus;

class ImportDumpNotifyJob extends Job
	implements RequestStatus {

	public const string JOB_NAME = 'ImportDumpNotifyJob';

	private readonly MessageLocalizer $messageLocalizer;

	private readonly int $requestID;
	private readonly string $jobError;
	private readonly string $username;
	private readonly string $status;

	public function __construct(
		array $params,
		private readonly Config $config,
		private readonly RequestManager $requestManager,
		private readonly NotificationService $notificationService,
		private readonly UserFactory $userFactory,
	) {
		parent::__construct( self::JOB_NAME, $params );

		$this->requestID = $params['requestid'];
		$this->status = $params['status'];
		$this->jobError = $params['joberror'];
		$this->username = $params['username'];

		$this->messageLocalizer = RequestContext::getMain();
	}

	/** @inheritDoc */
	public function run(): true {
		$this->requestManager->loadFromID( $this->requestID );
		if ( $this->status === self::STATUS_COMPLETE ) {
			$this->notifyComplete();
		}

		if ( $this->status === self::STATUS_FAILED || $this->status === self::STATUS_PARTIAL ) {
			$this->notifyFailed( $this->status );
		}

		if ( $this->status === self::STATUS_INPROGRESS ) {
			$this->notifyStarted();
		}

		return true;
	}

	private function notifyComplete(): void {
		if ( $this->requestManager->getStatus() === self::STATUS_COMPLETE ) {
			// Don't renotify for a job that is already completed.
			return;
		}

		$commentUser = User::newSystemUser( 'ImportDump Status Update' );
		$statusMessage = $this->messageLocalizer->msg( 'importdump-label-' . self::STATUS_COMPLETE )
			->inContentLanguage()
			->text();

		$comment = $this->messageLocalizer->msg( 'importdump-status-updated', mb_strtolower( $statusMessage ) )
			->inContentLanguage()
			->escaped();

		$this->requestManager->addComment( $comment, $commentUser );
		$this->requestManager->sendNotification( $comment, 'requestimport-request-status-update', $commentUser );
		$this->requestManager->setStatus( self::STATUS_COMPLETE );
	}

	private function notifyFailed( string $status ): void {
		$notifiedUsers = array_filter(
			array_map(
				fn ( string $userName ): ?User => $this->userFactory->newFromName( $userName ),
				$this->config->get( ConfigNames::UsersNotifiedOnFailedImports )
			)
		);

		$isPrivate = $this->requestManager->isPrivate( forced: false );
		$receivers = array_values( array_filter(
			$notifiedUsers,
			static fn ( User $receiver ): bool => $receiver->isAllowed( 'handle-import-requests' ) &&
				( !$isPrivate || $receiver->isAllowed( 'view-private-import-requests' ) )
		) );

		$commentUser = User::newSystemUser( 'ImportDump Status Update' );
		if ( $receivers ) {
			$requestLink = SpecialPage::getTitleFor( 'RequestImportQueue', (string)$this->requestID )->getFullURL();
			$this->notificationService->notify(
				new RequestNotification( 'requestimport-import-failed', $commentUser, [
					'request-id' => $this->requestID,
					'request-url' => $requestLink,
					'reason' => $this->jobError,
				] ),
				new RecipientSet( $receivers )
			);
		}

		$comment = $this->messageLocalizer->msg( "importdump-import-$status-comment" )
			->inContentLanguage()
			->escaped();

		$this->requestManager->addComment( $comment, $commentUser );
		$this->requestManager->sendNotification( $comment, 'requestimport-request-status-update', $commentUser );
		$this->requestManager->setStatus( $status );
	}

	private function notifyStarted(): void {
		$user = $this->userFactory->newFromName( $this->username );
		if ( !( $user instanceof User ) ) {
			$this->setLastError( '$user is not an instance of User' );
			return;
		}

		$this->requestManager->logStarted( $user );
		$statusMessage = $this->messageLocalizer->msg( 'importdump-label-' . self::STATUS_INPROGRESS )
			->inContentLanguage()
			->text();

		$comment = $this->messageLocalizer->msg(
			'importdump-status-updated-by',
			mb_strtolower( $statusMessage ),
			$user->getName()
		)->inContentLanguage()->escaped();

		$this->requestManager->addComment( $comment, User::newSystemUser( 'ImportDump Status Update' ) );
		$this->requestManager->sendNotification( $comment, 'requestimport-request-status-update', $user );
		$this->requestManager->setStatus( self::STATUS_INPROGRESS );
	}
}
