<?php

namespace Miraheze\ImportDump\Notifications;

use EchoDiscussionParser;
use EchoEventPresentationModel;
use RawMessage;

class EchoRequestStatusUpdatePresentationModel extends EchoEventPresentationModel {
	public function getIconType() {
		return 'global';
	}

	public function getHeaderMessage() {
		return $this->msg( 'importdump-notification-header-status-update' );
	}

	public function getBodyMessage() {
		$comment = $this->event->getExtraParam( 'comment' );
		$text = EchoDiscussionParser::getTextSnippet( $comment, $this->language );

		return new RawMessage( '$1', [ $text ] );
	}

	public function getPrimaryLink() {
		return false;
	}

	public function getSecondaryLinks() {
		$visitLink = [
			'url' => $this->event->getExtraParam( 'request-url', 0 ),
			'label' => $this->msg( 'importdump-notification-visit-request' )->text(),
			'prioritized' => true,
		];

		return [ $visitLink ];
	}
}
