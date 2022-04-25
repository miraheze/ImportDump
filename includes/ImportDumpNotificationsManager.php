<?php

namespace Miraheze\ImportDump;

use EchoEvent;

class ImportDumpNotificationsManager {

	/**
	 * @param array $data
	 * @param array $receivers
	 */
	public function sendNotification( array $data, array $receivers ) {
		foreach ( $receivers as $receiver ) {
			if ( !$receiver ) {
				continue;
			}

			EchoEvent::create( [
				'type' => $data['type'],
				'extra' => $data['extra'] + [ 'notifyAgent' => true ],
				'agent' => $receiver,
			] );
		}
	}
}
