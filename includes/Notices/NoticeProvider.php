<?php

namespace Miraheze\ImportDump\Notices;

use MediaWiki\Context\IContextSource;
use Miraheze\ImportDump\RequestManager;

interface NoticeProvider {

	public const string AUDIENCE_HANDLERS = 'handlers';

	/** The handlers and the requester of the request. */
	public const string AUDIENCE_PARTIES = 'parties';

	/** @return string One of the AUDIENCE_* constants. */
	public function getAudience(): string;

	/** Whether the notices come from the target wiki, so the requester needs access to it. */
	public function requiresTargetAccess(): bool;

	/**
	 * @param RequestManager $requestManager
	 * @param IContextSource $context
	 * @param string $status The status of the request, as earlier notices may have changed it.
	 * @return RequestNotice[]
	 */
	public function getNotices(
		RequestManager $requestManager,
		IContextSource $context,
		string $status
	): array;
}
