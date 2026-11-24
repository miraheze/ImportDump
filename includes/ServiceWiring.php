<?php

declare( strict_types = 1 );

namespace Miraheze\ImportDump;

use MediaWiki\Config\Config;
use MediaWiki\Config\ServiceOptions;
use MediaWiki\Context\RequestContext;
use MediaWiki\MediaWikiServices;
use Miraheze\ImportDump\Hooks\HookRunner;
use Miraheze\ImportDump\Notifications\AttentionNotifier;
use Miraheze\ImportDump\Progress\ImportHeartbeat;
use Miraheze\ImportDump\Progress\ImportOutput;
use Miraheze\ImportDump\Progress\ImportProgress;
use Miraheze\ImportDump\Progress\VerificationProgress;
use Miraheze\ImportDump\Request\RequestManager;
use Miraheze\ImportDump\Requirements\ExtensionNames;
use Miraheze\ImportDump\Requirements\RequirementChecker;
use Miraheze\ImportDump\Requirements\TargetStateProvider;
use Miraheze\ImportDump\Upload\ArchiveReader;
use Miraheze\ImportDump\Upload\DumpProcessor;
use Miraheze\ImportDump\Upload\ExternalBinaries;
use Miraheze\ImportDump\Upload\ImageProcessor;
use Wikimedia\FileBackend\FSFile\TempFSFileFactory;
use function is_dir;
use function wfMkdirParents;

// PHPUnit does not understand coverage for this file.
// It is covered though, see ServiceWiringTest.
// @codeCoverageIgnoreStart

return [
	'ImportDumpAttentionNotifier' => static function ( MediaWikiServices $services ): AttentionNotifier {
		return new AttentionNotifier( $services->getNotificationService() );
	},
	'ImportDumpConfig' => static function ( MediaWikiServices $services ): Config {
		return $services->getConfigFactory()->makeConfig( 'RequestImport' );
	},
	'ImportDumpDumpProcessor' => static function ( MediaWikiServices $services ): DumpProcessor {
		return new DumpProcessor(
			new ArchiveReader(
				$services->get( 'ImportDumpExternalBinaries' ),
				$services->get( 'ImportDumpTempFSFileFactory' )
			),
			new ServiceOptions(
				DumpProcessor::CONSTRUCTOR_OPTIONS,
				$services->get( 'ImportDumpConfig' )
			),
			$services->get( 'ImportDumpTempFSFileFactory' ),
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
			$services->get( 'ImportDumpImportOutput' ),
			$services->get( 'ImportDumpImportProgress' ),
			$services->get( 'ImportDumpRequirementChecker' ),
			$services->get( 'ImportDumpTargetStateProvider' ),
			$services->get( 'ImportDumpVerificationProgress' ),
			$services->has( 'ManageWikiModuleFactory' ) ?
				$services->get( 'ManageWikiModuleFactory' ) : null
		);
	},
	'ImportDumpImageProcessor' => static function ( MediaWikiServices $services ): ImageProcessor {
		return new ImageProcessor(
			new ArchiveReader(
				$services->get( 'ImportDumpExternalBinaries' ),
				$services->get( 'ImportDumpTempFSFileFactory' )
			),
			$services->getMimeAnalyzer(),
			new ServiceOptions(
				ImageProcessor::CONSTRUCTOR_OPTIONS,
				$services->get( 'ImportDumpConfig' ),
				$services->getMainConfig()
			),
			$services->get( 'ImportDumpTempFSFileFactory' ),
			$services->getTitleFactory(),
			$services->getUploadVerification()
		);
	},
	'ImportDumpImportOutput' => static function ( MediaWikiServices $services ): ImportOutput {
		return new ImportOutput( $services->getMainObjectStash() );
	},
	'ImportDumpImportProgress' => static function ( MediaWikiServices $services ): ImportProgress {
		return new ImportProgress( $services->getMainObjectStash() );
	},
	'ImportDumpRequirementChecker' => static function ( MediaWikiServices $services ): RequirementChecker {
		return new RequirementChecker( new ServiceOptions(
			RequirementChecker::CONSTRUCTOR_OPTIONS,
			$services->get( 'ImportDumpConfig' )
		) );
	},
	'ImportDumpTargetStateProvider' => static function ( MediaWikiServices $services ): TargetStateProvider {
		return new TargetStateProvider(
			$services->getActorStoreFactory(),
			$services->get( 'ImportDumpHookRunner' ),
			$services->getUserGroupManagerFactory()
		);
	},
	'ImportDumpTempFSFileFactory' => static function ( MediaWikiServices $services ): TempFSFileFactory {
		$config = $services->get( 'ImportDumpConfig' );
		'@phan-var Config $config';
		$directory = $config->get( ConfigNames::TempDirectory );
		if ( $directory === null || $directory === '' ) {
			return $services->getTempFSFileFactory();
		}

		// A directory that cannot be made is left for the first file that is written there to complain about.
		if ( !is_dir( $directory ) ) {
			wfMkdirParents( $directory, null, 'ImportDumpTempFSFileFactory' );
		}

		return new TempFSFileFactory( $directory );
	},
	'ImportDumpVerificationProgress' => static function ( MediaWikiServices $services ): VerificationProgress {
		return new VerificationProgress( $services->getMainObjectStash() );
	},
];

// @codeCoverageIgnoreEnd
