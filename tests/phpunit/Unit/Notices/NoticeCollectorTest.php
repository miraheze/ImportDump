<?php

declare( strict_types = 1 );

namespace Miraheze\ImportDump\Tests\Unit\Notices;

use MediaWiki\Context\IContextSource;
use MediaWikiUnitTestCase;
use Miraheze\ImportDump\Notices\NoticeAudience;
use Miraheze\ImportDump\Notices\NoticeCollector;
use Miraheze\ImportDump\Notices\NoticeProvider;
use Miraheze\ImportDump\Notices\NoticeResult;
use Miraheze\ImportDump\Notices\NoticeRole;
use Miraheze\ImportDump\Notices\NoticeSeverity;
use Miraheze\ImportDump\Notices\RequestNotice;
use Miraheze\ImportDump\Request\RequestManager;
use Miraheze\ImportDump\Request\RequestStatus;
use function array_map;
use function array_merge;

/**
 * @covers \Miraheze\ImportDump\Notices\NoticeCollector
 * @covers \Miraheze\ImportDump\Notices\NoticeResult
 * @covers \Miraheze\ImportDump\Notices\RequestNotice
 */
class NoticeCollectorTest extends MediaWikiUnitTestCase {

	private function newProvider(
		NoticeAudience $audience,
		array $notices,
		bool $requiresTargetAccess,
		bool $alwaysAtTop
	): NoticeProvider {
		$provider = $this->createMock( NoticeProvider::class );
		$provider->method( 'getAudience' )->willReturn( $audience );
		$provider->method( 'requiresTargetAccess' )->willReturn( $requiresTargetAccess );
		$provider->method( 'isAlwaysAtTop' )->willReturn( $alwaysAtTop );
		$provider->method( 'getNotices' )->willReturn( $notices );
		return $provider;
	}

	private function newNotice(
		NoticeSeverity $severity,
		string $html,
		bool $invalidates,
		bool $pinned
	): RequestNotice {
		return new RequestNotice( $severity, $html, $invalidates, $invalidates, $pinned );
	}

	private function collect(
		array $providers,
		RequestStatus $status,
		NoticeRole $role,
		bool $hasTargetAccess
	): NoticeResult {
		return new NoticeCollector( $providers )->collect(
			$this->createMock( RequestManager::class ),
			$this->createMock( IContextSource::class ),
			$status,
			$role,
			$hasTargetAccess
		);
	}

	/**
	 * @param RequestNotice[] $notices
	 * @return string[]
	 */
	private function getHtml( array $notices ): array {
		return array_map( static fn ( RequestNotice $notice ): string => $notice->html, $notices );
	}

	public function testSortsBySeverity(): void {
		$result = $this->collect( [
			$this->newProvider( NoticeAudience::Handlers, [
				$this->newNotice( NoticeSeverity::Notice, 'a', false, false ),
				$this->newNotice( NoticeSeverity::Error, 'b', false, false ),
			], requiresTargetAccess: false, alwaysAtTop: false ),
			$this->newProvider( NoticeAudience::Parties, [
				$this->newNotice( NoticeSeverity::Warning, 'c', false, false ),
			], requiresTargetAccess: false, alwaysAtTop: false ),
		], RequestStatus::Pending, NoticeRole::Handler, true );

		$this->assertSame( [ 'b', 'c', 'a' ], $this->getHtml( $result->notices ) );
		$this->assertSame( [], $result->topNotices );
		$this->assertTrue( $result->valid );
	}

	public function testPinnedNoticesComeFirst(): void {
		$result = $this->collect( [
			$this->newProvider( NoticeAudience::Handlers, [
				$this->newNotice( NoticeSeverity::Notice, 'pinned', false, true ),
			], requiresTargetAccess: false, alwaysAtTop: false ),
			$this->newProvider( NoticeAudience::Handlers, [
				$this->newNotice( NoticeSeverity::Error, 'error', false, false ),
				$this->newNotice( NoticeSeverity::Notice, 'notice', false, false ),
			], requiresTargetAccess: false, alwaysAtTop: false ),
		], RequestStatus::Pending, NoticeRole::Handler, true );

		$this->assertSame( [ 'pinned', 'error', 'notice' ], $this->getHtml( $result->notices ) );
	}

