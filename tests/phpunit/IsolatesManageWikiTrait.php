<?php

namespace Miraheze\ImportDump\Tests;

use Miraheze\ManageWiki\Helpers\Factories\ModuleFactory;

/**
 * Stops ManageWiki from answering for the made up target wikis that the tests use,
 * so that the tests give the same results whether or not it is installed.
 */
trait IsolatesManageWikiTrait {

	private function isolateFromManageWiki(): void {
		if ( !$this->getServiceContainer()->has( 'ManageWikiModuleFactory' ) ) {
			return;
		}

		$moduleFactory = $this->createMock( ModuleFactory::class );
		$moduleFactory->method( 'isEnabled' )->willReturn( false );

		$this->setService( 'ManageWikiModuleFactory', $moduleFactory );
	}
}
