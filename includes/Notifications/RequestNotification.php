<?php

declare( strict_types = 1 );

namespace Miraheze\ImportDump\Notifications;

use MediaWiki\Notification\AgentAware;
use MediaWiki\Notification\Notification;
use MediaWiki\User\UserIdentity;

/**
 * A notification about an import request, caused by a user and with no related page.
 * MediaWiki has AgentNotification for this from 1.47, which can replace this class once
 * 1.46 is no longer supported.
 */
class RequestNotification extends Notification implements AgentAware {

	public function __construct(
		string $type,
		private readonly UserIdentity $agent,
		array $custom,
	) {
		parent::__construct( $type, $custom );
	}

	/** @inheritDoc */
	public function getAgent(): UserIdentity {
		return $this->agent;
	}
}
