<?php

declare( strict_types = 1 );

namespace Miraheze\ImportDump\Tests\Jobs;

use MediaWiki\FileRepo\LocalRepo;
use MediaWiki\FileRepo\RepoGroup;
use MediaWiki\MainConfigNames;
use MediaWiki\WikiMap\WikiMap;
use MediaWikiIntegrationTestCase;
use Miraheze\ImportDump\Jobs\ImportOutputStoreJob;
use Miraheze\ImportDump\Request\RequestManager;
use Miraheze\ImportDump\Request\RequestStatus;
use Miraheze\ImportDump\Request\RequestType;
use Wikimedia\FileBackend\FSFileBackend;
use Wikimedia\Mime\MimeAnalyzer;
use Wikimedia\ObjectCache\WANObjectCache;
use Wikimedia\Timestamp\ConvertibleTimestamp;

/**
 * @group ImportDump
 * @group Database
 * @group medium
 * @covers \Miraheze\ImportDump\Jobs\ImportOutputStoreJob
 */
class ImportOutputStoreJobTest extends MediaWikiIntegrationTestCase {

	protected function setUp(): void {
		parent::setUp();

		$this->setService( 'RepoGroup', new RepoGroup(
			[
				'class' => LocalRepo::class,
				'name' => 'temp',
				'backend' => new FSFileBackend( [
					'name' => 'temp-backend',
					'wikiId' => WikiMap::getCurrentWikiId(),
					'basePath' => $this->getNewTempDirectory(),
				] ),
			],
			[],
			WANObjectCache::newEmpty(),
			$this->createMock( MimeAnalyzer::class )
		) );

		$this->overrideConfigValue( MainConfigNames::VirtualDomainsMapping, [
			'virtual-requestimport' => [ 'db' => WikiMap::getCurrentWikiId() ],
		] );
	}

	private function addRequest(): int {
		$dbw = $this->getDb();
		$dbw->newInsertQueryBuilder()
			->insertInto( 'import_requests' )
			->row( [
				'request_source' => 'https://requestimporttest.com',
				'request_target' => WikiMap::getCurrentWikiId(),
				'request_reason' => 'test',
				'request_status' => RequestStatus::Complete->value,
				'request_actor' => $this->getTestUser()->getUser()->getActorId(),
				'request_timestamp' => $dbw->timestamp( ConvertibleTimestamp::now() ),
				'request_type' => RequestType::Images->value,
			] )
			->caller( __METHOD__ )
			->execute();

		return $dbw->insertId();
	}

	private function getRequestManager( int $id ): RequestManager {
		$manager = $this->getServiceContainer()->getService( 'RequestImport.RequestManager' );
		'@phan-var RequestManager $manager';
		$manager->loadFromID( $id );
		return $manager;
	}

	private function newJob( int $requestID, int $part, string $text ): ImportOutputStoreJob {
		$job = $this->getServiceContainer()->getJobFactory()->newJob( ImportOutputStoreJob::JOB_NAME, [
			'part' => $part,
			'requestid' => $requestID,
			'text' => $text,
		] );

		$this->assertInstanceOf( ImportOutputStoreJob::class, $job );
		return $job;
	}

	public function testStoresThePart(): void {
		$id = $this->addRequest();

		$this->assertTrue( $this->newJob( $id, 2, "a\nb\n" )->run() );

		$manager = $this->getRequestManager( $id );
		$this->assertSame( [ [ 'part' => 2, 'lines' => 2 ] ], $manager->getOutputParts() );
		$this->assertSame( "a\nb\n", $manager->getOutputPart( 2 ) );
	}

	public function testStoringAgainReplacesThePart(): void {
		$id = $this->addRequest();

		$this->assertTrue( $this->newJob( $id, 0, 'a' )->run() );
		$this->assertTrue( $this->newJob( $id, 0, 'b' )->run() );

		$this->assertSame( 'b', $this->getRequestManager( $id )->getOutputPart( 0 ) );
	}

	public function testStoringAgainWithAnotherLengthLeavesOneFile(): void {
		$id = $this->addRequest();

		$this->assertTrue( $this->newJob( $id, 0, "a\nb\nc\n" )->run() );
		$this->assertTrue( $this->newJob( $id, 0, 'b' )->run() );

		$manager = $this->getRequestManager( $id );
		$this->assertSame( [ [ 'part' => 0, 'lines' => 1 ] ], $manager->getOutputParts() );
		$this->assertSame( 'b', $manager->getOutputPart( 0 ) );
	}

	public function testResetRemovesTheStoredParts(): void {
		$id = $this->addRequest();

		$this->assertTrue( $this->newJob( $id, 0, 'a' )->run() );
		$this->assertTrue( $this->newJob( $id, 1, 'b' )->run() );

		$job = $this->getServiceContainer()->getJobFactory()->newJob( ImportOutputStoreJob::JOB_NAME, [
			'requestid' => $id,
			'reset' => true,
		] );
		$this->assertTrue( $job->run() );

		$this->assertSame( [], $this->getRequestManager( $id )->getOutputParts() );
	}

	public function testUnknownRequestIsIgnored(): void {
		$this->assertTrue( $this->newJob( 12345, 0, 'a' )->run() );
	}
}
