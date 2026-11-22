<?php

declare( strict_types = 1 );

namespace Miraheze\ImportDump\Tests\Unit\Notices;

use MediaWiki\Context\IContextSource;
use MediaWikiUnitTestCase;
use Miraheze\ImportDump\Notices\ActionOutcomeNotices;
use Miraheze\ImportDump\Notices\NoticeAudience;
use Miraheze\ImportDump\Notices\NoticeSeverity;
use Miraheze\ImportDump\Request\RequestManager;
use Miraheze\ImportDump\Request\RequestStatus;

/**
 * @covers \Miraheze\ImportDump\Notices\ActionOutcomeNotices
 */
class ActionOutcomeNoticesTest extends MediaWikiUnitTestCase {

	private function getNotices( ActionOutcomeNotices $provider ): array {
		return $provider->getNotices(
			$this->createMock( RequestManager::class ),
			$this->createMock( IContextSource::class ),
			RequestStatus::Pending
		);
	}

	public function testShowsNothingUntilAnActionAddsAResult(): void {
		$this->assertSame( [], $this->getNotices( new ActionOutcomeNotices() ) );
	}

	public function testShowsTheResultsInTheOrderTheyWereAdded(): void {
		$provider = new ActionOutcomeNotices();
		$provider->add( NoticeSeverity::Success, 'saved' );
		$provider->add( NoticeSeverity::Error, 'refused' );

		$notices = $this->getNotices( $provider );

		$this->assertCount( 2, $notices );
		$this->assertSame( NoticeSeverity::Success, $notices[0]->severity );
		$this->assertSame( 'saved', $notices[0]->html );
		$this->assertSame( NoticeSeverity::Error, $notices[1]->severity );
		$this->assertSame( 'refused', $notices[1]->html );
	}

	public function testResultsArePinnedAndNeverChangeTheRequest(): void {
		$provider = new ActionOutcomeNotices();
		$provider->add( NoticeSeverity::Error, 'refused' );

		$notice = $this->getNotices( $provider )[0];

		$this->assertTrue( $notice->pinned );
		$this->assertFalse( $notice->invalidates );
		$this->assertFalse( $notice->declines );
	}

	public function testIsForEveryoneAndAlwaysAtTheTop(): void {
		$provider = new ActionOutcomeNotices();

		$this->assertSame( NoticeAudience::Everyone, $provider->getAudience() );
		$this->assertTrue( $provider->isAlwaysAtTop() );
		$this->assertFalse( $provider->requiresTargetAccess() );
	}
}
