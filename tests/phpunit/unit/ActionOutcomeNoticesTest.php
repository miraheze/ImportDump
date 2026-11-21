<?php

namespace Miraheze\ImportDump\Tests\Unit;

use MediaWiki\Context\IContextSource;
use MediaWikiUnitTestCase;
use Miraheze\ImportDump\Notices\ActionOutcomeNotices;
use Miraheze\ImportDump\Notices\NoticeProvider;
use Miraheze\ImportDump\Notices\RequestNotice;
use Miraheze\ImportDump\RequestManager;
use Miraheze\ImportDump\RequestStatus;

/**
 * @covers \Miraheze\ImportDump\Notices\ActionOutcomeNotices
 */
class ActionOutcomeNoticesTest extends MediaWikiUnitTestCase {

	private function getNotices( ActionOutcomeNotices $provider ): array {
		return $provider->getNotices(
			$this->createMock( RequestManager::class ),
			$this->createMock( IContextSource::class ),
			RequestStatus::STATUS_PENDING
		);
	}

	public function testShowsNothingUntilAnActionAddsAResult(): void {
		$this->assertSame( [], $this->getNotices( new ActionOutcomeNotices() ) );
	}

	public function testShowsTheResultsInTheOrderTheyWereAdded(): void {
		$provider = new ActionOutcomeNotices();
		$provider->add( RequestNotice::SEVERITY_SUCCESS, 'saved' );
		$provider->add( RequestNotice::SEVERITY_ERROR, 'refused' );

		$notices = $this->getNotices( $provider );

		$this->assertCount( 2, $notices );
		$this->assertSame( RequestNotice::SEVERITY_SUCCESS, $notices[0]->severity );
		$this->assertSame( 'saved', $notices[0]->html );
		$this->assertSame( RequestNotice::SEVERITY_ERROR, $notices[1]->severity );
		$this->assertSame( 'refused', $notices[1]->html );
	}

	public function testResultsArePinnedAndNeverChangeTheRequest(): void {
		$provider = new ActionOutcomeNotices();
		$provider->add( RequestNotice::SEVERITY_ERROR, 'refused' );

		$notice = $this->getNotices( $provider )[0];

		$this->assertTrue( $notice->pinned );
		$this->assertFalse( $notice->invalidates );
		$this->assertFalse( $notice->declines );
	}

	public function testIsForEveryoneAndAlwaysAtTheTop(): void {
		$provider = new ActionOutcomeNotices();

		$this->assertSame( NoticeProvider::AUDIENCE_EVERYONE, $provider->getAudience() );
		$this->assertTrue( $provider->isAlwaysAtTop() );
		$this->assertFalse( $provider->requiresTargetAccess() );
	}
}
