<?php

declare( strict_types = 1 );

namespace Miraheze\ImportDump\Notices;

use MediaWiki\Context\IContextSource;
use Miraheze\ImportDump\Request\RequestHistory;
use Miraheze\ImportDump\Request\RequestManager;
use Miraheze\ImportDump\Request\RequestStatus;

/** Missing revisions are the only thing about the history that the requester needs to know. */
final class PartialHistoryNotices implements NoticeProvider {

	/** @inheritDoc */
	public function getAudience(): NoticeAudience {
		return NoticeAudience::Parties;
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
		if ( $requestManager->getHistory() !== RequestHistory::Partial ) {
			return [];
		}

		return [ new RequestNotice(
			severity: NoticeSeverity::Warning,
			html: $context->msg( 'requestimport-info-history-partial' )->escaped(),
			invalidates: false,
			declines: false,
			pinned: false,
		) ];
	}
}
