<?php

namespace Miraheze\ImportDump\Jobs;

use Job;
use MediaWiki\Config\Config;
use MediaWiki\Context\RequestContext;
use MediaWiki\Extension\Notifications\Model\Event;
use MediaWiki\Registration\ExtensionRegistry;
use MediaWiki\SpecialPage\SpecialPage;
use MediaWiki\User\User;
use MediaWiki\User\UserFactory;
use MessageLocalizer;
use Miraheze\ImportDump\ConfigNames;
use Miraheze\ImportDump\ImportDumpRequestManager;
use Miraheze\ImportDump\ImportDumpStatus;

class ImportDumpNotifyJob extends Job
	implements ImportDumpStatus {

	public const JOB_NAME = 'ImportDumpNotifyJob';

	private readonly MessageLocalizer $messageLocalizer;

	private readonly int $requestID;
	private readonly string $jobError;
	private readonly string $username;
	private readonly string $status;

	public function __construct(
		array $params,
		private readonly ExtensionRegistry $extensionRegistry,
		private readonly Config $config,
		private readonly ImportDumpRequestManager $requestManager,
		private readonly UserFactory $userFactory
	) {
		parent::__construct( self::JOB_NAME, $params );

		$this->requestID = $params['requestid'];
		$this->status = $params['status'];
		$this->jobError = $params['joberror'];
		$this->username = $params['username'];

		$this->messageLocalizer = RequestContext::getMain();
	}

	/** @inheritDoc */
	public function run(): bool {
		if ( !$this->extensionRegistry->isLoaded( 'Echo' ) ) {
			return true;
		}

		$this->requestManager->loadFromID( $this->requestID );
		if ( $this->status === self::STATUS_COMPLETE ) {
			$this->notifyComplete();
		}

		if ( $this->status === self::STATUS_FAILED ) {
			$this->notifyFailed();
		}

		if ( $this->status === self::STATUS_INPROGRESS ) {
			$this->notifyStarted();
		}

		return true;
	}

	private function notifyComplete() {
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
		$this->requestManager->sendNotification( $comment, 'importdump-request-status-update', $commentUser );
		$this->requestManager->setStatus( self::STATUS_COMPLETE );
	}

	private function notifyFailed(): void {
		$notifiedUsers = array_filter(
			array_map(
				function ( string $userName ): ?User {
					return $this->userFactory->newFromName( $userName );
				}, $this->config->get( ConfigNames::UsersNotifiedOnFailedImports )
			)
		);

		$requestLink = SpecialPage::getTitleFor( 'RequestImportQueue', (string)$this->requestID )->getFullURL();
		foreach ( $notifiedUsers as $receiver ) {
			if (
				!$receiver->isAllowed( 'handle-import-requests' ) ||
				(
					$this->requestManager->isPrivate( forced: false ) &&
					!$receiver->isAllowed( 'view-private-import-requests' )
				)
			) {
				continue;
			}

			Event::create( [
				'type' => 'importdump-import-failed',
				'extra' => [
					'request-id' => $this->requestID,
					'request-url' => $requestLink,
					'reason' => $this->jobError,
					'notifyAgent' => true,
				],
				'agent' => $receiver,
			] );
		}

		$commentUser = User::newSystemUser( 'ImportDump Status Update' );
		$comment = $this->messageLocalizer->msg( 'importdump-import-failed-comment' )
			->inContentLanguage()
			->escaped();

		$this->requestManager->addComment( $comment, $commentUser );
		$this->requestManager->sendNotification( $comment, 'importdump-request-status-update', $commentUser );
		$this->requestManager->setStatus( self::STATUS_FAILED );
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

		$comment = $this->messageLocalizer->msg( 'importdump-status-updated', mb_strtolower( $statusMessage ) )
			->inContentLanguage()
			->escaped();

		$this->requestManager->addComment( $comment, $user );
		$this->requestManager->sendNotification( $comment, 'importdump-request-status-update', $user );
		$this->requestManager->setStatus( self::STATUS_INPROGRESS );
	}
}
