<?php

declare( strict_types = 1 );

namespace Miraheze\ImportDump\Tests;

use Generator;
use MediaWikiIntegrationTestCase;
use Miraheze\ImportDump\ConfigNames;
use Wikimedia\FileBackend\FSFile\TempFSFileFactory;

/**
 * @coversNothing
 * @group ImportDump
 * @group Database
 * @group medium
 */
class ServiceWiringTest extends MediaWikiIntegrationTestCase {

	/** @dataProvider provideService */
	public function testService( string $name ): void {
		$this->getServiceContainer()->get( $name );
		$this->addToAssertionCount( 1 );
	}

	public function testTempFilesAreMadeInTheConfiguredDirectory(): void {
		$directory = $this->getNewTempDirectory() . '/made/on/demand';
		$this->overrideConfigValue( ConfigNames::TempDirectory, $directory );

		$factory = $this->getServiceContainer()->get( 'RequestImport.TempFSFileFactory' );
		'@phan-var TempFSFileFactory $factory';

		$file = $factory->newTempFSFile( 'importdump-test', 'tmp' );
		$this->assertNotNull( $file );
		$this->assertDirectoryExists( $directory );
		$this->assertStringStartsWith( $directory, $file->getPath() );
	}

	public static function provideService(): Generator {
		$wiring = require __DIR__ . '/../../includes/ServiceWiring.php';
		foreach ( $wiring as $name => $_ ) {
			yield $name => [ $name ];
		}
	}
}
