<?php

namespace Miraheze\ImportDump\Tests\Unit;

use Generator;
use MediaWiki\Tests\HookContainer\HookRunnerTestBase;
use Miraheze\ImportDump\Hooks\ImportDumpHookRunner;

/**
 * @covers \Miraheze\ImportDump\Hooks\ImportDumpHookRunner
 */
class ImportDumpHookRunnerTest extends HookRunnerTestBase {

	/**
	 * @inheritDoc
	 */
	public static function provideHookRunners(): Generator {
		yield ImportDumpHookRunner::class => [ ImportDumpHookRunner::class ];
	}
}
