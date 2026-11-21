<?php

namespace Miraheze\ImportDump\Notices;

use MediaWiki\Context\IContextSource;
use Miraheze\ImportDump\RequestHistory;
use Miraheze\ImportDump\RequestManager;

/** Missing revisions are the only thing about the history that the requester needs to know. */
final class PartialHistoryNotices implements NoticeProvider {

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
		if ( $requestManager->getHistory() !== RequestHistory::HISTORY_PARTIAL ) {
			return [];
		}

		return [ new RequestNotice(
			severity: RequestNotice::SEVERITY_WARNING,
			html: $context->msg( 'importdump-info-history-partial' )->escaped(),
			invalidates: false,
			declines: false,
			pinned: false,
		) ];
	}
}
