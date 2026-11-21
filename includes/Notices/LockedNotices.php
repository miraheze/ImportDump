<?php

namespace Miraheze\ImportDump\Notices;

use MediaWiki\Context\IContextSource;
use Miraheze\ImportDump\RequestManager;

final class LockedNotices implements NoticeProvider {

	/** @inheritDoc */
	public function getAudience(): string {
		return self::AUDIENCE_EVERYONE;
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
	 * @param string $status @phan-unused-param
	 */
	public function getNotices(
		RequestManager $requestManager,
		IContextSource $context,
		string $status
	): array {
		if ( !$requestManager->isLocked() ) {
			return [];
		}

		return [ new RequestNotice(
			severity: RequestNotice::SEVERITY_ERROR,
			html: $context->msg( 'importdump-request-locked' )->escaped(),
			invalidates: false,
			declines: false,
			pinned: false,
		) ];
	}
}
