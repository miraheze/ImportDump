<?php

use MediaWiki\Config\ServiceOptions;
use MediaWiki\Context\RequestContext;
use MediaWiki\MediaWikiServices;
use Miraheze\ImportDump\Hooks\ImportDumpHookRunner;
use Miraheze\ImportDump\ImportDumpRequestManager;

return [
	'ImportDumpRequestManager' => static function ( MediaWikiServices $services ): ImportDumpRequestManager {
		return new ImportDumpRequestManager(
			$services->getConfigFactory()->makeConfig( 'ImportDump' ),
			$services->getActorStoreFactory(),
			$services->getConnectionProvider(),
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
			$services->has( 'RemoteWikiFactory' ) ?
				$services->get( 'RemoteWikiFactory' ) : null
		);
	},
	'ImportDumpHookRunner' => static function ( MediaWikiServices $services ): ImportDumpHookRunner {
		return new ImportDumpHookRunner( $services->getHookContainer() );
	},
];
