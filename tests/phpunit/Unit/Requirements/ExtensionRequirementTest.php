<?php

declare( strict_types = 1 );

namespace Miraheze\ImportDump\Tests\Unit\Requirements;

use MediaWikiUnitTestCase;
use Miraheze\ImportDump\Requirements\ExtensionRequirement;
use function array_keys;

/**
 * @covers \Miraheze\ImportDump\Requirements\ExtensionRequirement
 */
class ExtensionRequirementTest extends MediaWikiUnitTestCase {

	public function testFromConfig(): void {
		$requirements = ExtensionRequirement::fromConfig( [
			'Wikibase' => [
				'models' => [ 'wikibase-' ],
				'patterns' => [],
				'settings' => [ 'allowEntityImport' ],
			],
			'PortableInfobox' => [
				'patterns' => [ '/<infobox/i' ],
			],
		] );

		$this->assertSame( [ 'Wikibase', 'PortableInfobox' ], array_keys( $requirements ) );
		$this->assertSame( 'Wikibase', $requirements['Wikibase']->name );
		$this->assertSame( [ 'wikibase-' ], $requirements['Wikibase']->models );
		$this->assertSame( [ 'allowEntityImport' ], $requirements['Wikibase']->settings );
		$this->assertSame( [], $requirements['PortableInfobox']->models );
		$this->assertSame( [ '/<infobox/i' ], $requirements['PortableInfobox']->patterns );
		$this->assertSame( [], $requirements['PortableInfobox']->settings );
	}

	public function testFromEmptyConfig(): void {
		$this->assertSame( [], ExtensionRequirement::fromConfig( [] ) );
	}
}
