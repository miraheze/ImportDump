<?php

namespace Miraheze\ImportDump;

use ErrorPageError;
use FormSpecialPage;
use Html;
use PermissionsError;
use SpecialPage;
use Status;
use UploadBase;
use UserBlockedError;
use UserNotLoggedIn;
use WikiMap;
use Wikimedia\Rdbms\ILBFactory;

class SpecialRequestImportDump extends FormSpecialPage {

	/** @var ILBFactory */
	private $dbLoadBalancerFactory;

	/**
	 * @param ILBFactory $dbLoadBalancerFactory
	 */
	public function __construct( ILBFactory $dbLoadBalancerFactory ) {
		parent::__construct( 'RequestImportDump', 'requestimport' );

		$this->dbLoadBalancerFactory = $dbLoadBalancerFactory;
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
			'FileSourceType' => [
				'type' => 'radio',
				'label-message' => 'importdump-label-file-source-type',
				'default' => 'File',
				'options-messages' => [
					'importdump-label-file' => 'File',
					'importdump-label-url' => 'Url',
				],
			],
			'FileUpload' => [
				'type' => 'file',
				'label-message' => 'importdump-label-file-upload',
				'hide-if' => [ '!==', 'wpFileSourceType', 'File' ],
				'required' => true,
			],
			'FileUrl' => [
				'type' => 'url',
				'label-message' => 'importdump-label-file-upload-by-url',
				'hide-if' => [ '!==', 'wpFileSourceType', 'Url' ],
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

		$uploadBase = UploadBase::createFromRequest( $this->getRequest(), $data['FileSourceType'] );

		$rows = [
			'request_source' => $data['source'],
			'request_target' => $data['target'],
			'request_file' => $data['file'],
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
