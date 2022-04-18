<?php

namespace Miraheze\ImportDump;

use FormSpecialPage;
use Html;
use SpecialPage;

class SpecialRequestImportDump extends FormSpecialPage {
	public function __construct() {
		parent::__construct( 'RequestImportDump', 'requestimport' );
	}

	public function execute( $par ) {
		$out = $this->getOutput();

		$this->setParameter( $par );
		$this->setHeaders();

		if ( !$this->getUser()->isRegistered() ) {
			$loginurl = SpecialPage::getTitleFor( 'Userlogin' )->getFullURL( [ 'returnto' => $this->getPageTitle()->getPrefixedText() ] );

			$out->addWikiMsg( 'importdump-notloggedin', $loginurl );

			return false;
		}

		$this->checkExecutePermissions( $this->getUser() );

		$form = $this->getForm();
		if ( $form->show() ) {
			$this->onSuccess();
		}
	}

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

	public function onSubmit( array $formData ) {
		$out->addHTML( Html::successBox( $this->msg( 'importdump-success' )->text() ) );

		return true;
	}

	public function isValidReason( $reason, $allData ) {
		if ( !$reason || ctype_space( $reason ) ) {
			return $this->msg( 'htmlform-required', 'parseinline' )->escaped();
		}

		return true;
	}

	protected function getDisplayFormat() {
		return 'ooui';
	}

	protected function getGroupName() {
		return 'wikimanage';
	}
}
