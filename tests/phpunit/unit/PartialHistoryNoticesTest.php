<?php

namespace Miraheze\ImportDump\Tests\Unit;

use MediaWiki\Context\IContextSource;
use MediaWiki\Message\Message;
use MediaWikiUnitTestCase;
use Miraheze\ImportDump\Notices\PartialHistoryNotices;
use Miraheze\ImportDump\Notices\NoticeProvider;
use Miraheze\ImportDump\Notices\RequestNotice;
use Miraheze\ImportDump\RequestHistory;
use Miraheze\ImportDump\RequestManager;
use Miraheze\ImportDump\RequestStatus;

/**
 * @covers \Miraheze\ImportDump\Notices\PartialHistoryNotices
 */
class PartialHistoryNoticesTest extends MediaWikiUnitTestCase {

	private function getNotices( ?string $history ): array {
		$message = $this->createMock( Message::class );
		$message->method( 'escaped' )->willReturn( 'history' );

		$context = $this->createMock( IContextSource::class );
		$context->method( 'msg' )->willReturn( $message );

		$requestManager = $this->createMock( RequestManager::class );
		$requestManager->method( 'getHistory' )->willReturn( $history );

		return new PartialHistoryNotices()->getNotices( $requestManager, $context, RequestStatus::STATUS_PENDING );
	}

	public function testWarnsAboutMissingRevisions(): void {
		$notices = $this->getNotices( RequestHistory::HISTORY_PARTIAL );

		$this->assertCount( 1, $notices );
		$this->assertSame( RequestNotice::SEVERITY_WARNING, $notices[0]->severity );
		$this->assertFalse( $notices[0]->invalidates );
	}

	/** @dataProvider provideOtherHistory */
	public function testShowsNothingOtherwise( ?string $history ): void {
		$this->assertSame( [], $this->getNotices( $history ) );
	}

	public static function provideOtherHistory(): array {
		return [
			'full' => [ RequestHistory::HISTORY_FULL ],
			'unknown' => [ RequestHistory::HISTORY_UNKNOWN ],
			'not checked' => [ null ],
		];
	}

	public function testIsForThePartiesAndNeedsNoAccessToTheTarget(): void {
		$provider = new PartialHistoryNotices();

		$this->assertSame( NoticeProvider::AUDIENCE_PARTIES, $provider->getAudience() );
		$this->assertFalse( $provider->requiresTargetAccess() );
	}
}
