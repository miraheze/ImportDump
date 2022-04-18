<?php

namespace Miraheze\ImportDump;

use CentralAuthUser;
use ErrorPageError;
use FormSpecialPage;
use MWException;
use PermissionsError;
use SpecialPage;
use UploadBase;
use UserBlockedError;
use UserNotLoggedIn;

class SpecialRequestImportDump extends FormSpecialPage {
	public function __construct() {
		parent::__construct( 'RequestImportDump', 'requestimport' );
	}

	/**
	 * @param string $par
	 * @return bool
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
	 * @return bool
	 */
	public function onSubmit( array $data ) {
		$globalUser = CentralAuthUser::getInstance( $this->getUser() );
		$dbw = wfGetDB( DB_PRIMARY, [], $this->getConfig()->get( 'ImportDumpRequestsDatabase' ) );

		$inReview = $dbw->select(
			'importdump_requests',
			[
				'request_reason',
				'request_source',
				'request_target',
			],
			[
				'request_status' => 'inreview',
			],
			__METHOD__
		);

		foreach ( $inReview as $row ) {
			if (
				$data['source'] == $row->request_source ||
				$data['target'] == $row->request_target ||
				$data['reason'] == $row->request_reason
			) {
				throw new MWException( 'Request is too similar to an existing open request!' );
			}
		}

		$rows = [
			'request_source' => $data['source'],
			'request_target' => $data['target'],
			'request_file' => $data['file'],
			'request_reason' => $data['reason'],
			'request_status' => 'inreview',
			'request_user' => $globalUser->getId(),
			'request_timestamp' => $dbw->timestamp(),
		];

		$dbw->insert(
			'importdump_requests',
			$rows,
			__METHOD__,
			[ 'IGNORE' ]
		);

		return true;
	}

	public function onSuccess() {
		$this->getOutput()->addWikiMsg( 'importdump-success' );
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
