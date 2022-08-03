<?php

namespace Miraheze\ImportDump\Specials;

use EchoEvent;
use ErrorPageError;
use ExtensionRegistry;
use FileRepo;
use FormSpecialPage;
use Html;
use ManualLogEntry;
use MediaWiki\User\UserFactory;
use Message;
use MimeAnalyzer;
use Miraheze\CreateWiki\RemoteWiki;
use PermissionsError;
use RepoGroup;
use SpecialPage;
use Status;
use UploadBase;
use UploadFromUrl;
use UploadStash;
use User;
use UserBlockedError;
use UserNotLoggedIn;
use WikiMap;
use Wikimedia\Rdbms\ILBFactory;

class SpecialRequestImportDump extends FormSpecialPage {

	/** @var ILBFactory */
	private $dbLoadBalancerFactory;

	/** @var MimeAnalyzer */
	private $mimeAnalyzer;

	/** @var RepoGroup */
	private $repoGroup;

	/** @var UserFactory */
	private $userFactory;

	/**
	 * @param ILBFactory $dbLoadBalancerFactory
	 * @param MimeAnalyzer $mimeAnalyzer
	 * @param RepoGroup $repoGroup
	 * @param UserFactory $userFactory
	 */
	public function __construct(
		ILBFactory $dbLoadBalancerFactory,
		MimeAnalyzer $mimeAnalyzer,
		RepoGroup $repoGroup,
		UserFactory $userFactory
	) {
		parent::__construct( 'RequestImportDump', 'request-import-dump' );

		$this->dbLoadBalancerFactory = $dbLoadBalancerFactory;
		$this->mimeAnalyzer = $mimeAnalyzer;
		$this->repoGroup = $repoGroup;
		$this->userFactory = $userFactory;
	}

	/**
	 * @param string $par
	 */
	public function execute( $par ) {
		$this->setParameter( $par );
		$this->setHeaders();

		if (
			$this->getConfig()->get( 'ImportDumpCentralWiki' ) &&
			!WikiMap::isCurrentWikiId( $this->getConfig()->get( 'ImportDumpCentralWiki' ) )
		) {
			throw new ErrorPageError( 'importdump-notcentral', 'importdump-notcentral-text' );
		}

		if ( !$this->getUser()->isRegistered() ) {
			$loginURL = SpecialPage::getTitleFor( 'Userlogin' )
				->getFullURL( [
					'returnto' => $this->getPageTitle()->getPrefixedText(),
				]
			);

			throw new UserNotLoggedIn( 'importdump-notloggedin', 'exception-nologin', [ $loginURL ] );
		}

		$this->checkPermissions();

		if ( $this->getConfig()->get( 'ImportDumpHelpUrl' ) ) {
			$this->getOutput()->addHelpLink( $this->getConfig()->get( 'ImportDumpHelpUrl' ), true );
		}

		$form = $this->getForm();
		if ( $form->show() ) {
			$this->onSuccess();
		}
	}

	/**
	 * @return array
	 */
	protected function getFormFields() {
		$formDescriptor = [
			'source' => [
				'type' => 'url',
				'label-message' => 'importdump-label-source',
				'help-message' => 'importdump-help-source',
				'required' => true,
			],
			'target' => [
				'type' => 'text',
				'label-message' => 'importdump-label-target',
				'help-message' => 'importdump-help-target',
				'required' => true,
				'validation-callback' => [ $this, 'isValidDatabase' ],
			],
		];

		if (
			UploadFromUrl::isEnabled() &&
			UploadFromUrl::isAllowed( $this->getUser() ) === true
		) {
			$formDescriptor += [
				'UploadSourceType' => [
					'type' => 'radio',
					'label-message' => 'importdump-label-upload-source-type',
					'default' => 'File',
					'options-messages' => [
						'importdump-label-upload-source-file' => 'File',
						'importdump-label-upload-source-url' => 'Url',
					],
				],
				'UploadFile' => [
					'type' => 'file',
					'label-message' => 'importdump-label-upload-file',
					'help-message' => 'importdump-help-upload-file',
					'hide-if' => [ '!==', 'wpUploadSourceType', 'File' ],
					'accept' => [ '.xml' ],
					'required' => true,
				],
				'UploadFileURL' => [
					'type' => 'url',
					'label-message' => 'importdump-label-upload-file-url',
					'help-message' => 'importdump-help-upload-file-url',
					'hide-if' => [ '!==', 'wpUploadSourceType', 'Url' ],
					'required' => true,
				],
			];
		} else {
			$formDescriptor += [
				'UploadFile' => [
					'type' => 'file',
					'label-message' => 'importdump-label-upload-file',
					'help-message' => 'importdump-help-upload-file',
					'accept' => [ '.xml' ],
					'required' => true,
				],
			];
		}

		$formDescriptor += [
			'reason' => [
				'type' => 'textarea',
				'rows' => 4,
				'label-message' => 'importdump-label-reason',
				'help-message' => 'importdump-help-reason',
				'required' => true,
				'validation-callback' => [ $this, 'isValidReason' ],
			],
		];

		return $formDescriptor;
	}

