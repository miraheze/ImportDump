<?php

declare( strict_types = 1 );

namespace Miraheze\ImportDump\Notices;

use MediaWiki\Context\IContextSource;
use Miraheze\ImportDump\Request\RequestHistory;
use Miraheze\ImportDump\Request\RequestManager;
use Miraheze\ImportDump\Request\RequestStatus;

/** What the history check found when it did not find missing revisions. */
final class HistoryNotices implements NoticeProvider {

	/** @inheritDoc */
	public function getAudience(): NoticeAudience {
		return NoticeAudience::Handlers;
	}

	/** @inheritDoc */
	public function isAlwaysAtTop(): false {
		return false;
	}

	/** @inheritDoc */
	public function requiresTargetAccess(): false {
		return false;
	}

	/**
	 * @inheritDoc
	 * @param RequestStatus $status @phan-unused-param
	 */
	public function getNotices(
		RequestManager $requestManager,
		IContextSource $context,
		RequestStatus $status
	): array {
		$history = $requestManager->getHistory();
		if ( $history === null || $history === RequestHistory::Partial ) {
			return [];
		}

		// Messages that can be used here:
		// * requestimport-info-history-full
		// * requestimport-info-history-unknown
		return [ new RequestNotice(
			severity: NoticeSeverity::Notice,
			html: $context->msg( $history->getInfoKey() )->escaped(),
			invalidates: false,
			declines: false,
			pinned: false,
		) ];
	}
}
