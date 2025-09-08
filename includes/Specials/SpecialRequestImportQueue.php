<?php

namespace Miraheze\ImportDump\Specials;

use MediaWiki\Exception\ErrorPageError;
use MediaWiki\HTMLForm\HTMLForm;
use MediaWiki\SpecialPage\SpecialPage;
use MediaWiki\User\UserFactory;
use MediaWiki\WikiMap\WikiMap;
use Miraheze\ImportDump\ImportDumpRequestQueuePager;
use Miraheze\ImportDump\ImportDumpStatus;
use Miraheze\ImportDump\RequestManager;
use Miraheze\ImportDump\RequestViewer;
use Wikimedia\Rdbms\IConnectionProvider;

class SpecialRequestImportQueue extends SpecialPage
	implements ImportDumpStatus {

	public function __construct(
		private readonly IConnectionProvider $connectionProvider,
		private readonly RequestManager $requestManager,
		private readonly UserFactory $userFactory
	) {
		parent::__construct( 'RequestImportQueue' );
	}

	/**
	 * @param ?string $par
	 * @throws ErrorPageError
	 */
	public function execute( $par ): void {
		$this->setHeaders();

		$dbr = $this->connectionProvider->getReplicaDatabase( 'virtual-importdump' );
		if ( !WikiMap::isCurrentWikiDbDomain( $dbr->getDomainID() ) ) {
			throw new ErrorPageError(
				'importdump-requestimportqueue-notcentral',
				'importdump-requestimportqueue-notcentral-text'
			);
		}

		if ( $par ) {
			$this->getOutput()->addBacklinkSubtitle( $this->getPageTitle() );
			$this->lookupRequest( $par );
			return;
		}

		$this->doPagerStuff();
	}

	private function doPagerStuff(): void {
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

	private function lookupRequest( string $par ): void {
		$requestViewer = new RequestViewer(
			$this->getConfig(),
			$this->getContext(),
			$this->requestManager
		);

		$htmlForm = $requestViewer->getForm( (int)$par );
		if ( $htmlForm ) {
			$htmlForm->show();
		}
	}

	/** @inheritDoc */
	protected function getGroupName(): string {
		return 'other';
	}
}
