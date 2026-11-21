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

	private function newProvider( string $audience, array $notices ): NoticeProvider {
		$provider = $this->createMock( NoticeProvider::class );
		$provider->method( 'getAudience' )->willReturn( $audience );
		$provider->method( 'getNotices' )->willReturn( $notices );
		return $provider;
	}

	private function collect( array $providers, string $status, bool $forHandler ) {
		return ( new NoticeCollector( $providers ) )->collect(
			$this->createMock( RequestManager::class ),
			$this->createMock( IContextSource::class ),
			$status,
			$forHandler
		);
	}

	public function testSortsBySeverity(): void {
		$result = $this->collect( [
			$this->newProvider( NoticeProvider::AUDIENCE_HANDLERS, [
				new RequestNotice( RequestNotice::SEVERITY_NOTICE, 'a', false, false ),
				new RequestNotice( RequestNotice::SEVERITY_ERROR, 'b', false, false ),
			] ),
			$this->newProvider( NoticeProvider::AUDIENCE_EVERYONE, [
				new RequestNotice( RequestNotice::SEVERITY_WARNING, 'c', false, false ),
			] ),
		], RequestStatus::STATUS_PENDING, true );

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
			] ),
			$this->newProvider( NoticeProvider::AUDIENCE_EVERYONE, [
				new RequestNotice( RequestNotice::SEVERITY_WARNING, 'shared', false, false ),
			] ),
		], RequestStatus::STATUS_PENDING, false );

		$this->assertCount( 1, $result->notices );
		$this->assertSame( 'shared', $result->notices[0]->html );
		$this->assertSame( RequestStatus::STATUS_PENDING, $result->status );
	}

	/** @dataProvider provideDeclines */
	public function testDeclines( string $status, string $expected ): void {
		$result = $this->collect( [
			$this->newProvider( NoticeProvider::AUDIENCE_HANDLERS, [
				new RequestNotice( RequestNotice::SEVERITY_ERROR, 'x', true, true ),
			] ),
		], $status, true );

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