	/** @dataProvider provideAudiences */
	public function testAudiences( NoticeRole $role, array $expected ): void {
		$result = $this->collect( [
			$this->newProvider( NoticeAudience::Handlers, [
				$this->newNotice( NoticeSeverity::Notice, 'handlers', false, false ),
			], requiresTargetAccess: false, alwaysAtTop: false ),
			$this->newProvider( NoticeAudience::Parties, [
				$this->newNotice( NoticeSeverity::Notice, 'parties', false, false ),
			], requiresTargetAccess: false, alwaysAtTop: false ),
			$this->newProvider( NoticeAudience::Everyone, [
				$this->newNotice( NoticeSeverity::Notice, 'everyone', false, false ),
			], requiresTargetAccess: false, alwaysAtTop: false ),
		], RequestStatus::Pending, $role, true );

		$this->assertSame(
			$expected,
			$this->getHtml( array_merge( $result->notices, $result->topNotices ) )
		);
	}

	public static function provideAudiences(): array {
		return [
			'handler' => [ NoticeRole::Handler, [ 'handlers', 'parties', 'everyone' ] ],
			'requester' => [ NoticeRole::Requester, [ 'parties', 'everyone' ] ],
			'anyone else' => [ NoticeRole::Viewer, [ 'everyone' ] ],
		];
	}

	public function testPlacement(): void {
		$providers = [
			$this->newProvider( NoticeAudience::Everyone, [
				$this->newNotice( NoticeSeverity::Error, 'always at the top', false, false ),
			], requiresTargetAccess: false, alwaysAtTop: true ),
			$this->newProvider( NoticeAudience::Parties, [
				$this->newNotice( NoticeSeverity::Notice, 'other', false, false ),
			], requiresTargetAccess: false, alwaysAtTop: false ),
		];

		$handler = $this->collect(
			$providers, RequestStatus::Pending, NoticeRole::Handler, true
		);

		$this->assertSame( [ 'other' ], $this->getHtml( $handler->notices ) );
		$this->assertSame( [ 'always at the top' ], $this->getHtml( $handler->topNotices ) );

		$requester = $this->collect(
			$providers, RequestStatus::Pending, NoticeRole::Requester, true
		);

		$this->assertSame( [], $requester->notices );
		$this->assertSame( [ 'always at the top', 'other' ], $this->getHtml( $requester->topNotices ) );
	}

	/** @dataProvider provideTargetAccess */
	public function testTargetNoticesNeedAccess( bool $hasTargetAccess, NoticeRole $role, int $expected ): void {
		$result = $this->collect( [
			$this->newProvider( NoticeAudience::Parties, [
				$this->newNotice( NoticeSeverity::Error, 'remote', true, false ),
			], requiresTargetAccess: true, alwaysAtTop: false ),
			$this->newProvider( NoticeAudience::Parties, [
				$this->newNotice( NoticeSeverity::Notice, 'local', false, false ),
			], requiresTargetAccess: false, alwaysAtTop: false ),
		], RequestStatus::Pending, $role, $hasTargetAccess );

		$this->assertCount( $expected, array_merge( $result->notices, $result->topNotices ) );
	}

	public static function provideTargetAccess(): array {
		return [
			'requester with access' => [ true, NoticeRole::Requester, 2 ],
			'requester without access' => [ false, NoticeRole::Requester, 1 ],
			'handler' => [ false, NoticeRole::Handler, 2 ],
		];
	}

	/** @dataProvider provideDeclines */
	public function testDeclines( RequestStatus $status, RequestStatus $expected ): void {
		$result = $this->collect( [
			$this->newProvider( NoticeAudience::Handlers, [
				$this->newNotice( NoticeSeverity::Error, 'x', true, false ),
			], requiresTargetAccess: false, alwaysAtTop: false ),
		], $status, NoticeRole::Handler, true );

		$this->assertFalse( $result->valid );
		$this->assertSame( $expected, $result->status );
	}

	public static function provideDeclines(): array {
		return [
			'pending' => [ RequestStatus::Pending, RequestStatus::Declined ],
			'inprogress' => [ RequestStatus::InProgress, RequestStatus::Declined ],
			'complete' => [ RequestStatus::Complete, RequestStatus::Complete ],
		];
	}
}
