<?php

namespace Miraheze\ImportDump\Tests\Unit;

use MediaWiki\Tests\HookContainer\HookRunnerTestBase;
use Miraheze\ImportDump\Hooks\ImportDumpHookRunner;

/**
 * @covers \Miraheze\ImportDump\Hooks\ImportDumpHookRunner
 */
class ImportDumpHookRunnerTest extends HookRunnerTestBase {

	public function provideHookRunners() {
		yield ImportDumpHookRunner::class => [ ImportDumpHookRunner::class ];
	}
}
