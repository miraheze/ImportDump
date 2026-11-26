<?php

declare( strict_types = 1 );

namespace Miraheze\ImportDump\Notices;

use MediaWiki\Context\IContextSource;
use Miraheze\ImportDump\Request\RequestManager;
use Miraheze\ImportDump\Request\RequestStatus;
use function count;

final class RequiredRightsNotices implements NoticeProvider {

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
		$missing = $requestManager->getMissingTargetRights();
		if ( !$missing ) {
			return [];
		}

		return [ new RequestNotice(
			severity: NoticeSeverity::Warning,
			html: $context->msg( 'requestimport-info-missing-rights',
				$requestManager->getRequester()->getName(),
				$requestManager->getTarget(),
				$context->getLanguage()->commaList( $missing ),
				count( $missing )
			)->escaped(),
			invalidates: false,
			declines: false,
			pinned: false,
		) ];
	}
}
