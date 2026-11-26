<?php

declare( strict_types = 1 );

namespace Miraheze\ImportDump\Notices;

use MediaWiki\Context\IContextSource;
use Miraheze\ImportDump\Request\RequestManager;
use Miraheze\ImportDump\Request\RequestStatus;

final class LockedNotices implements NoticeProvider {

	/** @inheritDoc */
	public function getAudience(): NoticeAudience {
		return NoticeAudience::Everyone;
	}

	/** @inheritDoc */
	public function isAlwaysAtTop(): true {
		return true;
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
		if ( !$requestManager->isLocked() ) {
			return [];
		}

		return [ new RequestNotice(
			severity: NoticeSeverity::Error,
			html: $context->msg( 'requestimport-request-locked' )->escaped(),
			invalidates: false,
			declines: false,
			pinned: false,
		) ];
	}
}
