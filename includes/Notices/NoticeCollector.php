<?php

declare( strict_types = 1 );

namespace Miraheze\ImportDump\Notices;

use MediaWiki\Context\IContextSource;
use Miraheze\ImportDump\Request\RequestManager;
use Miraheze\ImportDump\Request\RequestStatus;
use function usort;

/**
 * Handlers see every notice, and the ones that are not always at the top go in the handling tab.
 * Everyone else sees only the notices meant for them, all above the form, and the ones from the
 * target wiki only if they have access to it.
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
		RequestStatus $status,
		NoticeRole $role,
		bool $hasTargetAccess
	): NoticeResult {
		$notices = [];
		$topNotices = [];
		$valid = true;

		foreach ( $this->providers as $provider ) {
			if ( !$this->isShownTo( $provider, $role, $hasTargetAccess ) ) {
				continue;
			}

			$atTop = $role !== NoticeRole::Handler || $provider->isAlwaysAtTop();
			foreach ( $provider->getNotices( $requestManager, $context, $status ) as $notice ) {
				if ( $atTop ) {
					$topNotices[] = $notice;
				} else {
					$notices[] = $notice;
				}

				$valid = $valid && !$notice->invalidates;

				if (
					$notice->declines &&
					( $status === RequestStatus::Pending || $status === RequestStatus::InProgress )
				) {
					$status = RequestStatus::Declined;
				}
			}
		}

		return new NoticeResult( $this->sort( $notices ), $this->sort( $topNotices ), $valid, $status );
	}

	private function isShownTo( NoticeProvider $provider, NoticeRole $role, bool $hasTargetAccess ): bool {
		if ( $role === NoticeRole::Handler ) {
			return true;
		}

		if ( $provider->requiresTargetAccess() && !$hasTargetAccess ) {
			return false;
		}

		return match ( $provider->getAudience() ) {
			NoticeAudience::Everyone => true,
			NoticeAudience::Parties => $role === NoticeRole::Requester,
			NoticeAudience::Handlers => false,
		};
	}

	/**
	 * @param RequestNotice[] $notices
	 * @return list<RequestNotice>
	 */
	private function sort( array $notices ): array {
		usort(
			$notices,
			static fn ( RequestNotice $a, RequestNotice $b ): int =>
				[ !$a->pinned, $a->severity->getRank() ] <=> [ !$b->pinned, $b->severity->getRank() ]
		);

		return $notices;
	}
}
