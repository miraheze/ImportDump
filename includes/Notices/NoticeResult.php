<?php

declare( strict_types = 1 );

namespace Miraheze\ImportDump\Notices;

use Miraheze\ImportDump\Request\RequestStatus;

final readonly class NoticeResult {

	/**
	 * @param RequestNotice[] $notices For the handling tab, with the most important first.
	 * @param RequestNotice[] $topNotices For above the form, with the most important first.
	 * @param bool $valid Whether the request can be started and have its status changed.
	 * @param RequestStatus $status The status to show, which a notice can change to declined.
	 */
	public function __construct(
		public array $notices,
		public array $topNotices,
		public bool $valid,
		public RequestStatus $status,
	) {
	}
}
