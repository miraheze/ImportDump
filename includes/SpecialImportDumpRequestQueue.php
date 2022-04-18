<?php

namespace Miraheze\ImportDump;

use HTMLForm;
use SpecialPage;

class SpecialImportDumpRequestQueue extends SpecialPage {

	public function __construct() {
		parent::__construct( 'ImportDumpRequestQueue', 'requestimport' );
	}

	/**
	 * @param string $par
	 */
	public function execute( $par ) {
		$this->setHeaders();

		if ( $par === null || $par === '' ) {
			$this->doPagerStuff();
		} else {
			$this->lookupRequest( $par );
		}
	}

	private function doPagerStuff() {
		$requester = $this->getRequest()->getText( 'requester' );
		$status = $this->getRequest()->getText( 'status' );
		$source = $this->getRequest()->getText( 'source' );
		$target = $this->getRequest()->getText( 'target' );

		$formDescriptor = [
			'source' => [
				'type' => 'text',
				'name' => 'source',
				'label-message' => 'importdump-label-source',
				'default' => $source,
			],
			'target' => [
				'type' => 'text',
				'name' => 'target',
				'label-message' => 'importdump-label-target',
				'default' => $target,
			],
			'requester' => [
				'type' => 'user',
				'name' => 'requester',
				'label-message' => 'importdump-request-label-requester',
				'exist' => true,
				'default' => $requester,
			],
			'status' => [
				'type' => 'select',
				'name' => 'status',
				'label-message' => 'importdump-request-label-status',
				'options' => [
					'Unreviewed' => 'inreview',
					'In-progress' => 'inprogress',
					'Approved' => 'approved',
					'Declined' => 'declined',
					'All' => '*',
				],
				'default' => $status ?: 'inreview',
			],
		];

		$htmlForm = HTMLForm::factory( 'ooui', $formDescriptor, $this->getContext() );
		$htmlForm->setMethod( 'get' )->prepareForm()->displayForm( false );

		$pager = new ImportDumpRequestQueuePager( $this, $requester, $source, $target, $status );
		$table = $pager->getFullOutput();

		$this->getOutput()->addParserOutputContent( $table );
	}

	/**
	 * @param string $par
	 */
	private function lookupRequest( $par ) {
		$out = $this->getOutput();

		$out->addModules( [ 'ext.importdump.oouiform' ] );

		$requestViewer = new ImportDumpRequestViewer();
		$htmlForm = $requestViewer->getForm( $par, $this->getContext() );

		$htmlForm->show();
	}

	/**
	 * @return bool
	 */
	protected function getGroupName() {
		return 'wikimanage';
	}
}
