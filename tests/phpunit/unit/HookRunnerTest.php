<?php

namespace Miraheze\ImportDump\Tests\Unit;

use Generator;
use MediaWiki\Tests\HookContainer\HookRunnerTestBase;
use Miraheze\ImportDump\Hooks\HookRunner;

/**
 * @covers \Miraheze\ImportDump\Hooks\HookRunner
 */
class HookRunnerTest extends HookRunnerTestBase {

	/** @inheritDoc */
	public static function provideHookRunners(): Generator {
		yield HookRunner::class => [ HookRunner::class ];
	}
}