	/**
	 * @param array $data
	 * @return Status
	 */
	public function onSubmit( array $data ) {
		$token = $this->getRequest()->getVal( 'wpEditToken' );
		$userToken = $this->getContext()->getCsrfTokenSet();

		if ( !$userToken->matchToken( $token ) ) {
			return Status::newFatal( 'sessionfailure' );
		}

		if (
			$this->getUser()->pingLimiter( 'request-import-dump' ) ||
			UploadBase::isThrottled( $this->getUser() )
		) {
			return Status::newFatal( 'actionthrottledtext' );
		}

		$centralWiki = $this->getConfig()->get( 'ImportDumpCentralWiki' );
		if ( $centralWiki ) {
			$dbw = $this->dbLoadBalancerFactory->getMainLB(
				$centralWiki
			)->getConnection( DB_PRIMARY, [], $centralWiki );
		} else {
			$dbw = $this->dbLoadBalancerFactory->getMainLB()->getConnection( DB_PRIMARY );
		}

		$duplicate = $dbw->newSelectQueryBuilder()
			->table( 'importdump_requests' )
			->field( '*' )
			->where( [
				'request_reason' => $data['reason'],
				'request_status' => 'pending',
			] )
			->caller( __METHOD__ )
			->fetchRow();

		if ( (bool)$duplicate ) {
			return Status::newFatal( 'importdump-duplicate-request' );
		}

		$timestamp = $dbw->timestamp();
		$fileName = $data['target'] . '-' . $timestamp . '.xml';

		$request = $this->getRequest();
		$request->setVal( 'wpDestFile', $fileName );

		$uploadBase = UploadBase::createFromRequest( $request, $data['UploadSourceType'] ?? 'File' );

		if ( !$uploadBase->isEnabled() ) {
			return Status::newFatal( 'uploaddisabled' );
		}

		$permission = $uploadBase->isAllowed( $this->getUser() );
		if ( $permission !== true ) {
			return User::newFatalPermissionDeniedStatus( $permission );
		}

		$status = $uploadBase->fetchFile();
		if ( !$status->isOK() ) {
			return $status;
		}

		$virus = UploadBase::detectVirus( $uploadBase->getTempPath() );
		if ( $virus ) {
			return Status::newFatal( 'uploadvirus', $virus );
		}

		$mime = $this->mimeAnalyzer->guessMimeType( $uploadBase->getTempPath() );
		if ( $mime !== 'application/xml' && $mime !== 'text/xml' ) {
			return Status::newFatal( 'filetype-mime-mismatch', 'xml', $mime );
		}

		$mimeExt = $this->mimeAnalyzer->getExtensionFromMimeTypeOrNull( $mime );
		if ( $mimeExt !== 'xml' ) {
			return Status::newFatal(
				'filetype-banned-type', $mimeExt ?? 'unknown', 'xml', 1, 1
			);
		}

		$status = $uploadBase->tryStashFile( $this->getUser() );
		if ( !$status->isGood() ) {
			return $status;
		}

		$repo = $this->repoGroup->getLocalRepo();
		$uploadStash = new UploadStash( $repo, $this->getUser() );

		$fileKey = $status->getStatusValue()->getValue()->getFileKey();
		$file = $uploadStash->getFile( $fileKey );

		$status = $repo->publish(
			$file->getPath(),
			'/ImportDump/' . $fileName,
			'/ImportDump/archive/' . $fileName,
			FileRepo::DELETE_SOURCE
		);

		if ( !$status->isOK() ) {
			return $status;
		}

		$dbw->insert(
			'importdump_requests',
			[
				'request_source' => $data['source'],
				'request_target' => $data['target'],
				'request_reason' => $data['reason'],
				'request_status' => 'pending',
				'request_actor' => $this->getUser()->getActorId(),
				'request_timestamp' => $timestamp,
			],
			__METHOD__,
			[ 'IGNORE' ]
		);

		$requestID = (string)$dbw->insertId();
		$requestQueueLink = SpecialPage::getTitleValueFor( 'RequestImportDumpQueue', $requestID );

		$requestLink = $this->getLinkRenderer()->makeLink( $requestQueueLink, "#{$requestID}" );

		$this->getOutput()->addHTML(
			Html::successBox(
				$this->msg( 'importdump-success' )->rawParams( $requestLink )->escaped()
			)
		);

		$logEntry = new ManualLogEntry( $this->getLogType( $data['target'] ), 'request' );

		$logEntry->setPerformer( $this->getUser() );
		$logEntry->setTarget( $requestQueueLink );
		$logEntry->setComment( $data['reason'] );

		$logEntry->setParameters(
			[
				'4::requestTarget' => $data['target'],
				'5::requestLink' => Message::rawParam( $requestLink ),
			]
		);

		$logID = $logEntry->insert( $dbw );
		$logEntry->publish( $logID );

		if ( $this->getConfig()->get( 'ImportDumpUsersNotifiedOnAllRequests' ) ) {
			$this->sendNotifications( $data['reason'], $this->getUser()->getName(), $requestID, $data['target'] );
		}

		return Status::newGood();
	}

