<?php

namespace Miraheze\ImportDump\Notices;

use MediaWiki\Context\IContextSource;
use Miraheze\ImportDump\RequestManager;

interface NoticeProvider {

	public const string AUDIENCE_HANDLERS = 'handlers';

	public const string AUDIENCE_EVERYONE = 'everyone';

	/** @return string One of the AUDIENCE_* constants. */
	public function getAudience(): string;

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
