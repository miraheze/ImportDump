<?php

namespace Miraheze\ImportDump\Notices;

use MediaWiki\Context\IContextSource;
use Miraheze\ImportDump\RequestManager;
use Miraheze\ImportDump\RequestStatus;

/**
 * Handlers see every notice, and the ones that are not always at the top go in the handling tab.
 * Everyone else sees only the notices meant for them, all above the form, and the ones from the
 * target wiki only if they have access to it.
 */
final class NoticeCollector {

	public const string ROLE_HANDLER = 'handler';

	public const string ROLE_REQUESTER = 'requester';

	public const string ROLE_VIEWER = 'viewer';

	/** @param NoticeProvider[] $providers In the order that they are asked. */
	public function __construct(
		private readonly array $providers,
	) {
	}

	public function collect(
		RequestManager $requestManager,
		IContextSource $context,
		string $status,
		string $role,
		bool $hasTargetAccess
	): NoticeResult {
		$notices = [];
		$topNotices = [];
		$valid = true;

		foreach ( $this->providers as $provider ) {
			if ( !$this->isShownTo( $provider, $role, $hasTargetAccess ) ) {
				continue;
			}

			$atTop = $role !== self::ROLE_HANDLER || $provider->isAlwaysAtTop();
			foreach ( $provider->getNotices( $requestManager, $context, $status ) as $notice ) {
				if ( $atTop ) {
					$topNotices[] = $notice;
				} else {
					$notices[] = $notice;
				}

				$valid = $valid && !$notice->invalidates;

				if (
					$notice->declines &&
					in_array( $status, [ RequestStatus::STATUS_PENDING, RequestStatus::STATUS_INPROGRESS ], true )
				) {
					$status = RequestStatus::STATUS_DECLINED;
				}
			}
		}

		return new NoticeResult( $this->sort( $notices ), $this->sort( $topNotices ), $valid, $status );
	}

	private function isShownTo( NoticeProvider $provider, string $role, bool $hasTargetAccess ): bool {
		if ( $role === self::ROLE_HANDLER ) {
			return true;
		}

		if ( $provider->requiresTargetAccess() && !$hasTargetAccess ) {
			return false;
		}

		return match ( $provider->getAudience() ) {
			NoticeProvider::AUDIENCE_EVERYONE => true,
			NoticeProvider::AUDIENCE_PARTIES => $role === self::ROLE_REQUESTER,
			default => false,
		};
	}

	/**
	 * @param RequestNotice[] $notices
	 * @return RequestNotice[]
	 */
	private function sort( array $notices ): array {
		usort(
			$notices,
			static fn ( RequestNotice $a, RequestNotice $b ): int =>
				[ !$a->pinned, array_search( $a->severity, RequestNotice::SEVERITIES, true ) ] <=>
				[ !$b->pinned, array_search( $b->severity, RequestNotice::SEVERITIES, true ) ]
		);

		return $notices;
	}
}
