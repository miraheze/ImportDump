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
	'RequestImport.AttentionNotifier' => static function ( MediaWikiServices $services ): AttentionNotifier {
		return new AttentionNotifier( $services->getNotificationService() );
	},
	'RequestImport.Config' => static function ( MediaWikiServices $services ): Config {
		return $services->getConfigFactory()->makeConfig( 'RequestImport' );
	},
	'RequestImport.DumpProcessor' => static function ( MediaWikiServices $services ): DumpProcessor {
		return new DumpProcessor(
			new ArchiveReader(
				$services->get( 'RequestImport.ExternalBinaries' ),
				$services->get( 'RequestImport.TempFSFileFactory' )
			),
			new ServiceOptions(
				DumpProcessor::CONSTRUCTOR_OPTIONS,
				$services->get( 'RequestImport.Config' )
			),
			$services->get( 'RequestImport.TempFSFileFactory' ),
			$services->getUploadVerification()
		);
	},
	'RequestImport.ExtensionNames' => static function ( MediaWikiServices $services ): ExtensionNames {
		return new ExtensionNames(
			$services->getObjectCacheFactory()->getLocalClusterInstance(),
			new ServiceOptions(
				ExtensionNames::CONSTRUCTOR_OPTIONS,
				$services->getMainConfig()
			)
		);
	},
	'RequestImport.ExternalBinaries' => static function ( MediaWikiServices $services ): ExternalBinaries {
		return new ExternalBinaries(
			$services->getShellCommandFactory(),
			new ServiceOptions(
				ExternalBinaries::CONSTRUCTOR_OPTIONS,
				$services->get( 'RequestImport.Config' )
			)
		);
	},
	'RequestImport.Heartbeat' => static function ( MediaWikiServices $services ): ImportHeartbeat {
		return new ImportHeartbeat( $services->getMainObjectStash() );
	},
	'RequestImport.HookRunner' => static function ( MediaWikiServices $services ): HookRunner {
		return new HookRunner( $services->getHookContainer() );
	},
	'RequestImport.RequestManager' => static function ( MediaWikiServices $services ): RequestManager {
		return new RequestManager(
			$services->getConnectionProvider(),
			$services->get( 'RequestImport.HookRunner' ),
			$services->getInterwikiLookup(),
			$services->getJobQueueGroupFactory(),
			$services->getNotificationService(),
			$services->getRepoGroup(),
			RequestContext::getMain(),
			new ServiceOptions(
				RequestManager::CONSTRUCTOR_OPTIONS,
				$services->get( 'RequestImport.Config' )
			),
			$services->getUserFactory(),
			$services->get( 'RequestImport.ImportOutput' ),
			$services->get( 'RequestImport.ImportProgress' ),
			$services->get( 'RequestImport.RequirementChecker' ),
			$services->get( 'RequestImport.TargetStateProvider' ),
			$services->get( 'RequestImport.VerificationProgress' ),
			$services->has( 'ManageWikiModuleFactory' ) ?
				$services->get( 'ManageWikiModuleFactory' ) : null
		);
	},
	'RequestImport.ImageProcessor' => static function ( MediaWikiServices $services ): ImageProcessor {
		return new ImageProcessor(
			new ArchiveReader(
				$services->get( 'RequestImport.ExternalBinaries' ),
				$services->get( 'RequestImport.TempFSFileFactory' )
			),
			$services->getMimeAnalyzer(),
			new ServiceOptions(
				ImageProcessor::CONSTRUCTOR_OPTIONS,
				$services->get( 'RequestImport.Config' ),
				$services->getMainConfig()
			),
			$services->get( 'RequestImport.TempFSFileFactory' ),
			$services->getTitleFactory(),
			$services->getUploadVerification()
		);
	},
	'RequestImport.ImportOutput' => static function ( MediaWikiServices $services ): ImportOutput {
		return new ImportOutput( $services->getMainObjectStash() );
	},
	'RequestImport.ImportProgress' => static function ( MediaWikiServices $services ): ImportProgress {
		return new ImportProgress( $services->getMainObjectStash() );
	},
	'RequestImport.RequirementChecker' => static function ( MediaWikiServices $services ): RequirementChecker {
		return new RequirementChecker( new ServiceOptions(
			RequirementChecker::CONSTRUCTOR_OPTIONS,
			$services->get( 'RequestImport.Config' )
		) );
	},
	'RequestImport.TargetStateProvider' => static function ( MediaWikiServices $services ): TargetStateProvider {
		return new TargetStateProvider(
			$services->getActorStoreFactory(),
			$services->get( 'RequestImport.HookRunner' ),
			$services->getUserGroupManagerFactory()
		);
	},
	'RequestImport.TempFSFileFactory' => static function ( MediaWikiServices $services ): TempFSFileFactory {
		$config = $services->get( 'RequestImport.Config' );
		'@phan-var Config $config';
		$directory = $config->get( ConfigNames::TempDirectory );
		if ( $directory === null || $directory === '' ) {
			return $services->getTempFSFileFactory();
		}

		// A directory that cannot be made is left for the first file that is written there to complain about.
		if ( !is_dir( $directory ) ) {
			wfMkdirParents( $directory, null, 'RequestImport.TempFSFileFactory' );
		}

		return new TempFSFileFactory( $directory );
	},
	'RequestImport.VerificationProgress' => static function ( MediaWikiServices $services ): VerificationProgress {
		return new VerificationProgress( $services->getMainObjectStash() );
	},
];

// @codeCoverageIgnoreEnd
