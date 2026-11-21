<?php

namespace Miraheze\ImportDump\Tests\Unit;

use MediaWikiUnitTestCase;
use Miraheze\ImportDump\RequestHistory;
use Miraheze\ImportDump\RequestStage;
use Miraheze\ImportDump\RequestStatus;

/**
 * @covers \Miraheze\ImportDump\RequestHistory
 * @covers \Miraheze\ImportDump\RequestStage
 * @covers \Miraheze\ImportDump\RequestStatus
 */
class RequestEnumsTest extends MediaWikiUnitTestCase {

	public function testStatusesKeepTheirStoredValues(): void {
		$this->assertSame(
			[ 'complete', 'declined', 'failed', 'inprogress', 'partial', 'pending', 'starting', 'verifying' ],
			array_column( RequestStatus::cases(), 'value' )
		);
	}

	public function testStatusLabelKey(): void {
		$this->assertSame( 'importdump-label-inprogress', RequestStatus::InProgress->getLabelKey() );
	}

	/** @dataProvider provideRunning */
	public function testIsRunning( RequestStatus $status, bool $expected ): void {
		$this->assertSame( $expected, $status->isRunning() );
	}

	public static function provideRunning(): array {
		return [
			'starting' => [ RequestStatus::Starting, true ],
			'inprogress' => [ RequestStatus::InProgress, true ],
			'pending' => [ RequestStatus::Pending, false ],
			'partial' => [ RequestStatus::Partial, false ],
			'complete' => [ RequestStatus::Complete, false ],
		];
	}

	public function testStagesRunInOrder(): void {
		$this->assertSame(
			[
				'download', 'import', 'sitestats', 'textindex',
				'recentchanges', 'refreshlinks', 'counts', 'afterimport',
			],
			array_column( RequestStage::cases(), 'value' )
		);

		$this->assertSame( 0, RequestStage::Download->getPosition() );
		$this->assertSame( 7, RequestStage::AfterImport->getPosition() );
	}

	/** @dataProvider provideHasImported */
	public function testStageHasImported( RequestStage $stage, bool $expected ): void {
		$this->assertSame( $expected, $stage->hasImported() );
	}

	public static function provideHasImported(): array {
		return [
			'download' => [ RequestStage::Download, false ],
			'import' => [ RequestStage::Import, false ],
			'sitestats' => [ RequestStage::SiteStats, true ],
			'afterimport' => [ RequestStage::AfterImport, true ],
		];
	}

	public function testStageLabelKey(): void {
		$this->assertSame( 'importdump-stage-textindex', RequestStage::TextIndex->getLabelKey() );
	}

	public function testHistoryInfoKey(): void {
		$this->assertSame( 'importdump-info-history-partial', RequestHistory::Partial->getInfoKey() );
		$this->assertSame( [ 'full', 'partial', 'unknown' ], array_column( RequestHistory::cases(), 'value' ) );
	}
}
