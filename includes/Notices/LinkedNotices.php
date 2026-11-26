<?php

declare( strict_types = 1 );

namespace Miraheze\ImportDump\Notices;

use MediaWiki\Context\IContextSource;
use MediaWiki\Html\Html;
use MediaWiki\SpecialPage\SpecialPage;
use Miraheze\ImportDump\Request\RequestManager;
use Miraheze\ImportDump\Request\RequestStatus;
use Miraheze\ImportDump\Request\RequestType;
use function mb_strtolower;

/** Points the XML request and the images request that were made together at each other. */
final class LinkedNotices implements NoticeProvider {

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
		$notices = [];
		$isImages = $requestManager->getType() === RequestType::Images;

		foreach ( $requestManager->getLinkedIDs() as $linkedID ) {
			$linkedStatus = $requestManager->getLinkedStatus( $linkedID );
			if ( $linkedStatus === null ) {
				continue;
			}

			$link = Html::element(
				'a',
				[ 'href' => SpecialPage::getTitleFor( 'RequestImportQueue', (string)$linkedID )->getLocalURL() ],
				"#$linkedID"
			);

			$notices[] = new RequestNotice(
				severity: NoticeSeverity::Notice,
				html: $context->msg( $isImages ? 'requestimport-info-linked-xml' : 'requestimport-info-linked-images' )
					->rawParams( $link )
					->params( mb_strtolower( $context->msg( $linkedStatus->getLabelKey() )->text() ) )
					->escaped(),
				invalidates: false,
				declines: false,
				pinned: false,
			);
		}

		return $notices;
	}
}
