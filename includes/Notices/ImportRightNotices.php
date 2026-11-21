<?php

namespace Miraheze\ImportDump\Notices;

use MediaWiki\Context\IContextSource;
use Miraheze\ImportDump\RequestManager;

final class ImportRightNotices implements NoticeProvider {

	/** @inheritDoc */
	public function getAudience(): string {
		return self::AUDIENCE_PARTIES;
	}

	/** @inheritDoc */
	public function isAlwaysAtTop(): false {
		return false;
	}

	/** @inheritDoc */
	public function requiresTargetAccess(): true {
		return true;
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
		if ( $requestManager->requesterHasTargetRight( RequestManager::IMPORT_RIGHT ) !== false ) {
			return [];
		}

		return [ new RequestNotice(
			severity: RequestNotice::SEVERITY_WARNING,
			html: $context->msg( 'importdump-info-no-import-right',
				$requestManager->getRequester()->getName(),
				$requestManager->getTarget()
			)->escaped(),
			invalidates: false,
			declines: false,
			pinned: false,
		) ];
	}
}
