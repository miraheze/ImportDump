<?php

declare( strict_types = 1 );

namespace Miraheze\ImportDump\Notices;

use MediaWiki\Context\IContextSource;
use Miraheze\ImportDump\Request\RequestManager;
use Miraheze\ImportDump\Request\RequestStatus;

interface NoticeProvider {

	public function getAudience(): NoticeAudience;

	/**
	 * Whether the notices are shown above the form to handlers too, instead of in the handling tab.
	 * Everyone else always sees them above the form.
	 */
	public function isAlwaysAtTop(): bool;

	/** Whether the notices come from the target wiki, so the requester needs access to it. */
	public function requiresTargetAccess(): bool;

	/**
	 * @param RequestManager $requestManager
	 * @param IContextSource $context
	 * @param RequestStatus $status The status of the request, as earlier notices may have changed it.
	 * @return RequestNotice[]
	 */
	public function getNotices(
		RequestManager $requestManager,
		IContextSource $context,
		RequestStatus $status
	): array;
}
