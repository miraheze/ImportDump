<?php

namespace Miraheze\ImportDump;

use MediaWiki\Config\Config;
use MediaWiki\Config\ServiceOptions;
use MediaWiki\Context\RequestContext;
use MediaWiki\MediaWikiServices;
use Miraheze\ImportDump\Hooks\HookRunner;
use Miraheze\ImportDump\Upload\ArchiveReader;
use Miraheze\ImportDump\Upload\DumpProcessor;
use Miraheze\ImportDump\Upload\ExternalBinaries;

// PHPUnit does not understand coverage for this file.
// It is covered though, see ServiceWiringTest.
// @codeCoverageIgnoreStart

return [
	'ImportDumpConfig' => static function ( MediaWikiServices $services ): Config {
		return $services->getConfigFactory()->makeConfig( 'ImportDump' );
	},
	'ImportDumpDumpProcessor' => static function ( MediaWikiServices $services ): DumpProcessor {
		return new DumpProcessor(
			new ArchiveReader(
				$services->get( 'ImportDumpExternalBinaries' ),
				$services->getTempFSFileFactory()
			),
			new ServiceOptions(
				DumpProcessor::CONSTRUCTOR_OPTIONS,
				$services->get( 'ImportDumpConfig' )
			),
			$services->getTempFSFileFactory(),
			$services->getUploadVerification()
		);
	},
	'ImportDumpExtensionNames' => static function ( MediaWikiServices $services ): ExtensionNames {
		return new ExtensionNames(
			$services->getObjectCacheFactory()->getLocalClusterInstance(),
			new ServiceOptions(
				ExtensionNames::CONSTRUCTOR_OPTIONS,
				$services->getMainConfig()
			)
		);
	},
	'ImportDumpExternalBinaries' => static function ( MediaWikiServices $services ): ExternalBinaries {
		return new ExternalBinaries(
			$services->getShellCommandFactory(),
			new ServiceOptions(
				ExternalBinaries::CONSTRUCTOR_OPTIONS,
				$services->get( 'ImportDumpConfig' )
			)
		);
	},
	'ImportDumpHeartbeat' => static function ( MediaWikiServices $services ): ImportHeartbeat {
		return new ImportHeartbeat( $services->getMainObjectStash() );
	},
	'ImportDumpHookRunner' => static function ( MediaWikiServices $services ): HookRunner {
		return new HookRunner( $services->getHookContainer() );
	},
	'ImportDumpRequestManager' => static function ( MediaWikiServices $services ): RequestManager {
		return new RequestManager(
			$services->getActorStoreFactory(),
			$services->getConnectionProvider(),
			$services->get( 'ImportDumpHookRunner' ),
			$services->getInterwikiLookup(),
			$services->getJobQueueGroupFactory(),
			$services->getNotificationService(),
			$services->getRepoGroup(),
			RequestContext::getMain(),
			new ServiceOptions(
				RequestManager::CONSTRUCTOR_OPTIONS,
				$services->get( 'ImportDumpConfig' )
			),
			$services->getUserFactory(),
			$services->getUserGroupManagerFactory(),
			$services->get( 'ImportDumpImportProgress' ),
			$services->get( 'ImportDumpVerificationProgress' ),
			$services->has( 'ManageWikiModuleFactory' ) ?
				$services->get( 'ManageWikiModuleFactory' ) : null
		);
	},
	'ImportDumpImportProgress' => static function ( MediaWikiServices $services ): ImportProgress {
		return new ImportProgress( $services->getMainObjectStash() );
	},
	'ImportDumpVerificationProgress' => static function ( MediaWikiServices $services ): VerificationProgress {
		return new VerificationProgress( $services->getMainObjectStash() );
	},
];

// @codeCoverageIgnoreEnd
