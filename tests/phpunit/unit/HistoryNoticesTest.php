<?php

namespace Miraheze\ImportDump\Tests\Unit;

use MediaWiki\Context\IContextSource;
use MediaWiki\Message\Message;
use MediaWikiUnitTestCase;
use Miraheze\ImportDump\Notices\HistoryNotices;
use Miraheze\ImportDump\Notices\NoticeAudience;
use Miraheze\ImportDump\Notices\NoticeSeverity;
use Miraheze\ImportDump\RequestHistory;
use Miraheze\ImportDump\RequestManager;
use Miraheze\ImportDump\RequestStatus;

/**
 * @covers \Miraheze\ImportDump\Notices\HistoryNotices
 */
class HistoryNoticesTest extends MediaWikiUnitTestCase {

	private function getNotices( ?RequestHistory $history ): array {
		$message = $this->createMock( Message::class );
		$message->method( 'escaped' )->willReturn( 'history' );

		$context = $this->createMock( IContextSource::class );
		$context->method( 'msg' )->willReturn( $message );

		$requestManager = $this->createMock( RequestManager::class );
		$requestManager->method( 'getHistory' )->willReturn( $history );

		return new HistoryNotices()->getNotices( $requestManager, $context, RequestStatus::Pending );
	}

	/** @dataProvider provideHistoryWithoutMissingRevisions */
	public function testShowsANoticeWhenNoRevisionsAreMissing( RequestHistory $history ): void {
		$notices = $this->getNotices( $history );

		$this->assertCount( 1, $notices );
		$this->assertSame( NoticeSeverity::Notice, $notices[0]->severity );
		$this->assertFalse( $notices[0]->invalidates );
	}

	public static function provideHistoryWithoutMissingRevisions(): array {
		return [
			'full' => [ RequestHistory::Full ],
			'unknown' => [ RequestHistory::Unknown ],
		];
	}

	public function testShowsNothingForMissingRevisionsOrWithoutACheck(): void {
		$this->assertSame( [], $this->getNotices( RequestHistory::Partial ) );
		$this->assertSame( [], $this->getNotices( null ) );
	}

	public function testIsForHandlersOnly(): void {
		$this->assertSame( NoticeAudience::Handlers, new HistoryNotices()->getAudience() );
	}
}
