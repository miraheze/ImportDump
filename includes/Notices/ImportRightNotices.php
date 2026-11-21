<?php

namespace Miraheze\ImportDump\Notices;

use MediaWiki\Context\IContextSource;
use Miraheze\ImportDump\RequestManager;
use Miraheze\ImportDump\RequestStatus;

final class ImportRightNotices implements NoticeProvider {

	/** @inheritDoc */
	public function getAudience(): NoticeAudience {
		return NoticeAudience::Parties;
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
	 * @param RequestStatus $status @phan-unused-param
	 */
	public function getNotices(
		RequestManager $requestManager,
		IContextSource $context,
		RequestStatus $status
	): array {
		if ( $requestManager->requesterHasTargetRight( RequestManager::IMPORT_RIGHT ) !== false ) {
			return [];
		}

		return [ new RequestNotice(
			severity: NoticeSeverity::Warning,
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
