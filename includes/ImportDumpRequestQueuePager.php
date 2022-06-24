<?php

namespace Miraheze\ImportDump;

use Config;
use IContextSource;
use MediaWiki\Linker\LinkRenderer;
use MediaWiki\User\UserFactory;
use SpecialPage;
use TablePager;
use Wikimedia\Rdbms\ILBFactory;

class ImportDumpRequestQueuePager extends TablePager {

	/** @var LinkRenderer */
	private $linkRenderer;

	/** @var UserFactory */
	private $userFactory;

	/** @var string */
	private $requester;

	/** @var string */
	private $status;

	/** @var string */
	private $target;

	/**
	 * @param Config $config
	 * @param IContextSource $context
	 * @param ILBFactory $dbLoadBalancerFactory
	 * @param LinkRenderer $linkRenderer
	 * @param UserFactory $userFactory
	 * @param string $requester
	 * @param string $status
	 * @param string $target
	 */
	public function __construct(
		Config $config,
		IContextSource $context,
		ILBFactory $dbLoadBalancerFactory,
		LinkRenderer $linkRenderer,
		UserFactory $userFactory,
		string $requester,
		string $status,
		string $target
	) {
		parent::__construct( $context, $linkRenderer );

		$centralWiki = $config->get( 'ImportDumpCentralWiki' );
		if ( $centralWiki ) {
			$this->mDb = $dbLoadBalancerFactory->getMainLB(
				$centralWiki
			)->getConnection( DB_REPLICA, [], $centralWiki );
		} else {
			$this->mDb = $dbLoadBalancerFactory->getMainLB()->getConnection( DB_REPLICA );
		}

		$this->linkRenderer = $linkRenderer;
		$this->userFactory = $userFactory;

		$this->requester = $requester;
		$this->status = $status;
		$this->target = $target;
	}

	/**
	 * @return array
	 */
	protected function getFieldNames() {
		return [
			'request_timestamp' => $this->msg( 'importdump-table-requested-date' )->text(),
			'request_actor' => $this->msg( 'importdump-table-requester' )->text(),
			'request_status' => $this->msg( 'importdump-table-status' )->text(),
			'request_target' => $this->msg( 'importdump-table-target' )->text(),
		];
	}

	/**
	 * @param string $name
	 * @param string $value
	 * @return string
	 */
	public function formatValue( $name, $value ) {
		$row = $this->mCurrentRow;

		switch ( $name ) {
			case 'request_timestamp':
				$language = $this->getLanguage();
				$formatted = $language->timeanddate( $row->request_timestamp );

				break;
			case 'request_target':
				$formatted = $row->request_target;

				break;
			case 'request_status':
				$formatted = $this->linkRenderer->makeLink(
					SpecialPage::getTitleValueFor( 'RequestImportDumpQueue', $row->request_id ),
					$this->msg( 'importdump-label-' . $row->request_status )->text()
				);

				break;
			case 'request_actor':
				$user = $this->userFactory->newFromActorId( $row->request_actor );
				$formatted = $user->getName();

				break;
			default:
				$formatted = "Unable to format $name";
		}

		return $formatted;
	}

	/**
	 * @return array
	 */
	public function getQueryInfo() {
		$info = [
			'tables' => [
				'importdump_requests',
			],
			'fields' => [
				'request_actor',
				'request_id',
				'request_status',
				'request_timestamp',
				'request_target',
			],
			'conds' => [],
			'joins_conds' => [],
		];

		if ( $this->target ) {
			$info['conds']['request_target'] = $this->target;
		}

		if ( $this->requester ) {
			$user = $this->userFactory->newFromName( $this->requester );
			$info['conds']['request_actor'] = $user->getActorId();
		}

		if ( $this->status && $this->status != '*' ) {
			$info['conds']['request_status'] = $this->status;
		} elseif ( !$this->status ) {
			$info['conds']['request_status'] = 'pending';
		}

		return $info;
	}

	/**
	 * @return string
	 */
	public function getDefaultSort() {
		return 'request_id';
	}

	/**
	 * @param string $name
	 * @return bool
	 */
	protected function isFieldSortable( $name ) {
		return $name !== 'request_actor';
	}
}
