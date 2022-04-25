<?php

use MediaWiki\Config\ServiceOptions;
use MediaWiki\MediaWikiServices;
use Miraheze\ImportDump\ImportDumpNotificationsManager;
use Miraheze\ImportDump\ImportDumpRequestManager;

return [
	'ImportDump.NotificationsManager' => static function (): ImportDumpNotificationsManager {
		return new ImportDumpNotificationsManager();
	},
	'ImportDumpRequestManager' => static function ( MediaWikiServices $services ): ImportDumpRequestManager {
		return new ImportDumpRequestManager(
			$services->getConfigFactory()->makeConfig( 'ImportDump' ),
			$services->getDBLoadBalancerFactory(),
			$services->getLinkRenderer(),
			RequestContext::getMain(),
			new ServiceOptions(
				ImportDumpRequestManager::CONSTRUCTOR_OPTIONS,
				$services->getConfigFactory()->makeConfig( 'ImportDump' )
			),
			$services->getUserFactory(),
			$services->getUserGroupManagerFactory()
		);
	},
];
