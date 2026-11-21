<?php

namespace Miraheze\ImportDump\Tests\Unit;

use MediaWiki\Context\IContextSource;
use MediaWikiUnitTestCase;
use Miraheze\ImportDump\Notices\NoticeCollector;
use Miraheze\ImportDump\Notices\NoticeProvider;
use Miraheze\ImportDump\Notices\NoticeResult;
use Miraheze\ImportDump\Notices\RequestNotice;
use Miraheze\ImportDump\RequestManager;
use Miraheze\ImportDump\RequestStatus;

/**
 * @covers \Miraheze\ImportDump\Notices\NoticeCollector
 * @covers \Miraheze\ImportDump\Notices\NoticeResult
 * @covers \Miraheze\ImportDump\Notices\RequestNotice
 */
class NoticeCollectorTest extends MediaWikiUnitTestCase {

	private function newProvider(
		string $audience,
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

	private function newNotice( string $severity, string $html, bool $invalidates, bool $pinned ): RequestNotice {
		return new RequestNotice( $severity, $html, $invalidates, $invalidates, $pinned );
	}

	private function collect(
		array $providers,
		string $status,
		string $role,
		bool $hasTargetAccess
	): NoticeResult {
		return ( new NoticeCollector( $providers ) )->collect(
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
			$this->newProvider( NoticeProvider::AUDIENCE_HANDLERS, [
				$this->newNotice( RequestNotice::SEVERITY_NOTICE, 'a', false, false ),
				$this->newNotice( RequestNotice::SEVERITY_ERROR, 'b', false, false ),
			], requiresTargetAccess: false, alwaysAtTop: false ),
			$this->newProvider( NoticeProvider::AUDIENCE_PARTIES, [
				$this->newNotice( RequestNotice::SEVERITY_WARNING, 'c', false, false ),
			], requiresTargetAccess: false, alwaysAtTop: false ),
		], RequestStatus::STATUS_PENDING, NoticeCollector::ROLE_HANDLER, true );

		$this->assertSame( [ 'b', 'c', 'a' ], $this->getHtml( $result->notices ) );
		$this->assertSame( [], $result->topNotices );
		$this->assertTrue( $result->valid );
	}

	public function testPinnedNoticesComeFirst(): void {
		$result = $this->collect( [
			$this->newProvider( NoticeProvider::AUDIENCE_HANDLERS, [
				$this->newNotice( RequestNotice::SEVERITY_NOTICE, 'pinned', false, true ),
			], requiresTargetAccess: false, alwaysAtTop: false ),
			$this->newProvider( NoticeProvider::AUDIENCE_HANDLERS, [
				$this->newNotice( RequestNotice::SEVERITY_ERROR, 'error', false, false ),
				$this->newNotice( RequestNotice::SEVERITY_NOTICE, 'notice', false, false ),
			], requiresTargetAccess: false, alwaysAtTop: false ),
		], RequestStatus::STATUS_PENDING, NoticeCollector::ROLE_HANDLER, true );

		$this->assertSame( [ 'pinned', 'error', 'notice' ], $this->getHtml( $result->notices ) );
	}

	/** @dataProvider provideAudiences */
	public function testAudiences( string $role, array $expected ): void {
		$result = $this->collect( [
			$this->newProvider( NoticeProvider::AUDIENCE_HANDLERS, [
				$this->newNotice( RequestNotice::SEVERITY_NOTICE, 'handlers', false, false ),
			], requiresTargetAccess: false, alwaysAtTop: false ),
			$this->newProvider( NoticeProvider::AUDIENCE_PARTIES, [
				$this->newNotice( RequestNotice::SEVERITY_NOTICE, 'parties', false, false ),
			], requiresTargetAccess: false, alwaysAtTop: false ),
			$this->newProvider( NoticeProvider::AUDIENCE_EVERYONE, [
				$this->newNotice( RequestNotice::SEVERITY_NOTICE, 'everyone', false, false ),
			], requiresTargetAccess: false, alwaysAtTop: false ),
		], RequestStatus::STATUS_PENDING, $role, true );

		$this->assertSame(
			$expected,
			$this->getHtml( array_merge( $result->notices, $result->topNotices ) )
		);
	}

