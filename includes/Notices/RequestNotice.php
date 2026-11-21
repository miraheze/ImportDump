<?php

declare( strict_types = 1 );

namespace Miraheze\ImportDump\Notices;

final readonly class RequestNotice {

	/**
	 * @param NoticeSeverity $severity How serious the notice is.
	 * @param string $html The message, already escaped or parsed.
	 * @param bool $invalidates Whether the request cannot be started or have its status changed.
	 * @param bool $declines Whether a pending or running request is shown as declined.
	 * @param bool $pinned Whether the notice stays above the others, whatever their severity.
	 */
	public function __construct(
		public NoticeSeverity $severity,
		public string $html,
		public bool $invalidates,
		public bool $declines,
		public bool $pinned,
	) {
	}
}
