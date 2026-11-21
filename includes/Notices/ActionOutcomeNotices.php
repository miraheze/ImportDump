<?php

declare( strict_types = 1 );

namespace Miraheze\ImportDump\Notices;

use MediaWiki\Context\IContextSource;
use Miraheze\ImportDump\Request\RequestManager;
use Miraheze\ImportDump\Request\RequestStatus;

/**
 * The results of the action that was just submitted, like a saved change or a refused one.
 * They are not part of the state of the request, so they are added as the action runs.
 */
final class ActionOutcomeNotices implements NoticeProvider {

	/** @var RequestNotice[] */
	private array $notices = [];

	/**
	 * @param NoticeSeverity $severity How serious the outcome is.
	 * @param string $html The message, already escaped or parsed.
	 */
	public function add( NoticeSeverity $severity, string $html ): void {
		$this->notices[] = new RequestNotice(
			severity: $severity,
			html: $html,
			invalidates: false,
			declines: false,
			pinned: true,
		);
	}

	/** @inheritDoc */
	public function getAudience(): NoticeAudience {
		return NoticeAudience::Everyone;
	}

	/** @inheritDoc */
	public function isAlwaysAtTop(): true {
		return true;
	}

	/** @inheritDoc */
	public function requiresTargetAccess(): false {
		return false;
	}

	/**
	 * @inheritDoc
	 * @param RequestManager $requestManager @phan-unused-param
	 * @param IContextSource $context @phan-unused-param
	 * @param RequestStatus $status @phan-unused-param
	 */
	public function getNotices(
		RequestManager $requestManager,
		IContextSource $context,
		RequestStatus $status
	): array {
		return $this->notices;
	}
}
