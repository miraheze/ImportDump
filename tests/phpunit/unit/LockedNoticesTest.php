<?php

namespace Miraheze\ImportDump\Tests\Unit;

use MediaWiki\Context\IContextSource;
use MediaWiki\Message\Message;
use MediaWikiUnitTestCase;
use Miraheze\ImportDump\Notices\LockedNotices;
use Miraheze\ImportDump\Notices\NoticeProvider;
use Miraheze\ImportDump\Notices\RequestNotice;
use Miraheze\ImportDump\RequestManager;
use Miraheze\ImportDump\RequestStatus;

/**
 * @covers \Miraheze\ImportDump\Notices\LockedNotices
 */
class LockedNoticesTest extends MediaWikiUnitTestCase {

	private function getNotices( bool $locked ): array {
		$message = $this->createMock( Message::class );
		$message->method( 'escaped' )->willReturn( 'locked' );

		$context = $this->createMock( IContextSource::class );
		$context->method( 'msg' )->with( 'importdump-request-locked' )->willReturn( $message );

		$requestManager = $this->createMock( RequestManager::class );
		$requestManager->method( 'isLocked' )->willReturn( $locked );

		return ( new LockedNotices() )->getNotices(
			$requestManager,
			$context,
			RequestStatus::STATUS_PENDING
		);
	}

	public function testShowsAnErrorWhenLocked(): void {
		$notices = $this->getNotices( locked: true );

		$this->assertCount( 1, $notices );
		$this->assertSame( RequestNotice::SEVERITY_ERROR, $notices[0]->severity );
		$this->assertSame( 'locked', $notices[0]->html );
		$this->assertFalse( $notices[0]->invalidates );
	}

	public function testShowsNothingWhenNotLocked(): void {
		$this->assertSame( [], $this->getNotices( locked: false ) );
	}

	public function testIsForEveryoneAndAlwaysAtTheTop(): void {
		$provider = new LockedNotices();

		$this->assertSame( NoticeProvider::AUDIENCE_EVERYONE, $provider->getAudience() );
		$this->assertTrue( $provider->isAlwaysAtTop() );
		$this->assertFalse( $provider->requiresTargetAccess() );
	}
}
