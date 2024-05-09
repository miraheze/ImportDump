<?php

namespace Miraheze\ImportDump\Notifications;

use MediaWiki\Extension\Notifications\DiscussionParser;
use MediaWiki\Extension\Notifications\Formatters\EchoEventPresentationModel;
use MediaWiki\Language\RawMessage;
use Message;

class EchoRequestCommentPresentationModel extends EchoEventPresentationModel {

	/**
	 * @return string
	 */
	public function getIconType() {
		return 'chat';
	}

	/**
	 * @return Message
	 */
	public function getHeaderMessage() {
		return $this->msg(
			'importdump-notification-header-comment',
			$this->event->getExtraParam( 'request-id' )
		);
	}

	/**
	 * @return Message
	 */
	public function getBodyMessage() {
		$comment = $this->event->getExtraParam( 'comment' );
		$text = DiscussionParser::getTextSnippet( $comment, $this->language );

		return new RawMessage( '$1', [ $text ] );
	}

	/**
	 * @return bool
	 */
	public function getPrimaryLink() {
		return false;
	}

	/**
	 * @return array
	 */
	public function getSecondaryLinks() {
		$visitLink = [
			'url' => $this->event->getExtraParam( 'request-url', 0 ),
			'label' => $this->msg( 'importdump-notification-visit-request' )->text(),
			'prioritized' => true,
		];

		return [ $visitLink ];
	}
}
