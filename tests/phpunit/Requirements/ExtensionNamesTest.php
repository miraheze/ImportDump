<?php

declare( strict_types = 1 );

namespace Miraheze\ImportDump\Tests\Requirements;

use MediaWiki\Config\ServiceOptions;
use MediaWiki\Context\RequestContext;
use MediaWiki\MainConfigNames;
use MediaWikiIntegrationTestCase;
use Miraheze\ImportDump\Requirements\ExtensionNames;
use Wikimedia\ObjectCache\HashBagOStuff;
use function file_put_contents;
use function json_encode;
use function mkdir;

/**
 * @covers \Miraheze\ImportDump\Requirements\ExtensionNames
 * @group ImportDump
 * @group medium
 */
class ExtensionNamesTest extends MediaWikiIntegrationTestCase {

	private function getExtensionNames(): ExtensionNames {
		$directory = $this->getNewTempDirectory();
		$this->addExtension( $directory, 'Named', [ 'namemsg' => 'mainpage' ] );
		$this->addExtension( $directory, 'MissingMessage', [ 'namemsg' => 'importdump-no-such-message' ] );
		$this->addExtension( $directory, 'Unnamed', [] );

		return new ExtensionNames(
			new HashBagOStuff(),
			new ServiceOptions( ExtensionNames::CONSTRUCTOR_OPTIONS, [
				MainConfigNames::ExtensionDirectory => $directory,
				MainConfigNames::StyleDirectory => $this->getNewTempDirectory(),
			] )
		);
	}

	private function addExtension( string $directory, string $name, array $info ): void {
		mkdir( "$directory/$name" );
		file_put_contents( "$directory/$name/extension.json", json_encode( [
			'name' => $name,
			'manifest_version' => 2,
		] + $info ) );
	}

	public function testUsesTheNameMessage(): void {
		$context = RequestContext::getMain();

		$this->assertSame(
			$context->msg( 'mainpage' )->text(),
			$this->getExtensionNames()->getName( $context, 'Named' )
		);
	}

	/** @dataProvider provideFallbacks */
	public function testFallsBackToTheName( string $name ): void {
		$this->assertSame(
			$name,
			$this->getExtensionNames()->getName( RequestContext::getMain(), $name )
		);
	}

	public static function provideFallbacks(): array {
		return [
			'message does not exist' => [ 'MissingMessage' ],
			'no name message' => [ 'Unnamed' ],
			'not an extension that is known' => [ 'NotThere' ],
		];
	}
}
