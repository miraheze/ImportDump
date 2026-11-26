<?php

declare( strict_types = 1 );

namespace Miraheze\ImportDump\Notices;

use MediaWiki\Context\IContextSource;
use MediaWiki\WikiMap\WikiMap;
use Miraheze\ImportDump\Request\RequestManager;
use Miraheze\ImportDump\Request\RequestStatus;

final class RequesterNotices implements NoticeProvider {

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
		$notices = [];
		$requester = $requestManager->getRequester();

		if ( $requestManager->isPrivate() ) {
			$notices[] = new RequestNotice(
				severity: NoticeSeverity::Warning,
				html: $context->msg( 'requestimport-info-request-private' )->escaped(),
				invalidates: false,
				declines: false,
				pinned: false,
			);
		}

		if ( $requester->getBlock() ) {
			$notices[] = new RequestNotice(
				severity: NoticeSeverity::Warning,
				html: $context->msg(
					'requestimport-info-requester-blocked',
					$requester->getName(),
					WikiMap::getCurrentWikiId()
				)->escaped(),
				invalidates: false,
				declines: false,
				pinned: false,
			);
		}

		if ( $requester->isLocked() ) {
			$notices[] = new RequestNotice(
				severity: NoticeSeverity::Error,
				html: $context->msg( 'requestimport-info-requester-locked', $requester->getName() )->escaped(),
				invalidates: true,
				declines: true,
				pinned: false,
			);
		}

		return $notices;
	}
}
