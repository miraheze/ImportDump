<?php

declare( strict_types = 1 );

namespace Miraheze\ImportDump\Tests\Unit\Notices;

use MediaWiki\Context\IContextSource;
use MediaWiki\Language\Language;
use MediaWiki\Message\Message;
use MediaWikiUnitTestCase;
use Miraheze\ImportDump\Notices\NoticeAudience;
use Miraheze\ImportDump\Notices\NoticeSeverity;
use Miraheze\ImportDump\Notices\RequestNotice;
use Miraheze\ImportDump\Notices\SkippedImageNotices;
use Miraheze\ImportDump\Request\RequestManager;
use Miraheze\ImportDump\Request\RequestStatus;
use Miraheze\ImportDump\Upload\SkippedFile;
use function implode;
use function substr_count;

/**
 * @covers \Miraheze\ImportDump\Notices\SkippedImageNotices
 */
class SkippedImageNoticesTest extends MediaWikiUnitTestCase {

	/**
	 * @param int $total
	 * @param list<SkippedFile> $files
	 * @return RequestNotice[]
	 */
	private function getNotices( int $total, array $files ): array {
		$message = $this->createMock( Message::class );
		$message->method( 'numParams' )->willReturnSelf();
		$message->method( 'params' )->willReturnSelf();
		$message->method( 'escaped' )->willReturn( 'summary' );
		$message->method( 'text' )->willReturn( 'reason' );

		$language = $this->createMock( Language::class );
		$language->method( 'listToText' )->willReturnCallback(
			static fn ( array $items ): string => implode( ', ', $items )
		);

		$context = $this->createMock( IContextSource::class );
		$context->method( 'msg' )->willReturn( $message );
		$context->method( 'getLanguage' )->willReturn( $language );

		$requestManager = $this->createMock( RequestManager::class );
		$requestManager->method( 'getSkippedImages' )->willReturn( [ 'total' => $total, 'files' => $files ] );

		return new SkippedImageNotices()->getNotices( $requestManager, $context, RequestStatus::Pending );
	}

	public function testShowsNothingWhenNothingWasSkipped(): void {
		$this->assertSame( [], $this->getNotices( 0, [] ) );
	}

	public function testWarnsAboutTheSkippedFilesWithoutBlockingTheRequest(): void {
		$notices = $this->getNotices( 2, [
			new SkippedFile( 'a.png', 'requestimport-images-skip-extension', [] ),
			new SkippedFile( 'b<i>.png', 'filetype-mime-mismatch', [ 'png', 'image/jpeg' ] ),
		] );

		$this->assertCount( 1, $notices );
		$this->assertSame( NoticeSeverity::Warning, $notices[0]->severity );
		$this->assertFalse( $notices[0]->invalidates );
		$this->assertFalse( $notices[0]->declines );
		$this->assertStringStartsWith( 'summary<details', $notices[0]->html );
		$this->assertSame( 2, substr_count( $notices[0]->html, '<li>' ) );
		$this->assertStringContainsString( 'a.png: reason', $notices[0]->html );
		$this->assertStringContainsString( 'b&lt;i>.png: reason', $notices[0]->html );
		$this->assertStringNotContainsString( '<i>', $notices[0]->html );
	}

	public function testSaysWhenTheListIsCutShort(): void {
		$notices = $this->getNotices( 3, [
			new SkippedFile( 'a.png', 'requestimport-images-skip-extension', [] ),
		] );

		$this->assertStringContainsString( '<p>reason</p>', $notices[0]->html );
	}

	public function testIsShownAboveTheFormToThePartiesAndNeedsNoAccessToTheTarget(): void {
		$provider = new SkippedImageNotices();

		$this->assertSame( NoticeAudience::Parties, $provider->getAudience() );
		$this->assertTrue( $provider->isAlwaysAtTop() );
		$this->assertFalse( $provider->requiresTargetAccess() );
	}
}
