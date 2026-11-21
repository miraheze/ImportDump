<?php

declare( strict_types = 1 );

namespace Miraheze\ImportDump\Tests\Maintenance;

use MediaWiki\Logging\ManualLogEntry;
use MediaWiki\MainConfigNames;
use MediaWiki\Tests\Maintenance\MaintenanceBaseTestCase;
use MediaWiki\Title\TitleValue;
use MediaWiki\WikiMap\WikiMap;
use Miraheze\ImportDump\Maintenance\MigrateLogTypes;
use const NS_SPECIAL;

/**
 * @group ImportDump
 * @group Database
 * @group medium
 * @covers \Miraheze\ImportDump\Maintenance\MigrateLogTypes
 */
class MigrateLogTypesTest extends MaintenanceBaseTestCase {

	/** @inheritDoc */
	protected function getMaintenanceClass(): string {
		return MigrateLogTypes::class;
	}

	protected function setUp(): void {
		parent::setUp();

		$this->overrideConfigValue( MainConfigNames::VirtualDomainsMapping, [
			'virtual-requestimport' => [ 'db' => WikiMap::getCurrentWikiId() ],
		] );
	}

	private function newEntry( string $type ): ManualLogEntry {
		$entry = new ManualLogEntry( $type, 'request' );
		$entry->setPerformer( $this->getTestUser()->getUser() );
		$entry->setTarget( new TitleValue( NS_SPECIAL, 'RequestImportQueue/1' ) );
		$entry->setParameters( [ '4::requestTarget' => 'testwiki' ] );

		return $entry;
	}

	private function addLog( string $type ): int {
		return $this->newEntry( $type )->insert( $this->getDb() );
	}

	private function getLogType( int $logID ): string {
		return (string)$this->getDb()->newSelectQueryBuilder()
			->select( 'log_type' )
			->from( 'logging' )
			->where( [ 'log_id' => $logID ] )
			->caller( __METHOD__ )
			->fetchField();
	}

	public function testMovesBothLogTypes(): void {
		$public = $this->addLog( 'importdump' );
		$private = $this->addLog( 'importdumpprivate' );

		$this->expectOutputRegex( '/Moved 2 log entries/' );
		$this->maintenance->execute();

		$this->assertSame( 'requestimport', $this->getLogType( $public ) );
		$this->assertSame( 'requestimportprivate', $this->getLogType( $private ) );
	}

	public function testLeavesOtherLogTypesAlone(): void {
		$other = $this->addLog( 'otherlog' );
		$moved = $this->addLog( 'requestimport' );

		$this->expectOutputRegex( '/Moved 0 log entries/' );
		$this->maintenance->execute();

		$this->assertSame( 'otherlog', $this->getLogType( $other ) );
		$this->assertSame( 'requestimport', $this->getLogType( $moved ) );
	}

	public function testMovesRecentChangesOnlyForTheOldTypes(): void {
		$entry = $this->newEntry( 'importdump' );
		$logID = $entry->insert( $this->getDb() );
		$entry->publish( $logID );

		$otherEntry = $this->newEntry( 'otherlog' );
		$otherID = $otherEntry->insert( $this->getDb() );
		$otherEntry->publish( $otherID );

		$this->expectOutputRegex( '/Moved 1 log entries/' );
		$this->maintenance->execute();

		$this->assertSame( 'requestimport', $this->getRecentChangeType( $logID ) );
		$this->assertSame( 'otherlog', $this->getRecentChangeType( $otherID ) );
	}

	private function getRecentChangeType( int $logID ): string {
		return (string)$this->getDb()->newSelectQueryBuilder()
			->select( 'rc_log_type' )
			->from( 'recentchanges' )
			->where( [ 'rc_logid' => $logID ] )
			->caller( __METHOD__ )
			->fetchField();
	}
}
