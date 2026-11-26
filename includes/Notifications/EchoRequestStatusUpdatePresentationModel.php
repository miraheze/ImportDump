<?php

declare( strict_types = 1 );

namespace Miraheze\ImportDump\Notifications;

use MediaWiki\Extension\Notifications\Formatters\EchoEventPresentationModel;
use MediaWiki\Language\RawMessage;
use MediaWiki\Message\Message;
use function htmlspecialchars;
use function nl2br;

class EchoRequestStatusUpdatePresentationModel extends EchoEventPresentationModel {

	/** @inheritDoc */
	public function getIconType(): string {
		return 'global';
	}

	/** @inheritDoc */
	public function getHeaderMessage(): Message {
		return $this->msg(
			'requestimport-notification-header-status-update',
			$this->event->getExtraParam( 'request-id' )
		);
	}

	/** @inheritDoc */
	public function getBodyMessage(): Message {
		$comment = $this->event->getExtraParam( 'comment' );

		return new RawMessage( '$1', [ nl2br( htmlspecialchars( $comment ) ) ] );
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
