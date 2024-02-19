<?php

namespace Miraheze\ImportDump\Specials;

use HTMLForm;
use MediaWiki\Permissions\PermissionManager;
use MediaWiki\User\UserFactory;
use Miraheze\ImportDump\ImportDumpRequestManager;
use Miraheze\ImportDump\ImportDumpRequestQueuePager;
use Miraheze\ImportDump\ImportDumpRequestViewer;
use SpecialPage;
use Wikimedia\Rdbms\ILBFactory;

class SpecialRequestImportDumpQueue extends SpecialPage {

	/** @var ILBFactory */
	private $dbLoadBalancerFactory;

	/** @var ImportDumpRequestManager */
	private $importDumpRequestManager;

	/** @var PermissionManager */
	private $permissionManager;

	/** @var UserFactory */
	private $userFactory;

	/**
	 * @param ILBFactory $dbLoadBalancerFactory
	 * @param ImportDumpRequestManager $importDumpRequestManager
	 * @param PermissionManager $permissionManager
	 * @param UserFactory $userFactory
	 */
	public function __construct(
		ILBFactory $dbLoadBalancerFactory,
		ImportDumpRequestManager $importDumpRequestManager,
		PermissionManager $permissionManager,
		UserFactory $userFactory
	) {
		parent::__construct( 'RequestImportDumpQueue' );

		$this->dbLoadBalancerFactory = $dbLoadBalancerFactory;
		$this->importDumpRequestManager = $importDumpRequestManager;
		$this->permissionManager = $permissionManager;
		$this->userFactory = $userFactory;
	}

	/**
	 * @param string $par
	 */
	public function execute( $par ) {
		$this->setHeaders();

		if ( $par ) {
			$this->getOutput()->addBacklinkSubtitle( $this->getPageTitle() );
			$this->lookupRequest( $par );
			return;
		}

		$this->doPagerStuff();
	}

	private function doPagerStuff() {
		$requester = $this->getRequest()->getText( 'requester' );
		$status = $this->getRequest()->getText( 'status' );
		$target = $this->getRequest()->getText( 'target' );

		$formDescriptor = [
			'info' => [
				'type' => 'info',
				'default' => $this->msg( 'requestimportdumpqueue-header-info' )->text(),
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
				'label-message' => 'importdump-label-requester',
				'exist' => true,
				'default' => $requester,
			],
			'status' => [
				'type' => 'select',
				'name' => 'status',
				'label-message' => 'importdump-label-status',
				'options-messages' => [
					'importdump-label-pending' => 'pending',
					'importdump-label-inprogress' => 'inprogress',
					'importdump-label-complete' => 'complete',
					'importdump-label-declined' => 'declined',
					'importdump-label-all' => '*',
				],
				'default' => $status ?: 'pending',
			],
		];

		$htmlForm = HTMLForm::factory( 'ooui', $formDescriptor, $this->getContext() );
		$htmlForm
			->setMethod( 'get' )
			->setWrapperLegendMsg( 'requestimportdumpqueue-header' )
			->setSubmitTextMsg( 'search' )
			->prepareForm()
			->displayForm( false );

		$pager = new ImportDumpRequestQueuePager(
			$this->getConfig(),
			$this->getContext(),
			$this->dbLoadBalancerFactory,
			$this->getLinkRenderer(),
			$this->userFactory,
			$requester,
			$status,
			$target
		);

		$table = $pager->getFullOutput();

		$this->getOutput()->addParserOutputContent( $table );
	}

	/**
	 * @param string $par
	 */
	private function lookupRequest( $par ) {
		$requestViewer = new ImportDumpRequestViewer(
			$this->getConfig(),
			$this->getContext(),
			$this->importDumpRequestManager,
			$this->permissionManager
		);

		$htmlForm = $requestViewer->getForm( (int)$par );

		if ( $htmlForm ) {
			$htmlForm->show();
		}
	}

	/**
	 * @return string
	 */
	protected function getGroupName() {
		return 'other';
	}
}
