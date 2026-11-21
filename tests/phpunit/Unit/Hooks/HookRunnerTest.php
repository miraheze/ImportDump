<?php

declare( strict_types = 1 );

namespace Miraheze\ImportDump\Tests\Unit\Hooks;

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
