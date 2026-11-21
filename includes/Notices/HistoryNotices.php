<?php

namespace Miraheze\ImportDump\Notices;

use MediaWiki\Context\IContextSource;
use Miraheze\ImportDump\RequestHistory;
use Miraheze\ImportDump\RequestManager;

final class HistoryNotices implements NoticeProvider {

	/** @inheritDoc */
	public function getAudience(): string {
		return self::AUDIENCE_PARTIES;
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
		if ( $history === null ) {
			return [];
		}

		// Messages that can be used here:
		// * importdump-info-history-full
		// * importdump-info-history-partial
		// * importdump-info-history-unknown
		return [ new RequestNotice(
			severity: $history === RequestHistory::HISTORY_PARTIAL ?
				RequestNotice::SEVERITY_WARNING :
				RequestNotice::SEVERITY_NOTICE,
			html: $context->msg( 'importdump-info-history-' . $history )->escaped(),
			invalidates: false,
			declines: false,
			pinned: false,
		) ];
	}
}
