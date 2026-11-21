<?php

namespace Miraheze\ImportDump\Notices;

final readonly class NoticeResult {

	/**
	 * @param RequestNotice[] $notices Sorted with the most important first.
	 * @param bool $valid Whether the request can be started and have its status changed.
	 * @param string $status The status to show, which a notice can change to declined.
	 */
	public function __construct(
		public array $notices,
		public bool $valid,
		public string $status,
	) {
	}
}
