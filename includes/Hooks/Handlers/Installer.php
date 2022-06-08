<?php

namespace Miraheze\ImportDump\Hooks\Handlers;

use DatabaseUpdater;
use MediaWiki\Installer\Hook\LoadExtensionSchemaUpdatesHook;

class Installer implements LoadExtensionSchemaUpdatesHook {

	/**
	 * @param DatabaseUpdater $updater
	 */
	public function onLoadExtensionSchemaUpdates( $updater ) {
		$dir = __DIR__ . '/../../../sql';

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
