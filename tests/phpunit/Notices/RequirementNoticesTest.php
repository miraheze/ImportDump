<?php

namespace Miraheze\ImportDump\Tests\Notices;

use MediaWiki\Context\RequestContext;
use MediaWikiIntegrationTestCase;
use Miraheze\ImportDump\Notices\RequestNotice;
use Miraheze\ImportDump\Notices\RequirementNotices;
use Miraheze\ImportDump\RequestManager;
use Miraheze\ImportDump\RequestStatus;

/**
 * @covers \Miraheze\ImportDump\Notices\RequirementNotices
 * @group ImportDump
 * @group medium
 */
class RequirementNoticesTest extends MediaWikiIntegrationTestCase {

	/** @return RequestNotice[] */
	private function getNotices( array $problems, string $status ): array {
		$requestManager = $this->createMock( RequestManager::class );
		$requestManager->method( 'getRequirementProblems' )->willReturn( $problems );

		return ( new RequirementNotices() )->getNotices(
			$requestManager,
			RequestContext::getMain(),
			$status
		);
	}

	private static function problem( string $name, string $problem, string $setting, bool $blocking ): array {
		return [ 'name' => $name, 'problem' => $problem, 'setting' => $setting, 'blocking' => $blocking ];
	}

	public function testOneNoticePerKindOfProblem(): void {
		$notices = $this->getNotices( [
			self::problem( 'PortableInfobox', 'missing', '', false ),
			self::problem( 'TemplateStyles', 'missing', '', true ),
			self::problem( 'WikibaseRepository', 'setting', 'allowEntityImport', true ),
			self::problem( 'WikibaseLexeme', 'unknown', '', false ),
		], RequestStatus::STATUS_PENDING );

		$this->assertCount( 3, $notices );

		$this->assertSame( RequestNotice::SEVERITY_ERROR, $notices[0]->severity );
		$this->assertTrue( $notices[0]->invalidates );
		$this->assertStringContainsString( 'PortableInfobox', $notices[0]->html );
		$this->assertStringContainsString( 'TemplateStyles', $notices[0]->html );

		$this->assertTrue( $notices[1]->invalidates );
		$this->assertStringContainsString( 'allowEntityImport', $notices[1]->html );

		$this->assertSame( RequestNotice::SEVERITY_WARNING, $notices[2]->severity );
		$this->assertFalse( $notices[2]->invalidates );
	}

	public function testUncertainProblemsDoNotInvalidate(): void {
		$notices = $this->getNotices( [
			self::problem( 'PortableInfobox', 'missing', '', false ),
		], RequestStatus::STATUS_FAILED );

		$this->assertCount( 1, $notices );
		$this->assertFalse( $notices[0]->invalidates );
	}

	public function testNothingWhenTheRequestIsNotOpen(): void {
		$this->assertSame( [], $this->getNotices( [
			self::problem( 'PortableInfobox', 'missing', '', true ),
		], RequestStatus::STATUS_COMPLETE ) );
	}
}
