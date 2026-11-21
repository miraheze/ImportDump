<?php

namespace Miraheze\ImportDump\Tests\Unit;

use MediaWiki\Context\IContextSource;
use MediaWiki\Message\Message;
use MediaWiki\User\User;
use MediaWikiUnitTestCase;
use Miraheze\ImportDump\Notices\ImportRightNotices;
use Miraheze\ImportDump\Notices\NoticeAudience;
use Miraheze\ImportDump\Notices\NoticeSeverity;
use Miraheze\ImportDump\RequestManager;
use Miraheze\ImportDump\RequestStatus;

/**
 * @covers \Miraheze\ImportDump\Notices\ImportRightNotices
 */
class ImportRightNoticesTest extends MediaWikiUnitTestCase {

	private function getNotices( ?bool $hasRight ): array {
		$message = $this->createMock( Message::class );
		$message->method( 'escaped' )->willReturn( 'no import right' );

		$context = $this->createMock( IContextSource::class );
		$context->method( 'msg' )->with( 'importdump-info-no-import-right', 'Requester', 'targetwiki' )
			->willReturn( $message );

		$requester = $this->createMock( User::class );
		$requester->method( 'getName' )->willReturn( 'Requester' );

		$requestManager = $this->createMock( RequestManager::class );
		$requestManager->method( 'requesterHasTargetRight' )->with( 'import' )->willReturn( $hasRight );
		$requestManager->method( 'getRequester' )->willReturn( $requester );
		$requestManager->method( 'getTarget' )->willReturn( 'targetwiki' );

		return new ImportRightNotices()->getNotices( $requestManager, $context, RequestStatus::Pending );
	}

	public function testWarnsWhenTheRequesterLacksTheRight(): void {
		$notices = $this->getNotices( hasRight: false );

		$this->assertCount( 1, $notices );
		$this->assertSame( NoticeSeverity::Warning, $notices[0]->severity );
		$this->assertSame( 'no import right', $notices[0]->html );
		$this->assertFalse( $notices[0]->invalidates );
		$this->assertFalse( $notices[0]->declines );
	}

	public function testShowsNothingWhenTheRequesterHasTheRight(): void {
		$this->assertSame( [], $this->getNotices( hasRight: true ) );
	}

	public function testShowsNothingWhenItIsNotKnown(): void {
		$this->assertSame( [], $this->getNotices( hasRight: null ) );
	}

	public function testIsForThePartiesAndNeedsAccessToTheTarget(): void {
		$provider = new ImportRightNotices();

		$this->assertSame( NoticeAudience::Parties, $provider->getAudience() );
		$this->assertTrue( $provider->requiresTargetAccess() );
		$this->assertFalse( $provider->isAlwaysAtTop() );
	}
}
