<?php

namespace Miraheze\ImportDump\Jobs;

use ExtensionRegistry;
use Job;
use MediaWiki\Config\Config;
use MediaWiki\Config\ConfigFactory;
use MediaWiki\Extension\Notifications\Model\Event;
use MediaWiki\SpecialPage\SpecialPage;
use MediaWiki\User\User;
use MediaWiki\User\UserFactory;
use MessageLocalizer;
use Miraheze\ImportDump\ImportDumpRequestManager;
use Miraheze\ImportDump\ImportDumpStatus;
use RequestContext;

class ImportDumpNotifyJob extends Job
	implements ImportDumpStatus {

	public const JOB_NAME = 'ImportDumpNotifyJob';

	/** @var int */
	private $requestID;

	/** @var string */
	private $username;

	/** @var string */
	private $status;

	/** @var string */
	private $lastError;

	/** @var Config */
	private $config;

	/** @var ImportDumpRequestManager */
	private $importDumpRequestManager;

	/** @var MessageLocalizer */
	private $messageLocalizer;

	/** @var UserFactory */
	private $userFactory;

	/**
	 * @param array $params
	 * @param ConfigFactory $configFactory
	 * @param ImportDumpRequestManager $importDumpRequestManager
	 * @param UserFactory $userFactory
	 */
	public function __construct(
		array $params,
		ConfigFactory $configFactory,
		ImportDumpRequestManager $importDumpRequestManager,
		UserFactory $userFactory
	) {
		parent::__construct( self::JOB_NAME, $params );

		$this->requestID = $params['requestid'];
		$this->status = $params['status'];
		$this->lastError = $params['lasterror'] ?? '';
		$this->username = $params['username'];

		$this->importDumpRequestManager = $importDumpRequestManager;
		$this->userFactory = $userFactory;

		$this->config = $configFactory->makeConfig( 'ImportDump' );
		$this->messageLocalizer = RequestContext::getMain();
	}

	/**
	 * @return bool
	 */
	public function run(): bool {
		if ( !ExtensionRegistry::getInstance()->isLoaded( 'Echo' ) ) {
			return true;
		}

		$this->importDumpRequestManager->fromID( $this->requestID );

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
		$commentUser = User::newSystemUser( 'ImportDump Status Update' );

		$statusMessage = $this->messageLocalizer->msg( 'importdump-label-' . self::STATUS_COMPLETE )
			->inContentLanguage()
			->text();

		$comment = $this->messageLocalizer->msg( 'importdump-status-updated', strtolower( $statusMessage ) )
			->inContentLanguage()
			->escaped();

		$this->importDumpRequestManager->addComment( $comment, $commentUser );
		$this->importDumpRequestManager->sendNotification( $comment, 'importdump-request-status-update', $commentUser );
		$this->importDumpRequestManager->setStatus( self::STATUS_COMPLETE );
	}

	private function notifyFailed() {
		$notifiedUsers = array_filter(
			array_map(
				function ( string $userName ): ?User {
					return $this->userFactory->newFromName( $userName );
				}, $this->config->get( 'ImportDumpUsersNotifiedOnFailedImports' )
			)
		);

		$requestLink = SpecialPage::getTitleFor( 'RequestImportQueue', (string)$this->requestID )->getFullURL();

		foreach ( $notifiedUsers as $receiver ) {
			if (
				!$receiver->isAllowed( 'handle-import-requests' ) ||
				(
					$this->importDumpRequestManager->isPrivate() &&
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
					'reason' => $this->lastError,
					'notifyAgent' => true,
				],
				'agent' => $receiver,
			] );
		}

		$commentUser = User::newSystemUser( 'ImportDump Status Update' );
		$comment = $this->messageLocalizer->msg( 'importdump-import-failed-comment' )
			->inContentLanguage()
			->escaped();

		$this->importDumpRequestManager->addComment( $comment, $commentUser );
		$this->importDumpRequestManager->sendNotification( $comment, 'importdump-request-status-update', $commentUser );
		$this->importDumpRequestManager->setStatus( self::STATUS_FAILED );
	}

	private function notifyStarted() {
		$user = $this->userFactory->newFromName( $this->username );
		if ( !( $user instanceof User ) ) {
			$this->setLastError( '$user is not an instance of User' );
			return;
		}

		$this->importDumpRequestManager->logStarted( $user );

		$statusMessage = $this->messageLocalizer->msg( 'importdump-label-' . self::STATUS_INPROGRESS )
			->inContentLanguage()
			->text();

		$comment = $this->messageLocalizer->msg( 'importdump-status-updated', strtolower( $statusMessage ) )
			->inContentLanguage()
			->escaped();

		$this->importDumpRequestManager->addComment( $comment, $user );
		$this->importDumpRequestManager->sendNotification( $comment, 'importdump-request-status-update', $user );
		$this->importDumpRequestManager->setStatus( self::STATUS_INPROGRESS );
	}
}
