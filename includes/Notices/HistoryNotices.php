<?php

namespace Miraheze\ImportDump\Notices;

use MediaWiki\Context\IContextSource;
use Miraheze\ImportDump\RequestHistory;
use Miraheze\ImportDump\RequestManager;

/** What the history check found when it did not find missing revisions. */
final class HistoryNotices implements NoticeProvider {

	/** @inheritDoc */
	public function getAudience(): string {
		return self::AUDIENCE_HANDLERS;
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
	 * @param string $status @phan-unused-param
	 */
	public function getNotices(
		RequestManager $requestManager,
		IContextSource $context,
		string $status
	): array {
		$history = $requestManager->getHistory();
		if ( $history === null || $history === RequestHistory::HISTORY_PARTIAL ) {
			return [];
		}

		// Messages that can be used here:
		// * importdump-info-history-full
		// * importdump-info-history-unknown
		return [ new RequestNotice(
			severity: RequestNotice::SEVERITY_NOTICE,
			html: $context->msg( 'importdump-info-history-' . $history )->escaped(),
			invalidates: false,
			declines: false,
			pinned: false,
		) ];
	}
}
