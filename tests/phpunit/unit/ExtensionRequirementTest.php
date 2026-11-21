<?php

namespace Miraheze\ImportDump\Tests\Unit;

use MediaWikiUnitTestCase;
use Miraheze\ImportDump\ExtensionRequirement;

/**
 * @covers \Miraheze\ImportDump\ExtensionRequirement
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
