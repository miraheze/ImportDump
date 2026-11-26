<?php

declare( strict_types = 1 );

namespace Miraheze\ImportDump\Tests\Notices;

use MediaWiki\Context\RequestContext;
use MediaWikiIntegrationTestCase;
use Miraheze\ImportDump\Notices\NoticeSeverity;
use Miraheze\ImportDump\Notices\RequestNotice;
use Miraheze\ImportDump\Notices\RequirementNotices;
use Miraheze\ImportDump\Request\RequestManager;
use Miraheze\ImportDump\Request\RequestStatus;
use Miraheze\ImportDump\Requirements\RequirementProblem;
use Miraheze\ImportDump\Requirements\RequirementProblemKind;

/**
 * @covers \Miraheze\ImportDump\Notices\RequirementNotices
 * @group ImportDump
 * @group medium
 */
class RequirementNoticesTest extends MediaWikiIntegrationTestCase {

	/** @return RequestNotice[] */
	private function getNotices( array $problems, RequestStatus $status ): array {
		$requestManager = $this->createMock( RequestManager::class );
		$requestManager->method( 'getRequirementProblems' )->willReturn( $problems );

		$extensionNames = $this->getServiceContainer()->get( 'RequestImport.ExtensionNames' );

		return new RequirementNotices( $extensionNames )->getNotices(
			$requestManager,
			RequestContext::getMain(),
			$status
		);
	}

	private static function problem(
		string $name,
		RequirementProblemKind $kind,
		string $setting,
		bool $blocking
	): RequirementProblem {
		return new RequirementProblem( $name, $kind, $setting, $blocking );
	}

	public function testOneNoticePerKindOfProblem(): void {
		$notices = $this->getNotices( [
			self::problem( 'PortableInfobox', RequirementProblemKind::Missing, '', false ),
			self::problem( 'TemplateStyles', RequirementProblemKind::Missing, '', true ),
			self::problem( 'WikibaseRepository', RequirementProblemKind::Setting, 'allowEntityImport', true ),
			self::problem( 'WikibaseLexeme', RequirementProblemKind::Unknown, '', false ),
		], RequestStatus::Pending );

		$this->assertCount( 3, $notices );

		$this->assertSame( NoticeSeverity::Error, $notices[0]->severity );
		$this->assertTrue( $notices[0]->invalidates );
		$this->assertStringContainsString( '<bdi>PortableInfobox</bdi>', $notices[0]->html );
		$this->assertStringContainsString( 'TemplateStyles', $notices[0]->html );

		$this->assertTrue( $notices[1]->invalidates );
		$this->assertStringContainsString( 'allowEntityImport', $notices[1]->html );
		$this->assertStringContainsString( 'WikibaseRepository', $notices[1]->html );

		$this->assertSame( NoticeSeverity::Warning, $notices[2]->severity );
		$this->assertFalse( $notices[2]->invalidates );
	}

	public function testUncertainProblemsDoNotInvalidate(): void {
		$notices = $this->getNotices( [
			self::problem( 'PortableInfobox', RequirementProblemKind::Missing, '', false ),
		], RequestStatus::Failed );

		$this->assertCount( 1, $notices );
		$this->assertFalse( $notices[0]->invalidates );
	}

	public function testNothingWhenTheRequestIsNotOpen(): void {
		$this->assertSame( [], $this->getNotices( [
			self::problem( 'PortableInfobox', RequirementProblemKind::Missing, '', true ),
		], RequestStatus::Complete ) );
	}
}
