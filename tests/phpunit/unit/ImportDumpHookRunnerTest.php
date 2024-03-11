<?php

namespace Miraheze\RequestImport\Tests\Unit;

use Generator;
use MediaWiki\Tests\HookContainer\HookRunnerTestBase;
use Miraheze\RequestImport\Hooks\ImportDumpHookRunner;

/**
 * @covers \Miraheze\RequestImport\Hooks\ImportDumpHookRunner
 */
class ImportDumpHookRunnerTest extends HookRunnerTestBase {

	/**
	 * @inheritDoc
	 */
	public static function provideHookRunners(): Generator {
		yield ImportDumpHookRunner::class => [ ImportDumpHookRunner::class ];
	}
}
