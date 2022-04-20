<?php

namespace Miraheze\ImportDump;

use DatabaseUpdater;
use MediaWiki\Installer\Hook\LoadExtensionSchemaUpdatesHook;

class Hooks implements LoadExtensionSchemaUpdatesHook {

	/**
	 * @param DatabaseUpdater $updater
	 */
	public function onLoadExtensionSchemaUpdates( $updater ) {
		$dir = __DIR__ . '/../sql';

		$updater->addExtensionTable(
			'importdump_request_comments',
			"$dir/importdump_request_comments.sql"
		);

		$updater->addExtensionTable(
			'importdump_requests',
			"$dir/importdump_requests.sql"
		);
	}
}
