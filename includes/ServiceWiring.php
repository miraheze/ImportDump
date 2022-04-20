<?php

use MediaWiki\Config\ServiceOptions;
use MediaWiki\MediaWikiServices;
use Miraheze\ImportDump\ImportDumpRequestManager;

return [
	'ImportDumpRequestManager' => static function ( MediaWikiServices $services ): ImportDumpRequestManager {
		return new ImportDumpRequestManager(
			$services->getDBLoadBalancerFactory(),
			RequestContext::getMain(),
			new ServiceOptions(
				ImportDumpRequest::CONSTRUCTOR_OPTIONS,
				$services->getConfigFactory()->makeConfig( 'ImportDump' )
			),
			$services->getUserFactory()
		);
	},
];
