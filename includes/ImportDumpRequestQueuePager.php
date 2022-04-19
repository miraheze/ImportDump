<?php

namespace Miraheze\ImportDump;

use CentralAuthUser;
use Config;
use MediaWiki\Linker\LinkRenderer;
use RequestContext;
use SpecialPage;
use TablePager;
use Wikimedia\Rdbms\ILBFactory;

class ImportDumpRequestQueuePager extends TablePager {

	/** @var LinkRenderer */
	private $linkRenderer;

	/** @var string */
	private $requester;

	/** @var string */
	private $status;

	public function __construct(
		Config $config,
		RequestContext $context,
		ILBFactory $dbLoadBalancerFactory,
		LinkRenderer $linkRenderer,
		string $requester,
		string $source,
		string $target,
		string $status
	) {
		parent::__construct( $context, $linkRenderer );

		$this->mDb = $dbLoadBalancerFactory->getMainLB(
			$config->get( 'ImportDumpRequestsDatabase' )
		)->getConnectionRef( DB_REPLICA, [], $config->get( 'ImportDumpRequestsDatabase' ) );

		$this->linkRenderer = $linkRenderer;
		$this->requester = $requester;
		$this->status = $status;
	}

	public function getFieldNames() {
		static $headers = null;

		$headers = [
			'request_timestamp' => 'importdump-request-label-requested-date',
			'request_source' => 'importdump-label-source',
			'request_target' => 'importdump-label-target',
			'request_user' => 'importdump-request-label-requester',
			'request_status' => 'importdump-request-label-status',
		];

		foreach ( $headers as &$msg ) {
			$msg = $this->msg( $msg )->text();
		}

		return $headers;
	}

	public function formatValue( $name, $value ) {
		$row = $this->mCurrentRow;

		switch ( $name ) {
			case 'request_timestamp':
				$language = $this->getLanguage();
				$formatted = $language->timeanddate( $row->request_timestamp );
				break;
			case 'request_source':
				$formatted = $row->request_source;
				break;
			case 'request_target':
				$formatted = $row->request_target;
				break;
			case 'request_user':
				$globalUser = CentralAuthUser::newFromId( $row->request_user );
				$formatted = $globalUser->getName();
				break;
			case 'request_status':
				$formatted = $this->linkRenderer->makeLink(
					SpecialPage::getTitleValueFor( 'ImportDumpRequestQueue', $row->request_id ),
					$row->request_status
				);
				break;
			default:
				$formatted = "Unable to format $name";
				break;
		}

		return $formatted;
	}

	public function getQueryInfo() {
		$info = [
			'tables' => [
				'importdump_requests',
			],
			'fields' => [
				'request_id',
				'request_timestamp',
				'request_source',
				'request_target',
				'request_user',
				'request_status',
			],
			'joins_conds' => [],
		];

		if ( $this->requester ) {
			$globalUser = CentralAuthUser::getInstanceByName( $this->requester );
			$info['conds']['request_user'] = $globalUser->getId();
		}

		if ( $this->status && $this->status != '*' ) {
			$info['conds']['request_status'] = $this->status;
		} elseif ( !$this->status ) {
			$info['conds']['request_status'] = 'pending';
		}

		return $info;
	}

	public function getDefaultSort() {
		return 'request_id';
	}

	public function isFieldSortable( $name ) {
		return true;
	}
}
