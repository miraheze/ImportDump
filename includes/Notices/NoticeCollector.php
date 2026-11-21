<?php

namespace Miraheze\ImportDump\Notices;

use MediaWiki\Context\IContextSource;
use Miraheze\ImportDump\RequestManager;
use Miraheze\ImportDump\RequestStatus;

/**
 * Handlers see every notice. Anyone else is the requester, and sees only the notices meant for
 * them, and the ones from the target wiki only if they have access to it.
 */
final class NoticeCollector {

	/** @param NoticeProvider[] $providers In the order that they are asked. */
	public function __construct(
		private readonly array $providers,
	) {
	}

	public function collect(
		RequestManager $requestManager,
		IContextSource $context,
		string $status,
		bool $forHandler,
		bool $hasTargetAccess
	): NoticeResult {
		$notices = [];
		$valid = true;

		foreach ( $this->providers as $provider ) {
			if ( !$forHandler && $provider->getAudience() !== NoticeProvider::AUDIENCE_PARTIES ) {
				continue;
			}

			if ( !$forHandler && $provider->requiresTargetAccess() && !$hasTargetAccess ) {
				continue;
			}

			$pending = $status;
			foreach ( $provider->getNotices( $requestManager, $context, $pending ) as $notice ) {
				$notices[] = $notice;
				$valid = $valid && !$notice->invalidates;

				if (
					$notice->declines &&
					in_array( $status, [ RequestStatus::STATUS_PENDING, RequestStatus::STATUS_INPROGRESS ], true )
				) {
					$status = RequestStatus::STATUS_DECLINED;
				}
			}
		}

		usort(
			$notices,
			static fn ( RequestNotice $a, RequestNotice $b ): int =>
				[ !$a->pinned, array_search( $a->severity, RequestNotice::SEVERITIES, true ) ] <=>
				[ !$b->pinned, array_search( $b->severity, RequestNotice::SEVERITIES, true ) ]
		);

		return new NoticeResult( $notices, $valid, $status );
	}
}
