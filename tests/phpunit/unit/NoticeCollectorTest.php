<?php

namespace Miraheze\ImportDump\Tests\Unit;

use MediaWiki\Context\IContextSource;
use MediaWikiUnitTestCase;
use Miraheze\ImportDump\Notices\NoticeCollector;
use Miraheze\ImportDump\Notices\NoticeProvider;
use Miraheze\ImportDump\Notices\RequestNotice;
use Miraheze\ImportDump\RequestManager;
use Miraheze\ImportDump\RequestStatus;

/**
 * @covers \Miraheze\ImportDump\Notices\NoticeCollector
 * @covers \Miraheze\ImportDump\Notices\RequestNotice
 */
class NoticeCollectorTest extends MediaWikiUnitTestCase {

	private function newProvider(
		string $audience,
		array $notices,
		bool $requiresTargetAccess
	): NoticeProvider {
		$provider = $this->createMock( NoticeProvider::class );
		$provider->method( 'getAudience' )->willReturn( $audience );
		$provider->method( 'requiresTargetAccess' )->willReturn( $requiresTargetAccess );
		$provider->method( 'getNotices' )->willReturn( $notices );
		return $provider;
	}

	private function collect(
		array $providers,
		string $status,
		bool $forHandler,
		bool $hasTargetAccess
	) {
		return ( new NoticeCollector( $providers ) )->collect(
			$this->createMock( RequestManager::class ),
			$this->createMock( IContextSource::class ),
			$status,
			$forHandler,
			$hasTargetAccess
		);
	}

	public function testSortsBySeverity(): void {
		$result = $this->collect( [
			$this->newProvider( NoticeProvider::AUDIENCE_HANDLERS, [
				new RequestNotice( RequestNotice::SEVERITY_NOTICE, 'a', false, false ),
				new RequestNotice( RequestNotice::SEVERITY_ERROR, 'b', false, false ),
			], requiresTargetAccess: false ),
			$this->newProvider( NoticeProvider::AUDIENCE_PARTIES, [
				new RequestNotice( RequestNotice::SEVERITY_WARNING, 'c', false, false ),
			], requiresTargetAccess: false ),
		], RequestStatus::STATUS_PENDING, true, true );

		$this->assertSame(
			[ 'b', 'c', 'a' ],
			array_map( static fn ( RequestNotice $n ): string => $n->html, $result->notices )
		);
		$this->assertTrue( $result->valid );
	}

	public function testRequestersOnlySeeEveryoneNotices(): void {
		$result = $this->collect( [
			$this->newProvider( NoticeProvider::AUDIENCE_HANDLERS, [
				new RequestNotice( RequestNotice::SEVERITY_ERROR, 'handler', true, true ),
			], requiresTargetAccess: false ),
			$this->newProvider( NoticeProvider::AUDIENCE_PARTIES, [
				new RequestNotice( RequestNotice::SEVERITY_WARNING, 'shared', false, false ),
			], requiresTargetAccess: false ),
		], RequestStatus::STATUS_PENDING, false, true );

		$this->assertCount( 1, $result->notices );
		$this->assertSame( 'shared', $result->notices[0]->html );
		$this->assertSame( RequestStatus::STATUS_PENDING, $result->status );
	}

	/** @dataProvider provideTargetAccess */
	public function testTargetNoticesNeedAccess( bool $hasTargetAccess, bool $forHandler, int $expected ): void {
		$result = $this->collect( [
			$this->newProvider( NoticeProvider::AUDIENCE_PARTIES, [
				new RequestNotice( RequestNotice::SEVERITY_ERROR, 'remote', true, false ),
			], requiresTargetAccess: true ),
			$this->newProvider( NoticeProvider::AUDIENCE_PARTIES, [
				new RequestNotice( RequestNotice::SEVERITY_NOTICE, 'local', false, false ),
			], requiresTargetAccess: false ),
		], RequestStatus::STATUS_PENDING, $forHandler, $hasTargetAccess );

		$this->assertCount( $expected, $result->notices );
	}

	public static function provideTargetAccess(): array {
		return [
			'requester with access' => [ true, false, 2 ],
			'requester without access' => [ false, false, 1 ],
			'handler' => [ true, true, 2 ],
		];
	}

	/** @dataProvider provideDeclines */
	public function testDeclines( string $status, string $expected ): void {
		$result = $this->collect( [
			$this->newProvider( NoticeProvider::AUDIENCE_HANDLERS, [
				new RequestNotice( RequestNotice::SEVERITY_ERROR, 'x', true, true ),
			], requiresTargetAccess: false ),
		], $status, true, true );

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
