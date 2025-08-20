<?php

namespace Miraheze\ImportDump\Notifications;

use MediaWiki\Extension\Notifications\DiscussionParser;
use MediaWiki\Extension\Notifications\Formatters\EchoEventPresentationModel;
use MediaWiki\Language\RawMessage;
use MediaWiki\Message\Message;

class EchoRequestCommentPresentationModel extends EchoEventPresentationModel {

	/** @inheritDoc */
	public function getIconType(): string {
		return 'chat';
	}

	/** @inheritDoc */
	public function getHeaderMessage(): Message {
		return $this->msg(
			'importdump-notification-header-comment',
			$this->event->getExtraParam( 'request-id' )
		);
	}

	/** @inheritDoc */
	public function getBodyMessage(): Message {
		$comment = $this->event->getExtraParam( 'comment' );
		$text = DiscussionParser::getTextSnippet( $comment, $this->language );

		return new RawMessage( '$1', [ $text ] );
	}

	/** @inheritDoc */
	public function getPrimaryLink(): false {
		return false;
	}

	/** @inheritDoc */
	public function getSecondaryLinks(): array {
		$visitLink = [
			'url' => $this->event->getExtraParam( 'request-url', 0 ),
			'label' => $this->msg( 'importdump-notification-visit-request' )->text(),
			'prioritized' => true,
		];

		return [ $visitLink ];
	}
}
