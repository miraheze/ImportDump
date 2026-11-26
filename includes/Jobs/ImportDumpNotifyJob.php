<?php

declare( strict_types = 1 );

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
use Miraheze\ImportDump\Request\RequestManager;
use Miraheze\ImportDump\Request\RequestStatus;
use Miraheze\ImportDump\Request\RequestType;
use function array_filter;
use function array_map;
use function array_values;
use function mb_strtolower;

class ImportDumpNotifyJob extends Job {

	public const string JOB_NAME = 'ImportDumpNotifyJob';

	private readonly MessageLocalizer $messageLocalizer;

	private readonly int $requestID;
	private readonly string $jobError;
	private readonly string $username;
	private readonly RequestStatus $status;

	public function __construct(
		array $params,
		private readonly Config $config,
		private readonly RequestManager $requestManager,
		private readonly NotificationService $notificationService,
		private readonly UserFactory $userFactory,
	) {
		parent::__construct( self::JOB_NAME, $params );

		$this->requestID = $params['requestid'];
		$this->status = RequestStatus::from( $params['status'] );
		$this->jobError = $params['joberror'];
		$this->username = $params['username'];

		$this->messageLocalizer = RequestContext::getMain();
	}

	/** @inheritDoc */
	public function run(): true {
		$this->requestManager->loadFromID( $this->requestID );
		if ( $this->status === RequestStatus::Complete ) {
			$this->notifyComplete();
		}

		if ( $this->status === RequestStatus::Failed || $this->status === RequestStatus::Partial ) {
			$this->notifyFailed( $this->status );
		}

		if ( $this->status === RequestStatus::InProgress ) {
			$this->notifyStarted();
		}

		return true;
	}

	private function notifyComplete(): void {
		if ( $this->requestManager->getStatus() === RequestStatus::Complete ) {
			// Don't renotify for a job that is already completed.
			return;
		}

		$commentUser = User::newSystemUser( RequestManager::STATUS_UPDATE_USER );
		$statusMessage = $this->messageLocalizer->msg( RequestStatus::Complete->getLabelKey() )
			->inContentLanguage()
			->text();

		$comment = $this->messageLocalizer->msg( 'requestimport-status-updated', mb_strtolower( $statusMessage ) )
			->inContentLanguage()
			->escaped();

		$this->requestManager->addComment( $comment, $commentUser );
		$this->requestManager->sendNotification( $comment, 'requestimport-request-status-update', $commentUser );
		$this->requestManager->setStatus( RequestStatus::Complete );
	}

	private function notifyFailed( RequestStatus $status ): void {
		$notifiedUsers = array_filter(
			array_map(
				fn ( string $userName ): ?User => $this->userFactory->newFromName( $userName ),
				$this->config->get( ConfigNames::UsersNotifiedOnFailedImports )
			)
		);

		$isPrivate = $this->requestManager->isPrivate();
		$receivers = array_values( array_filter(
			$notifiedUsers,
			static fn ( User $receiver ): bool => $receiver->isAllowed( 'handle-import-requests' ) &&
				( !$isPrivate || $receiver->isAllowed( 'view-private-import-requests' ) )
		) );

		$commentUser = User::newSystemUser( RequestManager::STATUS_UPDATE_USER );
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

		$commentKey = $this->requestManager->getType() === RequestType::Images && $status === RequestStatus::Failed ?
			'requestimport-images-failed-comment' :
			"requestimport-xml-{$status->value}-comment";

		$comment = $this->messageLocalizer->msg( $commentKey )
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
		$statusMessage = $this->messageLocalizer->msg( RequestStatus::InProgress->getLabelKey() )
			->inContentLanguage()
			->text();

		$comment = $this->messageLocalizer->msg(
			'requestimport-status-updated-by',
			mb_strtolower( $statusMessage ),
			$user->getName()
		)->inContentLanguage()->escaped();

		$this->requestManager->addComment( $comment, User::newSystemUser( RequestManager::STATUS_UPDATE_USER ) );
		$this->requestManager->sendNotification( $comment, 'requestimport-request-status-update', $user );
		$this->requestManager->setStatus( RequestStatus::InProgress );
	}
}
