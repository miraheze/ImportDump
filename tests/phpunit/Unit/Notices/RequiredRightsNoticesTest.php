<?php

declare( strict_types = 1 );

namespace Miraheze\ImportDump\Tests\Unit\Notices;

use MediaWiki\Context\IContextSource;
use MediaWiki\Language\Language;
use MediaWiki\Message\Message;
use MediaWiki\User\User;
use MediaWikiUnitTestCase;
use Miraheze\ImportDump\Notices\NoticeAudience;
use Miraheze\ImportDump\Notices\NoticeSeverity;
use Miraheze\ImportDump\Notices\RequiredRightsNotices;
use Miraheze\ImportDump\Request\RequestManager;
use Miraheze\ImportDump\Request\RequestStatus;
use function implode;

/**
 * @covers \Miraheze\ImportDump\Notices\RequiredRightsNotices
 */
class RequiredRightsNoticesTest extends MediaWikiUnitTestCase {

	/** @param ?list<string> $missing */
	private function getNotices( ?array $missing ): array {
		$message = $this->createMock( Message::class );
		$message->method( 'escaped' )->willReturn( 'missing rights' );

		$language = $this->createMock( Language::class );
		$language->method( 'commaList' )->willReturnCallback(
			static fn ( array $list ): string => implode( ', ', $list )
		);

		$context = $this->createMock( IContextSource::class );
		$context->method( 'getLanguage' )->willReturn( $language );
		$context->method( 'msg' )
			// @phan-suppress-next-line PhanTypeMismatchArgumentProbablyReal
			->with( 'requestimport-info-missing-rights', 'Requester', 'targetwiki', 'import, upload', 2 )
			->willReturn( $message );

		$requester = $this->createMock( User::class );
		$requester->method( 'getName' )->willReturn( 'Requester' );

		$requestManager = $this->createMock( RequestManager::class );
		$requestManager->method( 'getMissingTargetRights' )->willReturn( $missing );
		$requestManager->method( 'getRequester' )->willReturn( $requester );
		$requestManager->method( 'getTarget' )->willReturn( 'targetwiki' );

		return new RequiredRightsNotices()->getNotices( $requestManager, $context, RequestStatus::Pending );
	}

	public function testWarnsWhenTheRequesterLacksRights(): void {
		$notices = $this->getNotices( missing: [ 'import', 'upload' ] );

		$this->assertCount( 1, $notices );
		$this->assertSame( NoticeSeverity::Warning, $notices[0]->severity );
		$this->assertSame( 'missing rights', $notices[0]->html );
		$this->assertFalse( $notices[0]->invalidates );
		$this->assertFalse( $notices[0]->declines );
	}

	public function testShowsNothingWhenNoRightIsMissing(): void {
		$this->assertSame( [], $this->getNotices( missing: [] ) );
	}

	public function testShowsNothingWhenItIsNotKnown(): void {
		$this->assertSame( [], $this->getNotices( missing: null ) );
	}

	public function testIsForThePartiesAndNeedsAccessToTheTarget(): void {
		$provider = new RequiredRightsNotices();

		$this->assertSame( NoticeAudience::Parties, $provider->getAudience() );
		$this->assertTrue( $provider->requiresTargetAccess() );
		$this->assertFalse( $provider->isAlwaysAtTop() );
	}
}