	public static function provideAudiences(): array {
		return [
			'handler' => [ NoticeCollector::ROLE_HANDLER, [ 'handlers', 'parties', 'everyone' ] ],
			'requester' => [ NoticeCollector::ROLE_REQUESTER, [ 'parties', 'everyone' ] ],
			'anyone else' => [ NoticeCollector::ROLE_VIEWER, [ 'everyone' ] ],
		];
	}

	public function testPlacement(): void {
		$providers = [
			$this->newProvider( NoticeProvider::AUDIENCE_EVERYONE, [
				$this->newNotice( RequestNotice::SEVERITY_ERROR, 'always at the top', false, false ),
			], requiresTargetAccess: false, alwaysAtTop: true ),
			$this->newProvider( NoticeProvider::AUDIENCE_PARTIES, [
				$this->newNotice( RequestNotice::SEVERITY_NOTICE, 'other', false, false ),
			], requiresTargetAccess: false, alwaysAtTop: false ),
		];

		$handler = $this->collect(
			$providers, RequestStatus::STATUS_PENDING, NoticeCollector::ROLE_HANDLER, true
		);

		$this->assertSame( [ 'other' ], $this->getHtml( $handler->notices ) );
		$this->assertSame( [ 'always at the top' ], $this->getHtml( $handler->topNotices ) );

		$requester = $this->collect(
			$providers, RequestStatus::STATUS_PENDING, NoticeCollector::ROLE_REQUESTER, true
		);

		$this->assertSame( [], $requester->notices );
		$this->assertSame( [ 'always at the top', 'other' ], $this->getHtml( $requester->topNotices ) );
	}

	/** @dataProvider provideTargetAccess */
	public function testTargetNoticesNeedAccess( bool $hasTargetAccess, string $role, int $expected ): void {
		$result = $this->collect( [
			$this->newProvider( NoticeProvider::AUDIENCE_PARTIES, [
				$this->newNotice( RequestNotice::SEVERITY_ERROR, 'remote', true, false ),
			], requiresTargetAccess: true, alwaysAtTop: false ),
			$this->newProvider( NoticeProvider::AUDIENCE_PARTIES, [
				$this->newNotice( RequestNotice::SEVERITY_NOTICE, 'local', false, false ),
			], requiresTargetAccess: false, alwaysAtTop: false ),
		], RequestStatus::STATUS_PENDING, $role, $hasTargetAccess );

		$this->assertCount( $expected, array_merge( $result->notices, $result->topNotices ) );
	}

	public static function provideTargetAccess(): array {
		return [
			'requester with access' => [ true, NoticeCollector::ROLE_REQUESTER, 2 ],
			'requester without access' => [ false, NoticeCollector::ROLE_REQUESTER, 1 ],
			'handler' => [ false, NoticeCollector::ROLE_HANDLER, 2 ],
		];
	}

	/** @dataProvider provideDeclines */
	public function testDeclines( string $status, string $expected ): void {
		$result = $this->collect( [
			$this->newProvider( NoticeProvider::AUDIENCE_HANDLERS, [
				$this->newNotice( RequestNotice::SEVERITY_ERROR, 'x', true, false ),
			], requiresTargetAccess: false, alwaysAtTop: false ),
		], $status, NoticeCollector::ROLE_HANDLER, true );

		$this->assertFalse( $result->valid );
		$this->assertSame( $expected, $result->status );
	}

	public static function provideDeclines(): array {
		return [
			'pending' => [ RequestStatus::STATUS_PENDING, RequestStatus::STATUS_DECLINED ],
			'inprogress' => [ RequestStatus::STATUS_INPROGRESS, RequestStatus::STATUS_DECLINED ],
			'complete' => [ RequestStatus::STATUS_COMPLETE, RequestStatus::STATUS_COMPLETE ],
		];
	}
}
