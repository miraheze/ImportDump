<?php

namespace Miraheze\ImportDump;

use MediaWiki\Context\IContextSource;
use MediaWiki\Linker\LinkRenderer;
use MediaWiki\Pager\TablePager;
use MediaWiki\SpecialPage\SpecialPage;
use MediaWiki\User\UserFactory;
use Wikimedia\Rdbms\IConnectionProvider;

class ImportDumpRequestQueuePager extends TablePager
	implements ImportDumpStatus {

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
	 * @param IContextSource $context
	 * @param IConnectionProvider $connectionProvider
	 * @param LinkRenderer $linkRenderer
	 * @param UserFactory $userFactory
	 * @param string $requester
	 * @param string $status
	 * @param string $target
	 */
	public function __construct(
		IContextSource $context,
		IConnectionProvider $connectionProvider,
		LinkRenderer $linkRenderer,
		UserFactory $userFactory,
		string $requester,
		string $status,
		string $target
	) {
		parent::__construct( $context, $linkRenderer );

		$this->mDb = $connectionProvider->getReplicaDatabase( 'virtual-importdump' );

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
	 * Safely HTML-escape $value
	 *
	 * @param string $value
	 * @return string
	 */
	private static function escape( $value ) {
		return htmlspecialchars( $value, ENT_QUOTES );
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
				$formatted = $this->escape( $language->timeanddate( $row->request_timestamp ) );

				break;
			case 'request_target':
				// @phan-suppress-next-line SecurityCheck-LikelyFalsePositive Phan will only shut up if I put it here
				$formatted = $this->escape( $row->request_target );

				break;
			case 'request_status':
				$formatted = $this->linkRenderer->makeLink(
					SpecialPage::getTitleValueFor( 'RequestImportQueue', $row->request_id ),
					$this->msg( 'importdump-label-' . $row->request_status )->text()
				);

				break;
			case 'request_actor':
				$user = $this->userFactory->newFromActorId( $row->request_actor );
				$formatted = $this->escape( $user->getName() );

				break;
			default:
				$formatted = $this->escape( "Unable to format $name" );
		}

		return $formatted;
	}

	/**
	 * @return array
	 */
	public function getQueryInfo() {
		$info = [
			'tables' => [
				'import_requests',
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
			$info['conds']['request_status'] = self::STATUS_PENDING;
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
