<?php

namespace Miraheze\ImportDump\Specials;

use HTMLForm;
use MediaWiki\Permissions\PermissionManager;
use MediaWiki\SpecialPage\SpecialPage;
use MediaWiki\User\UserFactory;
use Miraheze\ImportDump\ImportDumpRequestManager;
use Miraheze\ImportDump\ImportDumpRequestQueuePager;
use Miraheze\ImportDump\ImportDumpRequestViewer;
use Miraheze\ImportDump\ImportDumpStatus;
use Wikimedia\Rdbms\IConnectionProvider;

class SpecialRequestImportQueue extends SpecialPage
	implements ImportDumpStatus {

	/** @var IConnectionProvider */
	private $connectionProvider;

	/** @var ImportDumpRequestManager */
	private $importDumpRequestManager;

	/** @var PermissionManager */
	private $permissionManager;

	/** @var UserFactory */
	private $userFactory;

	/**
	 * @param IConnectionProvider $connectionProvider
	 * @param ImportDumpRequestManager $importDumpRequestManager
	 * @param PermissionManager $permissionManager
	 * @param UserFactory $userFactory
	 */
	public function __construct(
		IConnectionProvider $connectionProvider,
		ImportDumpRequestManager $importDumpRequestManager,
		PermissionManager $permissionManager,
		UserFactory $userFactory
	) {
		parent::__construct( 'RequestImportQueue' );

		$this->connectionProvider = $connectionProvider;
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
				'default' => $this->msg( 'requestimportqueue-header-info' )->text(),
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
					'importdump-label-pending' => self::STATUS_PENDING,
					'importdump-label-starting' => self::STATUS_STARTING,
					'importdump-label-inprogress' => self::STATUS_INPROGRESS,
					'importdump-label-complete' => self::STATUS_COMPLETE,
					'importdump-label-declined' => self::STATUS_DECLINED,
					'importdump-label-failed' => self::STATUS_FAILED,
					'importdump-label-all' => '*',
				],
				'default' => $status ?: self::STATUS_PENDING,
			],
		];

		$htmlForm = HTMLForm::factory( 'ooui', $formDescriptor, $this->getContext() );
		$htmlForm
			->setMethod( 'get' )
			->setWrapperLegendMsg( 'requestimportqueue-header' )
			->setSubmitTextMsg( 'search' )
			->prepareForm()
			->displayForm( false );

		$pager = new ImportDumpRequestQueuePager(
			$this->getConfig(),
			$this->getContext(),
			$this->connectionProvider,
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
