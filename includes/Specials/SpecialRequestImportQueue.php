<?php

declare( strict_types = 1 );

namespace Miraheze\ImportDump\Specials;

use MediaWiki\Exception\ErrorPageError;
use MediaWiki\HTMLForm\HTMLForm;
use MediaWiki\Parser\ParserOptions;
use MediaWiki\SpecialPage\SpecialPage;
use MediaWiki\User\UserFactory;
use MediaWiki\WikiMap\WikiMap;
use Miraheze\ImportDump\Pager\RequestImportQueuePager;
use Miraheze\ImportDump\Request\RequestManager;
use Miraheze\ImportDump\Request\RequestStatus;
use Miraheze\ImportDump\Requirements\ExtensionNames;
use Miraheze\ImportDump\Viewer\RequestViewer;
use Wikimedia\Rdbms\IConnectionProvider;

class SpecialRequestImportQueue extends SpecialPage {

	public function __construct(
		private readonly IConnectionProvider $connectionProvider,
		private readonly ExtensionNames $extensionNames,
		private readonly RequestManager $requestManager,
		private readonly UserFactory $userFactory,
	) {
		parent::__construct( 'RequestImportQueue' );
	}

	/**
	 * @param ?string $par
	 * @throws ErrorPageError
	 */
	public function execute( $par ): void {
		$this->setHeaders();

		$dbr = $this->connectionProvider->getReplicaDatabase( RequestManager::VIRTUAL_DOMAIN );
		if ( !WikiMap::isCurrentWikiDbDomain( $dbr->getDomainID() ) ) {
			throw new ErrorPageError(
				'requestimport-requestimportqueue-notcentral',
				'requestimport-requestimportqueue-notcentral-text'
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
				'label-message' => 'requestimport-label-target',
				'default' => $target,
			],
			'requester' => [
				'type' => 'user',
				'name' => 'requester',
				'label-message' => 'requestimport-label-requester',
				'exists' => true,
				'excludetemp' => true,
				'default' => $requester,
			],
			'status' => [
				'type' => 'select',
				'name' => 'status',
				'label-message' => 'requestimport-label-status',
				'options-messages' => [
					'requestimport-label-pending' => RequestStatus::Pending->value,
					'requestimport-label-verifying' => RequestStatus::Verifying->value,
					'requestimport-label-starting' => RequestStatus::Starting->value,
					'requestimport-label-inprogress' => RequestStatus::InProgress->value,
					'requestimport-label-partial' => RequestStatus::Partial->value,
					'requestimport-label-complete' => RequestStatus::Complete->value,
					'requestimport-label-declined' => RequestStatus::Declined->value,
					'requestimport-label-failed' => RequestStatus::Failed->value,
					'requestimport-label-all' => '*',
				],
				'default' => $status ?: RequestStatus::Pending->value,
			],
		];

		$htmlForm = HTMLForm::factory( 'ooui', $formDescriptor, $this->getContext() );
		$htmlForm
			->setMethod( 'get' )
			->setWrapperLegendMsg( 'requestimportqueue-header' )
			->setSubmitTextMsg( 'search' )
			->prepareForm()
			->displayForm( false );

		$pager = new RequestImportQueuePager(
			$this->getContext(),
			$this->connectionProvider,
			$this->getLinkRenderer(),
			$this->userFactory,
			$requester,
			$status,
			$target
		);

		$table = $pager->getFullOutput();
		$parserOptions = ParserOptions::newFromContext( $this->getContext() );
		$this->getOutput()->addParserOutputContent( $table, $parserOptions );
	}

	private function lookupRequest( string $par ): void {
		$requestViewer = new RequestViewer(
			$this->getConfig(),
			$this->getContext(),
			$this->extensionNames,
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
