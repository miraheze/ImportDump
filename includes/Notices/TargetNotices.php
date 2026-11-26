<?php

declare( strict_types = 1 );

namespace Miraheze\ImportDump\Notices;

use MediaWiki\Context\IContextSource;
use Miraheze\ImportDump\Request\RequestManager;
use Miraheze\ImportDump\Request\RequestStatus;
use Miraheze\ImportDump\Request\RequestType;
use function parse_url;
use const PHP_URL_HOST;

final class TargetNotices implements NoticeProvider {

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
		if ( !$requestManager->targetExists() ) {
			return [ new RequestNotice(
				severity: NoticeSeverity::Error,
				html: $context->msg( 'requestimport-info-target-missing', $requestManager->getTarget() )->escaped(),
				invalidates: true,
				declines: true,
				pinned: false,
			) ];
		}

		$notices = [ new RequestNotice(
			severity: NoticeSeverity::Notice,
			html: $context->msg( 'requestimport-info-groups',
				$requestManager->getRequester()->getName(),
				$requestManager->getTarget(),
				$context->getLanguage()->commaList( $requestManager->getUserGroupsFromTarget() )
			)->escaped(),
			invalidates: false,
			declines: false,
			pinned: false,
		) ];

		if ( $requestManager->getType() !== RequestType::Images && !$requestManager->getInterwikiPrefix() ) {
			$notices[] = new RequestNotice(
				severity: NoticeSeverity::Error,
				html: $context->msg( 'requestimport-info-no-interwiki-prefix',
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
