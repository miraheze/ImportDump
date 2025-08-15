<?php

namespace Miraheze\ImportDump\Specials;

use ErrorPageError;
use FileRepo;
use ManualLogEntry;
use MediaWiki\Extension\Notifications\Model\Event;
use MediaWiki\Html\Html;
use MediaWiki\MainConfigNames;
use MediaWiki\Message\Message;
use MediaWiki\Permissions\PermissionManager;
use MediaWiki\Registration\ExtensionRegistry;
use MediaWiki\SpecialPage\FormSpecialPage;
use MediaWiki\SpecialPage\SpecialPage;
use MediaWiki\Status\Status;
use MediaWiki\User\User;
use MediaWiki\User\UserFactory;
use MediaWiki\WikiMap\WikiMap;
use Miraheze\ImportDump\ConfigNames;
use Miraheze\ImportDump\ImportDumpStatus;
use Miraheze\ManageWiki\Helpers\Factories\ModuleFactory;
use PermissionsError;
use RepoGroup;
use UploadBase;
use UploadFromUrl;
use UploadStash;
use UserBlockedError;
use Wikimedia\Mime\MimeAnalyzer;
use Wikimedia\Rdbms\IConnectionProvider;
use Wikimedia\Rdbms\Platform\ISQLPlatform;

class SpecialRequestImport extends FormSpecialPage
	implements ImportDumpStatus {

	public function __construct(
		private readonly IConnectionProvider $connectionProvider,
		private readonly ExtensionRegistry $extensionRegistry,
		private readonly MimeAnalyzer $mimeAnalyzer,
		private readonly PermissionManager $permissionManager,
		private readonly RepoGroup $repoGroup,
		private readonly UserFactory $userFactory,
		private readonly ?ModuleFactory $moduleFactory
	) {
		parent::__construct( 'RequestImport', 'request-import' );
	}

	/**
	 * @param ?string $par
	 */
	public function execute( $par ): void {
		$this->requireLogin( 'importdump-notloggedin' );
		$this->setParameter( $par );
		$this->setHeaders();

		$dbr = $this->connectionProvider->getReplicaDatabase( 'virtual-importdump' );
		if ( !WikiMap::isCurrentWikiDbDomain( $dbr->getDomainID() ) ) {
			throw new ErrorPageError( 'importdump-notcentral', 'importdump-notcentral-text' );
		}

		$this->checkPermissions();

		if ( $this->getConfig()->get( ConfigNames::HelpUrl ) ) {
			$this->getOutput()->addHelpLink( $this->getConfig()->get( ConfigNames::HelpUrl ), true );
		}

		$form = $this->getForm();
		if ( $form->show() ) {
			$this->onSuccess();
		}
	}

	/** @inheritDoc */
	protected function getFormFields(): array {
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
					'accept' => [ 'application/xml', 'text/xml' ],
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
					'accept' => [ 'application/xml', 'text/xml' ],
				],
			];
		}

		$formDescriptor += [
			'reason' => [
				'type' => 'textarea',
				'rows' => 6,
				'label-message' => 'importdump-label-reason',
				'help-message' => 'importdump-help-reason',
				'required' => true,
				'validation-callback' => [ $this, 'isValidReason' ],
			],
		];

		return $formDescriptor;
	}

	/** @inheritDoc */
	public function onSubmit( array $data ): Status {
		$token = $this->getRequest()->getVal( 'wpEditToken' );
		$userToken = $this->getContext()->getCsrfTokenSet();
		if ( !$userToken->matchToken( $token ) ) {
			return Status::newFatal( 'sessionfailure' );
		}

		if (
			$this->getUser()->pingLimiter( 'request-import' ) ||
			$this->getUser()->pingLimiter( 'upload' )
		) {
			return Status::newFatal( 'actionthrottledtext' );
		}

		$dbw = $this->connectionProvider->getPrimaryDatabase( 'virtual-importdump' );
		$duplicate = $dbw->newSelectQueryBuilder()
			->select( ISQLPlatform::ALL_ROWS )
			->from( 'import_requests' )
			->where( [
				'request_reason' => $data['reason'],
				'request_status' => self::STATUS_PENDING,
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
			return Status::wrap(
				$this->permissionManager->newFatalPermissionDeniedStatus(
					$permission, $this->getContext()
				)
			);
		}

		if ( $uploadBase->isEmptyFile() ) {
			return Status::newFatal( 'empty-file' );
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

		$dbw->newInsertQueryBuilder()
			->insertInto( 'import_requests' )
			->ignore()
			->row( [
				'request_source' => $data['source'],
				'request_target' => $data['target'],
				'request_reason' => $data['reason'],
				'request_status' => self::STATUS_PENDING,
				'request_actor' => $this->getUser()->getActorId(),
				'request_timestamp' => $timestamp,
			] )
			->caller( __METHOD__ )
			->execute();

		$requestID = (string)$dbw->insertId();
		$requestQueueLink = SpecialPage::getTitleValueFor( 'RequestImportQueue', $requestID );

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

		if (
			$this->extensionRegistry->isLoaded( 'Echo' ) &&
			$this->getConfig()->get( ConfigNames::UsersNotifiedOnAllRequests )
		) {
			$this->sendNotifications( $data['reason'], $this->getUser()->getName(), $requestID, $data['target'] );
		}

		return Status::newGood();
	}

	public function getLogType( string $target ): string {
		if (
			!$this->extensionRegistry->isLoaded( 'ManageWiki' ) ||
			!$this->moduleFactory ||
			!$this->moduleFactory->isEnabled( 'core' )
		) {
			return 'importdump';
		}

		$mwCore = $this->moduleFactory->core( $target );
		if ( !$mwCore->isEnabled( 'private-wikis' ) ) {
			return 'importdump';
		}

		return $mwCore->isPrivate() ? 'importdumpprivate' : 'importdump';
	}

	public function sendNotifications(
		string $reason,
		string $requester,
		string $requestID,
		string $target
	): void {
		$notifiedUsers = array_filter(
			array_map(
				function ( string $userName ): ?User {
					return $this->userFactory->newFromName( $userName );
				}, $this->getConfig()->get( ConfigNames::UsersNotifiedOnAllRequests )
			)
		);

		$requestLink = SpecialPage::getTitleFor( 'RequestImportQueue', $requestID )->getFullURL();
		foreach ( $notifiedUsers as $receiver ) {
			if (
				!$receiver->isAllowed( 'handle-import-requests' ) ||
				(
					$this->getLogType( $target ) === 'importdumpprivate' &&
					!$receiver->isAllowed( 'view-private-import-requests' )
				)
			) {
				continue;
			}

			Event::create( [
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

	public function isValidDatabase( ?string $target ): Message|true {
		if ( !in_array( $target, $this->getConfig()->get( MainConfigNames::LocalDatabases ), true ) ) {
			return $this->msg( 'importdump-invalid-target' );
		}

		return true;
	}

	public function isValidReason( ?string $reason ): Message|true {
		if ( !$reason || ctype_space( $reason ) ) {
			return $this->msg( 'htmlform-required' );
		}

		return true;
	}

	public function checkPermissions(): void {
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
				$block->appliesToRight( 'request-import' )
			)
		) {
			throw new UserBlockedError( $block );
		}

		$this->checkReadOnly();
		if ( !UploadBase::isEnabled() ) {
			throw new ErrorPageError( 'uploaddisabled', 'uploaddisabledtext' );
		}
	}

	/** @inheritDoc */
	protected function getDisplayFormat(): string {
		return 'ooui';
	}

	/** @inheritDoc */
	protected function getGroupName(): string {
		return 'other';
	}
}
