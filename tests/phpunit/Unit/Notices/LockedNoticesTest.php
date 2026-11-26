<?php

declare( strict_types = 1 );

namespace Miraheze\ImportDump\Tests\Unit\Notices;

use MediaWiki\Context\IContextSource;
use MediaWiki\Message\Message;
use MediaWikiUnitTestCase;
use Miraheze\ImportDump\Notices\LockedNotices;
use Miraheze\ImportDump\Notices\NoticeAudience;
use Miraheze\ImportDump\Notices\NoticeSeverity;
use Miraheze\ImportDump\Request\RequestManager;
use Miraheze\ImportDump\Request\RequestStatus;

/**
 * @covers \Miraheze\ImportDump\Notices\LockedNotices
 */
class LockedNoticesTest extends MediaWikiUnitTestCase {

	private function getNotices( bool $locked ): array {
		$message = $this->createMock( Message::class );
		$message->method( 'escaped' )->willReturn( 'locked' );

		$context = $this->createMock( IContextSource::class );
		// @phan-suppress-next-line PhanTypeMismatchArgumentProbablyReal
		$context->method( 'msg' )->with( 'requestimport-request-locked' )->willReturn( $message );

		$requestManager = $this->createMock( RequestManager::class );
		$requestManager->method( 'isLocked' )->willReturn( $locked );

		return new LockedNotices()->getNotices(
			$requestManager,
			$context,
			RequestStatus::Pending
		);
	}

	public function testShowsAnErrorWhenLocked(): void {
		$notices = $this->getNotices( locked: true );

		$this->assertCount( 1, $notices );
		$this->assertSame( NoticeSeverity::Error, $notices[0]->severity );
		$this->assertSame( 'locked', $notices[0]->html );
		$this->assertFalse( $notices[0]->invalidates );
	}

	public function testShowsNothingWhenNotLocked(): void {
		$this->assertSame( [], $this->getNotices( locked: false ) );
	}

	public function testIsForEveryoneAndAlwaysAtTheTop(): void {
		$provider = new LockedNotices();

		$this->assertSame( NoticeAudience::Everyone, $provider->getAudience() );
		$this->assertTrue( $provider->isAlwaysAtTop() );
		$this->assertFalse( $provider->requiresTargetAccess() );
	}
}
