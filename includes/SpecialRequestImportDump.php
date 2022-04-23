<?php

namespace Miraheze\ImportDump;

use ErrorPageError;
use File;
use FormSpecialPage;
use Html;
use MimeAnalyzer;
use PermissionsError;
use SpecialPage;
use Status;
use UserBlockedError;
use UserNotLoggedIn;
use WikiMap;
use Wikimedia\Rdbms\ILBFactory;

class SpecialRequestImportDump extends FormSpecialPage {

	/** @var ILBFactory */
	private $dbLoadBalancerFactory;

	/** @var MimeAnalyzer */
	private $mimeAnalyzer;

	/**
	 * @param ILBFactory $dbLoadBalancerFactory
	 * @param MimeAnalyzer $mimeAnalyzer
	 */
	public function __construct(
		ILBFactory $dbLoadBalancerFactory,
		MimeAnalyzer $mimeAnalyzer
	) {
		parent::__construct( 'RequestImportDump', 'requestimport' );

		$this->dbLoadBalancerFactory = $dbLoadBalancerFactory;
		$this->mimeAnalyzer = $mimeAnalyzer;
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
				'required' => true,
			],
			'target' => [
				'type' => 'text',
				'label-message' => 'importdump-label-target',
				'required' => true,
				'validation-callback' => [ $this, 'isValidDatabase' ],
			],
			'UploadSourceType' => [
				'type' => 'radio',
				'label-message' => 'importdump-label-upload-source-type',
				'default' => 'File',
				'options-messages' => [
					'importdump-label-file' => 'File',
					'importdump-label-url' => 'Url',
				],
			],
			'UploadFile' => [
				'type' => 'file',
				'label-message' => 'importdump-label-upload-file',
				'hide-if' => [ '!==', 'wpUploadSourceType', 'File' ],
				'required' => true,
			],
			'UploadFileURL' => [
				'type' => 'url',
				'label-message' => 'importdump-label-upload-file-url',
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
		if ( $this->getUser()->pingLimiter( 'requestimportdump' ) ) {
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

		$fileName = $this->getUser()->getName() . '-' . rand( 0, 10000 ) . '.jpg';

		$request = $this->getRequest();
		$request->setVal( 'wpDestFile', $fileName );

		$uploadBase = UploadBase::createFromRequest( $request, $data['UploadSourceType'] );
		$uploadBase->getLocalFile()->load( File::READ_LATEST );

		$dbname = $this->getConfig()->get( 'DBname' );
		$uploadPath = '/mnt/mediawiki-static/' . $dbname . '/ImportDump';

		$uploadBase->setTempFile( $uploadPath, $uploadBase->getFileSize() );
		$status = $uploadBase->performUpload( '', '', false, $this->getUser() );

		if ( !$status->isGood() ) {
			return $status;
		}

		$rows = [
			'request_source' => $data['source'],
			'request_target' => $data['target'],
			'request_file' => $uploadBase->getLocalFile()->getTitle()->getFullURL(),
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
		$requestLink = $this->getLinkRenderer()->makeLink(
			SpecialPage::getTitleValueFor( 'ImportDumpRequestQueue', $requestID ),
			"#{$requestID}"
		);

		$this->getOutput()->addHTML(
			Html::successBox( $this->msg( 'importdump-success', $requestLink )->plain() )
		);

		return Status::newGood();
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
