<?php

use MediaWiki\Config\ServiceOptions;
use MediaWiki\MediaWikiServices;
use Miraheze\ImportDump\Hooks\ImportDumpHookRunner;
use Miraheze\ImportDump\ImportDumpRequestManager;

return [
	'ImportDumpHookRunner' => static function ( MediaWikiServices $services ): ImportDumpHookRunner {
		return new ImportDumpHookRunner( $services->getHookContainer() );
	},
	'ImportDumpRequestManager' => static function ( MediaWikiServices $services ): ImportDumpRequestManager {
		return new ImportDumpRequestManager(
			$services->getConfigFactory()->makeConfig( 'ImportDump' ),
			$services->getDBLoadBalancerFactory(),
			$services->getInterwikiLookup(),
			$services->getLinkRenderer(),
			$services->getRepoGroup(),
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
