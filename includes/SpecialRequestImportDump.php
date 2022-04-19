<?php

namespace Miraheze\ImportDump;

use ErrorPageError;
use FormSpecialPage;
use Html;
use MWException;
use PermissionsError;
use SpecialPage;
use Status;
use UploadBase;
use UserBlockedError;
use UserNotLoggedIn;
use WikiMap;

class SpecialRequestImportDump extends FormSpecialPage {
	public function __construct() {
		parent::__construct( 'RequestImportDump', 'requestimport' );
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
				'type' => 'text',
				'label-message' => 'importdump-label-source',
			],
			'target' => [
				'type' => 'text',
				'label-message' => 'importdump-label-target',
				'required' => true,
			],
			'file' => [
				'type' => 'file',
				'label-message' => 'importdump-label-file',
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

		$dbw = wfGetDB( DB_PRIMARY );

		$pending = $dbw->select(
			'importdump_requests',
			[
				'request_reason',
				'request_source',
				'request_target',
			],
			[
				'request_status' => 'pending',
			],
			__METHOD__
		);

		foreach ( $pending as $row ) {
			if (
				$data['source'] == $row->request_source ||
				$data['target'] == $row->request_target ||
				$data['reason'] == $row->request_reason
			) {
				return Status::newFatal( 'importdump-duplicate-request' );
			}
		}

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
	 * @param string $reason
	 * @return string|bool
	 */
	public function isValidReason( $reason ) {
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
