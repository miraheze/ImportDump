<?php

declare( strict_types = 1 );

namespace Miraheze\ImportDump\Tests\Unit\Request;

use Generator;
use MediaWikiUnitTestCase;
use Miraheze\ImportDump\Request\RequestHistory;
use Miraheze\ImportDump\Request\RequestStage;
use Miraheze\ImportDump\Request\RequestStatus;

use function array_column;

/**
 * @covers \Miraheze\ImportDump\Request\RequestHistory
 * @covers \Miraheze\ImportDump\Request\RequestStage
 * @covers \Miraheze\ImportDump\Request\RequestStatus
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

	public static function provideRunning(): Generator {
		yield 'starting' => [ RequestStatus::Starting, true ];
		yield 'inprogress' => [ RequestStatus::InProgress, true ];
		yield 'pending' => [ RequestStatus::Pending, false ];
		yield 'partial' => [ RequestStatus::Partial, false ];
		yield 'complete' => [ RequestStatus::Complete, false ];
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

	public static function provideHasImported(): Generator {
		yield 'download' => [ RequestStage::Download, false ];
		yield 'import' => [ RequestStage::Import, false ];
		yield 'sitestats' => [ RequestStage::SiteStats, true ];
		yield 'afterimport' => [ RequestStage::AfterImport, true ];
	}

	public function testStageLabelKey(): void {
		$this->assertSame( 'importdump-stage-textindex', RequestStage::TextIndex->getLabelKey() );
	}

	public function testHistoryInfoKey(): void {
		$this->assertSame( 'importdump-info-history-partial', RequestHistory::Partial->getInfoKey() );
		$this->assertSame( [ 'full', 'partial', 'unknown' ], array_column( RequestHistory::cases(), 'value' ) );
	}
}
