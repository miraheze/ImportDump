<?php

namespace Miraheze\ImportDump;

use MediaWiki\Config\ServiceOptions;
use MediaWiki\Context\RequestContext;
use MediaWiki\MediaWikiServices;
use Miraheze\ImportDump\Hooks\ImportDumpHookRunner;

return [
	'ImportDumpRequestManager' => static function ( MediaWikiServices $services ): ImportDumpRequestManager {
		return new ImportDumpRequestManager(
			$services->getActorStoreFactory(),
			$services->getConnectionProvider(),
			$services->getExtensionRegistry(),
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
			$services->has( 'ManageWikiModuleFactory' ) ?
				$services->get( 'ManageWikiModuleFactory' ) : null
		);
	},
	'ImportDumpHookRunner' => static function ( MediaWikiServices $services ): ImportDumpHookRunner {
		return new ImportDumpHookRunner( $services->getHookContainer() );
	},
];
