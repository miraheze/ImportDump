<?php

declare( strict_types = 1 );

namespace Miraheze\ImportDump\Notifications;

use MediaWiki\Extension\Notifications\DiscussionParser;
use MediaWiki\Extension\Notifications\Formatters\EchoEventPresentationModel;
use MediaWiki\Message\Message;

class EchoNewRequestPresentationModel extends EchoEventPresentationModel {

	/** @inheritDoc */
	public function getIconType(): string {
		return 'global';
	}

	/** @inheritDoc */
	public function getHeaderMessage(): Message {
		return $this->msg(
			'requestimport-notification-header-new-request',
			$this->event->getExtraParam( 'request-id' )
		);
	}

	/** @inheritDoc */
	public function getBodyMessage(): Message {
		$reason = DiscussionParser::getTextSnippet(
			$this->event->getExtraParam( 'reason' ),
			$this->language
		);

		return $this->msg( 'requestimport-notification-body-new-request',
			$reason,
			$this->event->getExtraParam( 'requester' ),
			$this->event->getExtraParam( 'target' )
		);
	}

	/** @inheritDoc */
	public function getPrimaryLink(): false {
		return false;
	}

	/** @inheritDoc */
	public function getSecondaryLinks(): array {
		$visitLink = [
			'url' => $this->event->getExtraParam( 'request-url', 0 ),
			'label' => $this->msg( 'requestimport-notification-visit-request' )->text(),
			'prioritized' => true,
		];

		return [ $visitLink ];
	}
}
