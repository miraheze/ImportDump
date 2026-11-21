<?php

namespace Miraheze\ImportDump\Notices;

use MediaWiki\Context\IContextSource;
use MediaWiki\WikiMap\WikiMap;
use Miraheze\ImportDump\RequestManager;

final class RequesterNotices implements NoticeProvider {

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
		$notices = [];
		$requester = $requestManager->getRequester();

		if ( $requestManager->isPrivate( forced: false ) ) {
			$notices[] = new RequestNotice(
				severity: RequestNotice::SEVERITY_WARNING,
				html: $context->msg( 'importdump-info-request-private' )->escaped(),
				invalidates: false,
				declines: false,
				pinned: false,
			);
		}

		if ( $requester->getBlock() ) {
			$notices[] = new RequestNotice(
				severity: RequestNotice::SEVERITY_WARNING,
				html: $context->msg(
					'importdump-info-requester-blocked',
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
				severity: RequestNotice::SEVERITY_ERROR,
				html: $context->msg( 'importdump-info-requester-locked', $requester->getName() )->escaped(),
				invalidates: true,
				declines: true,
				pinned: false,
			);
		}

		return $notices;
	}
}
