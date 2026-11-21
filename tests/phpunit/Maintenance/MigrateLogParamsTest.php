<?php

declare( strict_types = 1 );

namespace Miraheze\ImportDump\Tests\Maintenance;

use MediaWiki\Logging\LogEntryBase;
use MediaWiki\Logging\ManualLogEntry;
use MediaWiki\MainConfigNames;
use MediaWiki\Message\Message;
use MediaWiki\Tests\Maintenance\MaintenanceBaseTestCase;
use MediaWiki\Title\TitleValue;
use MediaWiki\WikiMap\WikiMap;
use Miraheze\ImportDump\Maintenance\MigrateLogParams;
use Wikimedia\TestingAccessWrapper;
use const NS_SPECIAL;

/**
 * @group ImportDump
 * @group Database
 * @group medium
 * @covers \Miraheze\ImportDump\Maintenance\MigrateLogParams
 */
class MigrateLogParamsTest extends MaintenanceBaseTestCase {

	/** @inheritDoc */
	protected function getMaintenanceClass(): string {
		return MigrateLogParams::class;
	}

	protected function setUp(): void {
		parent::setUp();

		$this->overrideConfigValue( MainConfigNames::VirtualDomainsMapping, [
			'virtual-requestimport' => [ 'db' => WikiMap::getCurrentWikiId() ],
		] );
	}

	private function addLog( string $type, string $action, int $requestID, array $params ): int {
		$entry = new ManualLogEntry( $type, $action );
		$entry->setPerformer( $this->getTestUser()->getUser() );
		$entry->setTarget( new TitleValue( NS_SPECIAL, "RequestImportQueue/$requestID" ) );
		$entry->setParameters( $params );

		return $entry->insert( $this->getDb() );
	}

	/**
	 * @return array<string, mixed>
	 */
	private function getParams( int $logID ): array {
		$blob = $this->getDb()->newSelectQueryBuilder()
			->select( 'log_params' )
			->from( 'logging' )
			->where( [ 'log_id' => $logID ] )
			->caller( __METHOD__ )
			->fetchField();

		// @phan-suppress-next-line SecurityCheck-PHPSerializeInjection Classes are not allowed
		$params = LogEntryBase::extractParams( (string)$blob );
		$this->assertIsArray( $params );
		'@phan-var array<string, mixed> $params';

		return $params;
	}

	public function testMigratesRequestStatusAndInterwikiEntries(): void {
		$request = $this->addLog( 'importdump', 'request', 7, [
			'4::requestTarget' => 'testwiki',
			'5::requestLink' => Message::rawParam( '<a href="/wiki/Special:RequestImportQueue/7">#7</a>' ),
		] );

		$started = $this->addLog( 'importdumpprivate', 'started', 8, [
			'4::requestTarget' => 'privatewiki',
			'5::requestLink' => Message::rawParam( '<a href="/x">#8</a>' ),
		] );

		$status = $this->addLog( 'importdump', 'statusupdate', 9, [
			'4::requestLink' => Message::rawParam( '<a href="/x">#9</a>' ),
			'5::requestStatus' => 'partially completed',
		] );

		$interwiki = $this->addLog( 'importdump', 'interwiki', 10, [
			'4::prefix' => 'example',
			'5::target' => 'testwiki',
			'6::requestLink' => Message::rawParam( '<a href="/x">#10</a>' ),
		] );

		$this->expectOutputRegex( '/Migrated 4 log entries\. Skipped 0\. Left 0 unknown statuses/' );
		$this->maintenance->execute();

		$this->assertSame( [
			'4::requestTarget' => 'testwiki',
			'5:request-link:requestID' => 7,
		], $this->getParams( $request ) );

		$this->assertSame( [
			'4::requestTarget' => 'privatewiki',
			'5:request-link:requestID' => 8,
		], $this->getParams( $started ) );

		$this->assertSame( [
			'4:request-link:requestID' => 9,
			'5:request-status:requestStatus' => 'partial',
		], $this->getParams( $status ) );

		$this->assertSame( [
			'4::prefix' => 'example',
			'5::target' => 'testwiki',
			'6:request-link:requestID' => 10,
		], $this->getParams( $interwiki ) );
	}

	public function testLeavesUnknownStatusTextAsItWas(): void {
		$logID = $this->addLog( 'importdump', 'statusupdate', 3, [
			'4::requestLink' => Message::rawParam( '<a href="/x">#3</a>' ),
			'5::requestStatus' => 'something else',
		] );

		$this->expectOutputRegex( '/Migrated 1 log entries\. Skipped 0\. Left 1 unknown statuses/' );
		$this->maintenance->execute();

		$this->assertSame( [
			'4:request-link:requestID' => 3,
			'5:request-status:requestStatus' => 'something else',
		], $this->getParams( $logID ) );
	}

	public function testIgnoresEntriesThatAreAlreadyMigrated(): void {
		$params = [
			'4:request-link:requestID' => 5,
			'5:request-status:requestStatus' => 'complete',
		];

		$logID = $this->addLog( 'importdump', 'statusupdate', 5, $params );

		$this->expectOutputRegex( '/Migrated 0 log entries/' );
		$this->maintenance->execute();

		$this->assertSame( $params, $this->getParams( $logID ) );
	}

	public function testIgnoresOtherLogTypes(): void {
		$params = [
			'4::requestLink' => Message::rawParam( '<a href="/x">#1</a>' ),
		];

		$logID = $this->addLog( 'otherlog', 'statusupdate', 1, $params );

		$this->expectOutputRegex( '/Migrated 0 log entries/' );
		$this->maintenance->execute();

		$stored = $this->getParams( $logID );
		$this->assertArrayHasKey( '4::requestLink', $stored );
		$this->assertArrayNotHasKey( '4:request-link:requestID', $stored );
	}

	public function testBatches(): void {
		$logIDs = [];
		foreach ( [ 1, 2, 3 ] as $requestID ) {
			$logIDs[$requestID] = $this->addLog( 'importdump', 'request', $requestID, [
				'4::requestTarget' => 'testwiki',
				'5::requestLink' => Message::rawParam( "<a href=\"/x\">#$requestID</a>" ),
			] );
		}

		$maintenance = $this->maintenance;
		'@phan-var TestingAccessWrapper $maintenance';
		$maintenance->setBatchSize( 1 );

		$this->expectOutputRegex( '/Migrated 3 log entries/' );
		$this->maintenance->execute();

		foreach ( $logIDs as $requestID => $logID ) {
			$this->assertSame( $requestID, $this->getParams( $logID )['5:request-link:requestID'] );
		}
	}
}
