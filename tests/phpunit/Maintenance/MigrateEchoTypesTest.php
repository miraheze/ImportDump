<?php

declare( strict_types = 1 );

namespace Miraheze\ImportDump\Tests\Maintenance;

use MediaWiki\MainConfigNames;
use MediaWiki\Tests\Maintenance\MaintenanceBaseTestCase;
use MediaWiki\WikiMap\WikiMap;
use Miraheze\ImportDump\Maintenance\MigrateEchoTypes;

/**
 * @group ImportDump
 * @group Database
 * @group medium
 * @covers \Miraheze\ImportDump\Maintenance\MigrateEchoTypes
 */
class MigrateEchoTypesTest extends MaintenanceBaseTestCase {

	/** @inheritDoc */
	protected function getMaintenanceClass(): string {
		return MigrateEchoTypes::class;
	}

	protected function setUp(): void {
		parent::setUp();

		$this->overrideConfigValue( MainConfigNames::VirtualDomainsMapping, [
			'virtual-requestimport' => [ 'db' => WikiMap::getCurrentWikiId() ],
		] );
	}

	private function addPreference( int $userID, string $property ): void {
		$this->getDb()->newInsertQueryBuilder()
			->insertInto( 'user_properties' )
			->row( [
				'up_user' => $userID,
				'up_property' => $property,
				'up_value' => '1',
			] )
			->caller( __METHOD__ )
			->execute();
	}

	/** @return list<string> */
	private function getPreferences( int $userID ): array {
		return $this->getDb()->newSelectQueryBuilder()
			->select( 'up_property' )
			->from( 'user_properties' )
			->where( [ 'up_user' => $userID ] )
			->orderBy( 'up_property' )
			->caller( __METHOD__ )
			->fetchFieldValues();
	}

	public function testMovesPreferencesForEveryDeliveryMethod(): void {
		$userID = $this->getTestUser()->getUser()->getId();

		$this->addPreference( $userID, 'echo-subscriptions-web-importdump-request-comment' );
		$this->addPreference( $userID, 'echo-subscriptions-email-importdump-import-failed' );

		$this->expectOutputRegex( '/Moved 2 preferences/' );
		$this->maintenance->execute();

		$this->assertSame( [
			'echo-subscriptions-email-requestimport-import-failed',
			'echo-subscriptions-web-requestimport-request-comment',
		], $this->getPreferences( $userID ) );
	}

	public function testLeavesOtherPreferencesAlone(): void {
		$userID = $this->getTestUser()->getUser()->getId();

		$this->addPreference( $userID, 'echo-subscriptions-web-edit-user-talk' );
		$this->addPreference( $userID, 'echo-subscriptions-web-requestimport-new-request' );

		$this->expectOutputRegex( '/Moved 0 preferences/' );
		$this->maintenance->execute();

		$this->assertSame( [
			'echo-subscriptions-web-edit-user-talk',
			'echo-subscriptions-web-requestimport-new-request',
		], $this->getPreferences( $userID ) );
	}
}
