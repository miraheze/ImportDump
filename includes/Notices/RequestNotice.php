<?php

namespace Miraheze\ImportDump\Notices;

final readonly class RequestNotice {

	public const string SEVERITY_ERROR = 'error';

	public const string SEVERITY_WARNING = 'warning';

	public const string SEVERITY_NOTICE = 'notice';

	public const string SEVERITY_SUCCESS = 'success';

	/** From the most to the least important. */
	public const array SEVERITIES = [
		self::SEVERITY_ERROR,
		self::SEVERITY_WARNING,
		self::SEVERITY_NOTICE,
		self::SEVERITY_SUCCESS,
	];

	/**
	 * @param string $severity One of the SEVERITY_* constants.
	 * @param string $html The message, already escaped or parsed.
	 * @param bool $invalidates Whether the request cannot be started or have its status changed.
	 * @param bool $declines Whether a pending or running request is shown as declined.
	 * @param bool $pinned Whether the notice stays above the others, whatever their severity.
	 */
	public function __construct(
		public string $severity,
		public string $html,
		public bool $invalidates,
		public bool $declines,
		public bool $pinned,
	) {
	}
}
