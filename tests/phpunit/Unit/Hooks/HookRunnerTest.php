<?php

declare( strict_types = 1 );

namespace Miraheze\ImportDump\Tests\Unit\Hooks;

use BackedEnum;
use Generator;
use MediaWiki\Tests\HookContainer\HookRunnerTestBase;
use Miraheze\ImportDump\Hooks\HookRunner;
use ReflectionNamedType;
use ReflectionParameter;
use function is_subclass_of;

/**
 * @covers \Miraheze\ImportDump\Hooks\HookRunner
 */
class HookRunnerTest extends HookRunnerTestBase {

	/** @inheritDoc */
	public static function provideHookRunners(): Generator {
		yield HookRunner::class => [ HookRunner::class ];
	}

	/**
	 * Enums are final, so they cannot be mocked. Use the first case instead.
	 * @inheritDoc
	 */
	protected function getMockedParamValue( ReflectionParameter $param ) {
		$type = $param->getType();
		if ( $type instanceof ReflectionNamedType && is_subclass_of( $type->getName(), BackedEnum::class ) ) {
			return $type->getName()::cases()[0];
		}

		return parent::getMockedParamValue( $param );
	}
}
