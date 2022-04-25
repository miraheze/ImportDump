<?php

namespace Miraheze\ImportDump;

use ErrorPageError;
use ExtensionRegistry;
use FileRepo;
use FormSpecialPage;
use Html;
use ManualLogEntry;
use Message;
use MimeAnalyzer;
use Miraheze\CreateWiki\RemoteWiki;
use PermissionsError;
use RepoGroup;
use SpecialPage;
use Status;
use UploadBase;
use UploadStash;
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

	/**
	 * @param ILBFactory $dbLoadBalancerFactory
	 * @param MimeAnalyzer $mimeAnalyzer
	 * @param RepoGroup $repoGroup
	 */
	public function __construct(
		ILBFactory $dbLoadBalancerFactory,
		MimeAnalyzer $mimeAnalyzer,
		RepoGroup $repoGroup
	) {
		parent::__construct( 'RequestImportDump', 'requestimport' );

		$this->dbLoadBalancerFactory = $dbLoadBalancerFactory;
		$this->mimeAnalyzer = $mimeAnalyzer;
		$this->repoGroup = $repoGroup;
	}

	/**
	 * @param string $par
	 */
	public function execute( $par ) {
		$this->setParameter( $par );
		$this->setHeaders();

		if ( !$this->getUser()->isRegistered() ) {
			$loginurl = SpecialPage::getTitleFor( 'Userlogin' )
				->getFullURL( [
					'returnto' => $this->getPageTitle()->getPrefixedText()
				]
			);

			throw new UserNotLoggedIn( 'importdump-notloggedin', 'exception-nologin', [ $loginurl ] );
		}

		$this->checkPermissions();

		if (
			$this->getConfig()->get( 'ImportDumpCentralWiki' ) &&
			!WikiMap::isCurrentWikiId( $this->getConfig()->get( 'ImportDumpCentralWiki' ) )
		) {
			throw new ErrorPageError( 'importdump-notcentral', 'importdump-notcentral-text' );
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
				'help-message' => 'importdump-help-upload',
				'hide-if' => [ '!==', 'wpUploadSourceType', 'File' ],
				'required' => true,
			],
			'UploadFileURL' => [
				'type' => 'url',
				'label-message' => 'importdump-label-upload-file-url',
				'help-message' => 'importdump-help-upload',
				'hide-if' => [ '!==', 'wpUploadSourceType', 'Url' ],
				'required' => true,
			],
			'reason' => [
				'type' => 'textarea',
				'rows' => 4,
				'label-message' => 'importdump-label-reason',
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
			$this->getUser()->pingLimiter( 'requestimportdump' ) ||
			UploadBase::isThrottled( $this->getUser() )
		) {
			return Status::newFatal( 'actionthrottledtext' );
		}

		$centralWiki = $this->getConfig()->get( 'ImportDumpCentralWiki' );
		if ( $centralWiki ) {
			$dbw = $this->dbLoadBalancerFactory->getMainLB(
				$centralWiki
			)->getConnectionRef( DB_PRIMARY, [], $centralWiki );
		} else {
			$dbw = $this->dbLoadBalancerFactory->getMainLB()->getConnectionRef( DB_PRIMARY );
		}

		$duplicate = $dbw->selectRow(
			'importdump_requests',
			'*',
			[
				'request_reason' => $data['reason'],
				'request_status' => 'pending',
			],
			__METHOD__
		);

		if ( (bool)$duplicate ) {
			return Status::newFatal( 'importdump-duplicate-request' );
		}

		$fileName = $data['target'] . '-' . $dbw->timestamp() . '.xml';

		$request = $this->getRequest();
		$request->setVal( 'wpDestFile', $fileName );

		$uploadBase = UploadBase::createFromRequest( $request, $data['UploadSourceType'] );

		$status = $uploadBase->fetchFile();
		if ( !$status->isOK() ) {
			return $status;
		}

		$mime = $this->mimeAnalyzer->guessMimeType( $uploadBase->getTempPath() );
		if ( $mime !== 'application/xml' ) {
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

		$filePath = $this->getConfig()->get( 'UploadDirectory' ) . '/ImportDump/' . $fileName;

		$rows = [
			'request_source' => $data['source'],
			'request_target' => $data['target'],
			'request_file' => $filePath,
			'request_reason' => $data['reason'],
			'request_status' => 'pending',
			'request_actor' => $this->getUser()->getActorId(),
			'request_timestamp' => $dbw->timestamp(),
		];

		$dbw->insert(
			'importdump_requests',
			$rows,
			__METHOD__,
			[ 'IGNORE' ]
		);

		$requestID = (string)$dbw->insertId();
		$requestQueueLink = SpecialPage::getTitleValueFor( 'ImportDumpRequestQueue', $requestID );

		$requestLink = $this->getLinkRenderer()->makeLink(
			$requestQueueLink,
			"#{$requestID}"
		);

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

		return Status::newGood();
	}

	/**
	 * @param string $target
	 * @return string
	 */
	public function getLogType( string $target ): string {
		if ( !ExtensionRegistry::getInstance()->isLoaded( 'CreateWiki' ) ) {
			return 'importdump';
		}

		// @phan-suppress-next-line PhanUndeclaredClassMethod
		$remoteWiki = new RemoteWiki( $target );

		// @phan-suppress-next-line PhanUndeclaredClassMethod
		return $remoteWiki->isPrivate() ? 'importdumpprivate' : 'importdump';
	}

	/**
	 * @param ?string $target
	 * @return string|bool
	 */
	public function isValidDatabase( ?string $target ) {
		if ( !in_array( $target, $this->getConfig()->get( 'LocalDatabases' ) ) ) {
			return $this->msg( 'importdump-invalid-target' )->escaped();
		}

		return true;
	}

	/**
	 * @param ?string $reason
	 * @return string|bool
	 */
	public function isValidReason( ?string $reason ) {
		if ( !$reason || ctype_space( $reason ) ) {
			return $this->msg( 'htmlform-required', 'parseinline' )->escaped();
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

		if ( $user->isBlockedFromUpload() ) {
			// @phan-suppress-next-line PhanTypeMismatchArgumentNullable
			throw new UserBlockedError( $user->getBlock() );
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
		return 'wikimanage';
	}
}
