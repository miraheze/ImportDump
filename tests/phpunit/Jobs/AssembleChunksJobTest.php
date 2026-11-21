<?php

declare( strict_types = 1 );

namespace Miraheze\ImportDump\Tests\Jobs;

use MediaWiki\FileRepo\LocalRepo;
use MediaWiki\FileRepo\RepoGroup;
use MediaWiki\MainConfigNames;
use MediaWiki\Status\Status;
use MediaWiki\Tests\Api\ApiUploadTestCase;
use MediaWiki\Upload\UploadBase;
use MediaWiki\Upload\UploadStash;
use MediaWiki\User\User;
use MediaWiki\WikiMap\WikiMap;
use Miraheze\ImportDump\ConfigNames;
use Miraheze\ImportDump\Jobs\AssembleChunksJob;
use Wikimedia\FileBackend\FSFileBackend;
use Wikimedia\Mime\MimeAnalyzer;
use function str_repeat;
use function strlen;
use function substr;

/**
 * @group API
 * @group ImportDump
 * @group Database
 * @group medium
 * @covers \Miraheze\ImportDump\Jobs\AssembleChunksJob
 */
class AssembleChunksJobTest extends ApiUploadTestCase {

	private const int CHUNK_SIZE = 1024;

	private User $uploader;

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
			$this->getServiceContainer()->getMainWANObjectCache(),
			$this->createMock( MimeAnalyzer::class )
		) );

		$this->overrideConfigValues( [
			MainConfigNames::VirtualDomainsMapping => [
				'virtual-requestimport' => [ 'db' => WikiMap::getCurrentWikiId() ],
			],
			MainConfigNames::MinUploadChunkSize => self::CHUNK_SIZE,
			MainConfigNames::EnableAsyncUploads => true,
			ConfigNames::EnableChunkedUploads => true,
		] );

		$this->uploader = $this->getTestUser()->getUser();

		// The job reads the session that is exported from this context.
		$this->apiContext->setAuthority( $this->uploader );
	}

	private function getXml( int $length ): string {
		$xml = "<?xml version=\"1.0\"?>\n<mediawiki>";
		while ( strlen( $xml ) < $length ) {
			$xml .= '<page><title>Test</title></page>';
		}

		return $xml . '</mediawiki>';
	}

	/**
	 * Sends every chunk to the API and leaves the upload queued for assembly.
	 */
	private function queueUpload( string $content ): string {
		$size = strlen( $content );
		$filekey = '';

		for ( $offset = 0; $offset < $size; $offset += self::CHUNK_SIZE ) {
			$chunk = substr( $content, $offset, self::CHUNK_SIZE );
			$params = [
				'action' => 'requestimportupload',
				'type' => 'xml',
				'async' => 1,
				'offset' => $offset,
				'filesize' => $size,
			];

			if ( $filekey !== '' ) {
				$params['filekey'] = $filekey;
			}

			$this->fakeUploadChunk( 'chunk', 'blob', 'application/xml', $chunk );
			[ $result ] = $this->doApiRequestWithToken( $params, null, $this->uploader );
			$filekey = $result['requestimportupload']['filekey'];
		}

		$status = UploadBase::getSessionStatus( $this->uploader, $filekey );
		$this->assertSame( 'Poll', $status['result'] );
		$this->assertSame( 'queued', $status['stage'] );

		return $filekey;
	}

	/**
	 * @param string $filekey
	 * @param int $size
	 * @param array<string, mixed> $params
	 * @return AssembleChunksJob
	 */
	private function newJob( string $filekey, int $size, array $params ): AssembleChunksJob {
		return new AssembleChunksJob( $params + [
			'filekey' => $filekey,
			'filesize' => $size,
			'type' => 'xml',
			'session' => $this->apiContext->exportSession(),
		] );
	}

	public function testJobIsRegistered(): void {
		$job = $this->getServiceContainer()->getJobFactory()->newJob(
			AssembleChunksJob::JOB_NAME,
			[ 'filekey' => 'abc.xml', 'filesize' => 1, 'type' => 'xml', 'session' => [] ]
		);

		$this->assertInstanceOf( AssembleChunksJob::class, $job );
		$this->assertSame( AssembleChunksJob::JOB_NAME, $job->getType() );
	}

	public function testRunAssemblesChunks(): void {
		$xml = $this->getXml( self::CHUNK_SIZE * 3 + 100 );
		$filekey = $this->queueUpload( $xml );

		$job = $this->newJob( $filekey, strlen( $xml ), [] );
		$this->assertTrue( $job->run() );
		$this->assertNull( $job->getLastError() );

		$status = UploadBase::getSessionStatus( $this->uploader, $filekey );
		$this->assertSame( 'Success', $status['result'] );
		$this->assertNotSame( $filekey, $status['filekey'] );
		$this->assertTrue( $status['status']->isGood() );

		$repo = $this->getServiceContainer()->getRepoGroup()->getLocalRepo();
		$file = new UploadStash( $repo, $this->uploader )->getFile( $status['filekey'] );
		$this->assertSame( strlen( $xml ), $file->getSize() );
	}

	public function testRunFailsVerification(): void {
		$content = str_repeat( 'not xml at all. ', 200 );
		$filekey = $this->queueUpload( $content );

		$job = $this->newJob( $filekey, strlen( $content ), [] );

		// A file that fails verification is reported to the client and is not a job failure.
		$this->assertTrue( $job->run() );

		$status = UploadBase::getSessionStatus( $this->uploader, $filekey );
		$this->assertSame( 'Failure', $status['result'] );
		$this->assertSame( 'assembling', $status['stage'] );
		$this->assertFalse( $status['status']->isGood() );
	}

	public function testRunFailsWhenStashedFileIsMissing(): void {
		$filekey = 'missing.xml';
		UploadBase::setSessionStatus( $this->uploader, $filekey, [
			'result' => 'Poll',
			'stage' => 'queued',
			'status' => Status::newGood(),
		] );

		$job = $this->newJob( $filekey, 1, [] );
		$this->assertFalse( $job->run() );
		$this->assertStringContainsString( 'UploadStash', $job->getLastError() );

		$status = UploadBase::getSessionStatus( $this->uploader, $filekey );
		$this->assertSame( 'Failure', $status['result'] );
		$this->assertFalse( $status['status']->isGood() );
	}

	public function testRunSkipsUploadAlreadyAssembling(): void {
		$filekey = 'abc.xml';
		UploadBase::setSessionStatus( $this->uploader, $filekey, [
			'result' => 'Poll',
			'stage' => 'assembling',
			'status' => Status::newGood(),
		] );

		$job = $this->newJob( $filekey, 1, [] );
		$this->assertFalse( $job->run() );
		$this->assertStringContainsString( 'already in progress', $job->getLastError() );

		$status = UploadBase::getSessionStatus( $this->uploader, $filekey );
		$this->assertSame( 'Poll', $status['result'] );
		$this->assertSame( 'assembling', $status['stage'] );
	}

	public function testRunRequiresRegisteredUser(): void {
		$this->apiContext->setAuthority(
			$this->getServiceContainer()->getUserFactory()->newAnonymous( '127.0.0.1' )
		);

		$job = $this->newJob( 'abc.xml', 1, [] );
		$this->assertFalse( $job->run() );
		$this->assertStringContainsString( 'author user', $job->getLastError() );
	}

	public function testDeduplicationUsesOnlyFileKey(): void {
		$job = $this->newJob( 'abc.xml', 1, [ 'session' => [ 'userId' => 5 ] ] );
		$other = $this->newJob( 'abc.xml', 2, [ 'session' => [ 'userId' => 6 ] ] );

		$this->assertSame(
			[ 'filekey' => 'abc.xml' ],
			$job->getDeduplicationInfo()['params']
		);

		$this->assertSame( $job->getDeduplicationInfo(), $other->getDeduplicationInfo() );
	}

	public function testRetriesAreNotAllowed(): void {
		$this->assertFalse( $this->newJob( 'abc.xml', 1, [] )->allowRetries() );
	}
}
