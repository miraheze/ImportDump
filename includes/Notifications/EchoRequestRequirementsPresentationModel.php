<?php

namespace Miraheze\ImportDump\Notifications;

use MediaWiki\Extension\Notifications\Formatters\EchoEventPresentationModel;
use MediaWiki\Message\Message;

class EchoRequestRequirementsPresentationModel extends EchoEventPresentationModel {

	/** @inheritDoc */
	public function getIconType(): string {
		return 'global';
	}

	/** @inheritDoc */
	public function getHeaderMessage(): Message {
		return $this->msg(
			'importdump-notification-header-requirements',
			$this->event->getExtraParam( 'request-id' )
		);
	}

	/** @inheritDoc */
	public function getBodyMessage(): Message {
		return $this->msg( 'importdump-notification-body-requirements' );
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
