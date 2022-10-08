<?php

use MediaWiki\Config\ServiceOptions;
use MediaWiki\MediaWikiServices;
use Miraheze\ImportDump\ImportDumpRequestManager;

return [
	'ImportDumpRequestManager' => static function ( MediaWikiServices $services ): ImportDumpRequestManager {
		return new ImportDumpRequestManager(
			$services->getConfigFactory()->makeConfig( 'ImportDump' ),
			$services->getDBLoadBalancerFactory(),
			$services->getInterwikiLookup(),
			$services->getLinkRenderer(),
			RequestContext::getMain(),
			$services->getRepoGroup(),
			new ServiceOptions(
				ImportDumpRequestManager::CONSTRUCTOR_OPTIONS,
				$services->getConfigFactory()->makeConfig( 'ImportDump' )
			),
			$services->getUserFactory(),
			$services->getUserGroupManagerFactory()
		);
	},
];
