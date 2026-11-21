<?php

declare( strict_types = 1 );

namespace Miraheze\ImportDump\Tests\Unit\Notices;

use MediaWiki\Context\IContextSource;
use MediaWiki\Message\Message;
use MediaWikiUnitTestCase;
use Miraheze\ImportDump\Notices\NoticeAudience;
use Miraheze\ImportDump\Notices\NoticeSeverity;
use Miraheze\ImportDump\Notices\PartialHistoryNotices;
use Miraheze\ImportDump\Request\RequestHistory;
use Miraheze\ImportDump\Request\RequestManager;
use Miraheze\ImportDump\Request\RequestStatus;

/**
 * @covers \Miraheze\ImportDump\Notices\PartialHistoryNotices
 */
class PartialHistoryNoticesTest extends MediaWikiUnitTestCase {

	private function getNotices( ?RequestHistory $history ): array {
		$message = $this->createMock( Message::class );
		$message->method( 'escaped' )->willReturn( 'history' );

		$context = $this->createMock( IContextSource::class );
		$context->method( 'msg' )->willReturn( $message );

		$requestManager = $this->createMock( RequestManager::class );
		$requestManager->method( 'getHistory' )->willReturn( $history );

		return new PartialHistoryNotices()->getNotices( $requestManager, $context, RequestStatus::Pending );
	}

	public function testWarnsAboutMissingRevisions(): void {
		$notices = $this->getNotices( RequestHistory::Partial );

		$this->assertCount( 1, $notices );
		$this->assertSame( NoticeSeverity::Warning, $notices[0]->severity );
		$this->assertFalse( $notices[0]->invalidates );
	}

	/** @dataProvider provideOtherHistory */
	public function testShowsNothingOtherwise( ?RequestHistory $history ): void {
		$this->assertSame( [], $this->getNotices( $history ) );
	}

	public static function provideOtherHistory(): array {
		return [
			'full' => [ RequestHistory::Full ],
			'unknown' => [ RequestHistory::Unknown ],
			'not checked' => [ null ],
		];
	}

	public function testIsForThePartiesAndNeedsNoAccessToTheTarget(): void {
		$provider = new PartialHistoryNotices();

		$this->assertSame( NoticeAudience::Parties, $provider->getAudience() );
		$this->assertFalse( $provider->requiresTargetAccess() );
	}
}
