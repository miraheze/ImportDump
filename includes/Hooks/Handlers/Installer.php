<?php

declare( strict_types = 1 );

namespace Miraheze\ImportDump\Hooks\Handlers;

use MediaWiki\Installer\Hook\LoadExtensionSchemaUpdatesHook;
use Miraheze\ImportDump\Maintenance\MigrateEchoTypes;
use Miraheze\ImportDump\Maintenance\MigrateLogParams;
use Miraheze\ImportDump\Maintenance\MigrateLogTypes;
use Miraheze\ImportDump\Request\RequestManager;

class Installer implements LoadExtensionSchemaUpdatesHook {

	/** @inheritDoc */
	public function onLoadExtensionSchemaUpdates( $updater ) {
		$dbType = $updater->getDB()->getType();
		$dir = __DIR__ . '/../../../sql';

		$updater->addExtensionUpdateOnVirtualDomain( [
			RequestManager::VIRTUAL_DOMAIN,
			'addTable',
			'import_requests',
			"$dir/$dbType/tables-generated.sql",
			true,
		] );

		$updater->addPostDatabaseUpdateMaintenance( MigrateLogParams::class );
		$updater->addPostDatabaseUpdateMaintenance( MigrateLogTypes::class );
		$updater->addPostDatabaseUpdateMaintenance( MigrateEchoTypes::class );
	}
}
