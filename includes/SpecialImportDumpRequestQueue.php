<?php

namespace Miraheze\ImportDump;

use HTMLForm;
use SpecialPage;
use Wikimedia\Rdbms\ILBFactory;

class SpecialImportDumpRequestQueue extends SpecialPage {

	/** @var ILBFactory */
	private $dbLoadBalancerFactory;

	/**
	 * @param ILBFactory $dbLoadBalancerFactory
	 */
	public function __construct( ILBFactory $dbLoadBalancerFactory ) {
		parent::__construct( 'ImportDumpRequestQueue', 'requestimport' );

		$this->dbLoadBalancerFactory = $dbLoadBalancerFactory;
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
					'Pending' => 'pending',
					'In-progress' => 'inprogress',
					'Approved' => 'approved',
					'Declined' => 'declined',
					'All' => '*',
				],
				'default' => $status ?: 'pending',
			],
		];

		$htmlForm = HTMLForm::factory( 'ooui', $formDescriptor, $this->getContext() );
		$htmlForm->setMethod( 'get' )->prepareForm()->displayForm( false );

		$pager = new ImportDumpRequestQueuePager(
			$this->getConfig(),
			$this->getContext(),
			$this->dbLoadBalancerFactory,
			$this->getLinkRenderer(),
			$requester,
			$source,
			$target,
			$status
		);

		$table = $pager->getFullOutput();

		$this->getOutput()->addParserOutputContent( $table );
	}

	/**
	 * @param string $par
	 */
	private function lookupRequest( $par ) {
		$out = $this->getOutput();

		$out->addModules( [ 'ext.importdump.oouiform' ] );

		// $requestViewer = new ImportDumpRequestViewer();
		// $htmlForm = $requestViewer->getForm( $par, $this->getContext() );

		// $htmlForm->show();
	}

	/**
	 * @return string
	 */
	protected function getGroupName() {
		return 'wikimanage';
	}
}
