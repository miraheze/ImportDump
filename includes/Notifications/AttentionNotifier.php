<?php

declare( strict_types = 1 );

namespace Miraheze\ImportDump\Notifications;

use MediaWiki\Notification\NotificationService;
use MediaWiki\Notification\RecipientSet;
use MediaWiki\SpecialPage\SpecialPage;
use MediaWiki\User\User;
use Miraheze\ImportDump\Request\RequestHistory;
use Miraheze\ImportDump\Request\RequestManager;

class AttentionNotifier {

	public function __construct(
		private readonly NotificationService $notificationService,
	) {
	}

	/**
	 * Tells the requester that the request needs attention, which is when the dump seems to be
	 * missing revisions, or when the import cannot start until something is enabled on the target
	 * wiki. That the target lacks something is a fact about the target, so it only counts for a
	 * requester who can read it, like the notices on the request. The notification does not say
	 * what the matter is. That is left to the notices, which are up to date when the request is
	 * opened.
	 */
	public function notify( RequestManager $requestManager ): void {
		$needsAttention = $requestManager->getHistory() === RequestHistory::Partial || (
			$requestManager->requesterHasTargetAccess() && $requestManager->getBlockingRequirementProblems()
		);

		if ( !$needsAttention ) {
			return;
		}

		$requestID = $requestManager->getID();
		$this->notificationService->notify(
			new RequestNotification(
				'requestimport-attention',
				User::newSystemUser( RequestManager::STATUS_UPDATE_USER ),
				[
					'request-id' => $requestID,
					'request-url' => SpecialPage::getTitleFor(
						'RequestImportQueue', (string)$requestID
					)->getFullURL(),
				]
			),
			new RecipientSet( [ $requestManager->getRequester() ] )
		);
	}
}
