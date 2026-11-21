<?php

namespace Miraheze\ImportDump\Notices;

use MediaWiki\Context\IContextSource;
use Miraheze\ImportDump\RequestManager;

final class TargetNotices implements NoticeProvider {

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
		if ( !$requestManager->targetExists() ) {
			return [ new RequestNotice(
				severity: RequestNotice::SEVERITY_ERROR,
				html: $context->msg( 'importdump-info-target-missing', $requestManager->getTarget() )->escaped(),
				invalidates: true,
				declines: true,
				pinned: false,
			) ];
		}

		$notices = [];
		if ( $requestManager->requesterHasTargetRight( RequestManager::IMPORT_RIGHT ) === null ) {
			$notices[] = new RequestNotice(
				severity: RequestNotice::SEVERITY_NOTICE,
				html: $context->msg( 'importdump-info-groups',
					$requestManager->getRequester()->getName(),
					$requestManager->getTarget(),
					$context->getLanguage()->commaList( $requestManager->getUserGroupsFromTarget() )
				)->escaped(),
				invalidates: false,
				declines: false,
				pinned: false,
			);
		}

		if ( !$requestManager->getInterwikiPrefix() ) {
			$notices[] = new RequestNotice(
				severity: RequestNotice::SEVERITY_ERROR,
				html: $context->msg( 'importdump-info-no-interwiki-prefix',
					$requestManager->getTarget(),
					parse_url( $requestManager->getSource(), PHP_URL_HOST )
				)->escaped(),
				invalidates: false,
				declines: false,
				pinned: false,
			);
		}

		return $notices;
	}
}
