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

	public function __construct(
		IContextSource $context,
		IConnectionProvider $connectionProvider,
		LinkRenderer $linkRenderer,
		private readonly UserFactory $userFactory,
		private readonly string $requester,
		private readonly string $status,
		private readonly string $target
	) {
		parent::__construct( $context, $linkRenderer );
		$this->mDb = $connectionProvider->getReplicaDatabase( 'virtual-importdump' );
	}

	/** @inheritDoc */
	protected function getFieldNames(): array {
		return [
			'request_timestamp' => $this->msg( 'importdump-table-requested-date' )->text(),
			'request_actor' => $this->msg( 'importdump-table-requester' )->text(),
			'request_status' => $this->msg( 'importdump-table-status' )->text(),
			'request_target' => $this->msg( 'importdump-table-target' )->text(),
		];
	}

	/** @inheritDoc */
	public function formatValue( $name, $value ): string {
		if ( $value === null ) {
			return '';
		}

		switch ( $name ) {
			case 'request_timestamp':
				$formatted = htmlspecialchars( $this->getLanguage()->userTimeAndDate(
					$value, $this->getUser()
				) );
				break;
			case 'request_target':
				$formatted = htmlspecialchars( $value );
				break;
			case 'request_status':
				$row = $this->getCurrentRow();
				$formatted = $this->getLinkRenderer()->makeLink(
					SpecialPage::getTitleValueFor( 'RequestImportQueue', $row->request_id ),
					$this->msg( "importdump-label-$value" )->text()
				);
				break;
			case 'request_actor':
				$user = $this->userFactory->newFromActorId( (int)$value );
				$formatted = htmlspecialchars( $user->getName() );
				break;
			default:
				$formatted = "Unable to format $name";
		}

		return $formatted;
	}

	/** @inheritDoc */
	public function getQueryInfo(): array {
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

		if ( $this->status && $this->status !== '*' ) {
			$info['conds']['request_status'] = $this->status;
		} elseif ( !$this->status ) {
			$info['conds']['request_status'] = self::STATUS_PENDING;
		}

		return $info;
	}

	/** @inheritDoc */
	public function getDefaultSort(): string {
		return 'request_id';
	}

	/** @inheritDoc */
	protected function isFieldSortable( $field ): bool {
		return $field !== 'request_actor';
	}
}
