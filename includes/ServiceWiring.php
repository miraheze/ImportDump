<?php

use MediaWiki\Config\ServiceOptions;
use MediaWiki\MediaWikiServices;
use Miraheze\RequestImport\Hooks\ImportDumpHookRunner;
use Miraheze\RequestImport\ImportDumpRequestManager;

return [
	'ImportDumpRequestManager' => static function ( MediaWikiServices $services ): ImportDumpRequestManager {
		return new ImportDumpRequestManager(
			$services->getConfigFactory()->makeConfig( 'ImportDump' ),
			$services->getActorStoreFactory(),
			$services->getDBLoadBalancerFactory(),
			$services->getInterwikiLookup(),
			$services->getJobQueueGroupFactory(),
			$services->getLinkRenderer(),
			$services->getRepoGroup(),
			RequestContext::getMain(),
			new ServiceOptions(
				ImportDumpRequestManager::CONSTRUCTOR_OPTIONS,
				$services->getConfigFactory()->makeConfig( 'ImportDump' )
			),
			$services->getUserFactory(),
			$services->getUserGroupManagerFactory(),
			$services->has( 'CreateWikiHookRunner' ) ?
				$services->get( 'CreateWikiHookRunner' ) : null
		);
	},
	'ImportDumpHookRunner' => static function ( MediaWikiServices $services ): ImportDumpHookRunner {
		return new ImportDumpHookRunner( $services->getHookContainer() );
	},
];
