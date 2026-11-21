<?php

namespace Miraheze\ImportDump\Notices;

use MediaWiki\Context\IContextSource;
use Miraheze\ImportDump\RequestManager;

/**
 * The results of the action that was just submitted, like a saved change or a refused one.
 * They are not part of the state of the request, so they are added as the action runs.
 */
final class ActionOutcomeNotices implements NoticeProvider {

	/** @var RequestNotice[] */
	private array $notices = [];

	/**
	 * @param string $severity One of the RequestNotice::SEVERITY_* constants.
	 * @param string $html The message, already escaped or parsed.
	 */
	public function add( string $severity, string $html ): void {
		$this->notices[] = new RequestNotice(
			severity: $severity,
			html: $html,
			invalidates: false,
			declines: false,
			pinned: true,
		);
	}

	/** @inheritDoc */
	public function getAudience(): string {
		return self::AUDIENCE_EVERYONE;
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
	 * @param string $status @phan-unused-param
	 */
	public function getNotices(
		RequestManager $requestManager,
		IContextSource $context,
		string $status
	): array {
		return $this->notices;
	}
}
