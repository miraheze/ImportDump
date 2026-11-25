<?php

declare( strict_types = 1 );

namespace Miraheze\ImportDump\Tests\Progress;

use MediaWikiIntegrationTestCase;
use Miraheze\ImportDump\Progress\RefreshLinksWithProgress;
use Wikimedia\TestingAccessWrapper;

/**
 * @group ImportDump
 * @covers \Miraheze\ImportDump\Progress\RefreshLinksWithProgress
 */
class RefreshLinksWithProgressTest extends MediaWikiIntegrationTestCase {

	public function testOnlyTheCountOfPagesIsReported(): void {
		$this->expectOutputString( "Estimated page count: 5000\n100\n200\nDone\n" );

		$counts = [];
		$script = new RefreshLinksWithProgress();
		$script->setPagesCallback( static function ( int $pages ) use ( &$counts ): void {
			$counts[] = $pages;
		} );

		$wrapper = TestingAccessWrapper::newFromObject( $script );
		$wrapper->output( "Estimated page count: 5000\n" );
		$wrapper->output( "100\n" );
		$wrapper->output( "200\n" );
		$wrapper->output( "Done\n" );

		$this->assertSame( [ 100, 200 ], $counts );
	}

	public function testOutputWorksWithoutACallback(): void {
		$this->expectOutputString( "100\n" );

		$wrapper = TestingAccessWrapper::newFromObject( new RefreshLinksWithProgress() );
		$wrapper->output( "100\n" );
	}
}