	/**
	 * @param string $target
	 * @return string
	 */
	public function getLogType( string $target ): string {
		if (
			!ExtensionRegistry::getInstance()->isLoaded( 'CreateWiki' ) ||
			!$this->getConfig()->get( 'CreateWikiUsePrivateWikis' )
		) {
			return 'importdump';
		}

		$remoteWiki = new RemoteWiki( $target );
		return $remoteWiki->isPrivate() ? 'importdumpprivate' : 'importdump';
	}

	/**
	 * @param string $reason
	 * @param string $requester
	 * @param string $requestID
	 * @param string $target
	 */
	public function sendNotifications( string $reason, string $requester, string $requestID, string $target ) {
		$notifiedUsers = array_filter(
			array_map(
				function ( string $userName ): ?User {
					return $this->userFactory->newFromName( $userName );
				}, $this->getConfig()->get( 'ImportDumpUsersNotifiedOnAllRequests' )
			)
		);

		$requestLink = SpecialPage::getTitleFor( 'RequestImportDumpQueue', $requestID )->getFullURL();

		foreach ( $notifiedUsers as $receiver ) {
			if (
				!$receiver->isAllowed( 'handle-import-dump-requests' ) ||
				(
					$this->getLogType( $target ) === 'importdumpprivate' &&
					!$receiver->isAllowed( 'view-private-import-dump-requests' )
				)
			) {
				continue;
			}

			EchoEvent::create( [
				'type' => 'importdump-new-request',
				'extra' => [
					'request-id' => $requestID,
					'request-url' => $requestLink,
					'reason' => $reason,
					'requester' => $requester,
					'target' => $target,
					'notifyAgent' => true,
				],
				'agent' => $receiver,
			] );
		}
	}

	/**
	 * @param ?string $target
	 * @return string|bool
	 */
	public function isValidDatabase( ?string $target ) {
		if ( !in_array( $target, $this->getConfig()->get( 'LocalDatabases' ) ) ) {
			return Status::newFatal( 'importdump-invalid-target' )->getMessage();
		}

		return true;
	}

	/**
	 * @param ?string $reason
	 * @return string|bool
	 */
	public function isValidReason( ?string $reason ) {
		if ( !$reason || ctype_space( $reason ) ) {
			return Status::newFatal( 'htmlform-required' )->getMessage();
		}

		return true;
	}

	public function checkPermissions() {
		parent::checkPermissions();

		$user = $this->getUser();
		$permissionRequired = UploadBase::isAllowed( $user );
		if ( $permissionRequired !== true ) {
			throw new PermissionsError( $permissionRequired );
		}

		$block = $user->getBlock();
		if (
			$block && (
				$user->isBlockedFromUpload() ||
				$block->appliesToRight( 'request-import-dump' )
			)
		) {
			throw new UserBlockedError( $block );
		}

		$globalBlock = $user->getGlobalBlock();
		if ( $globalBlock ) {
			throw new UserBlockedError( $globalBlock );
		}

		$this->checkReadOnly();
		if ( !UploadBase::isEnabled() ) {
			throw new ErrorPageError( 'uploaddisabled', 'uploaddisabledtext' );
		}
	}

	/**
	 * @return string
	 */
	protected function getDisplayFormat() {
		return 'ooui';
	}

	/**
	 * @return string
	 */
	protected function getGroupName() {
		return 'other';
	}
}
