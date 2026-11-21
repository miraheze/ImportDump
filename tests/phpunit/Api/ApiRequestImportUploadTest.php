<?php

namespace Miraheze\ImportDump\Tests\Api;

use MediaWiki\FileRepo\LocalRepo;
use MediaWiki\FileRepo\RepoGroup;
use MediaWiki\MainConfigNames;
use MediaWiki\Status\Status;
use MediaWiki\Tests\Api\ApiUploadTestCase;
use MediaWiki\Upload\UploadBase;
use MediaWiki\Upload\UploadStash;
use MediaWiki\WikiMap\WikiMap;
use Miraheze\ImportDump\ConfigNames;
use Miraheze\ImportDump\Jobs\AssembleChunksJob;
use Wikimedia\FileBackend\FSFileBackend;
use Wikimedia\Mime\MimeAnalyzer;

/**
 * @group API
 * @group ImportDump
 * @group Database
 * @group medium
 * @coversDefaultClass \Miraheze\ImportDump\Api\ApiRequestImportUpload
 */
class ApiRequestImportUploadTest extends ApiUploadTestCase {

	private const int CHUNK_SIZE = 1024;

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
				'virtual-importdump' => [ 'db' => WikiMap::getCurrentWikiId() ],
			],
			MainConfigNames::MinUploadChunkSize => self::CHUNK_SIZE,
			ConfigNames::EnableChunkedUploads => true,
		] );
	}

	private function getXml( int $length ): string {
		$xml = "<?xml version=\"1.0\"?>\n<mediawiki>";
		while ( strlen( $xml ) < $length ) {
			$xml .= '<page><title>Test</title></page>';
		}

		return $xml . '</mediawiki>';
	}

	/** @return array{0: array, 1: string} */
	private function sendChunk( string $chunk, array $params ): array {
		$this->fakeUploadChunk( 'chunk', 'blob', 'application/xml', $chunk );

		[ $result ] = $this->doApiRequestWithToken( [
			'action' => 'requestimportupload',
			'type' => 'xml',
		] + $params, null, $this->getTestUser()->getAuthority() );

		$this->assertArrayHasKey( 'requestimportupload', $result );
		return [ $result['requestimportupload'], $result['requestimportupload']['filekey'] ?? '' ];
	}

	/** @return array<string, mixed> */
	private function checkStatus( string $filekey ): array {
		[ $result ] = $this->doApiRequestWithToken( [
			'action' => 'requestimportupload',
			'type' => 'xml',
			'checkstatus' => 1,
			'filekey' => $filekey,
		], null, $this->getTestUser()->getAuthority() );

		$this->assertArrayHasKey( 'requestimportupload', $result );
		return $result['requestimportupload'];
	}

	/**
	 * Upload all chunks but the last one and return the file key.
	 */
	private function uploadAllButLast( string $xml ): string {
		$filekey = '';
		$size = strlen( $xml );
		for ( $offset = 0; $offset + self::CHUNK_SIZE < $size; $offset += self::CHUNK_SIZE ) {
			$params = [ 'offset' => $offset, 'filesize' => $size ];
			if ( $filekey !== '' ) {
				$params['filekey'] = $filekey;
			}

			[ $result, $filekey ] = $this->sendChunk(
				substr( $xml, $offset, self::CHUNK_SIZE ),
				$params
			);

			$this->assertSame( 'Continue', $result['result'] );
			$this->assertSame( $offset + self::CHUNK_SIZE, $result['offset'] );
		}

		return $filekey;
	}

	/**
	 * @covers ::execute
	 * @covers ::assertAvailable
	 */
	public function testDisabled(): void {
		$this->overrideConfigValue( ConfigNames::EnableChunkedUploads, false );

		$this->expectApiErrorCode( 'chunkingdisabled' );
		$this->doApiRequestWithToken( [
			'action' => 'requestimportupload',
			'type' => 'xml',
			'offset' => 0,
			'filesize' => 1,
		], null, $this->getTestUser()->getAuthority() );
	}

	/**
	 * @covers ::assertAvailable
	 */
	public function testPermissionDenied(): void {
		$this->setGroupPermissions( 'user', 'request-import', false );

		$this->expectApiErrorCode( 'permissiondenied' );
		$this->doApiRequestWithToken( [
			'action' => 'requestimportupload',
			'type' => 'xml',
			'offset' => 0,
			'filesize' => 1,
		], null, $this->getTestUser()->getAuthority() );
	}

	/**
	 * @covers ::getAllowedParams
	 */
	public function testInvalidType(): void {
		$chunk = $this->getXml( 10 );
		$this->fakeUploadChunk( 'chunk', 'blob', 'application/xml', $chunk );

		$this->expectApiErrorCode( 'badvalue' );
		$this->doApiRequestWithToken( [
			'action' => 'requestimportupload',
			'type' => 'png',
			'offset' => 0,
			'filesize' => strlen( $chunk ),
		], null, $this->getTestUser()->getAuthority() );
	}

	/**
	 * @covers ::execute
	 */
	public function testMissingChunk(): void {
		$this->expectApiErrorCode( 'invalid-chunk' );
		$this->doApiRequestWithToken( [
			'action' => 'requestimportupload',
			'type' => 'xml',
			'offset' => 0,
			'filesize' => 10,
		], null, $this->getTestUser()->getAuthority() );
	}

	/**
	 * @covers ::execute
	 */
	public function testChunkLargerThanFileSize(): void {
		$this->expectApiErrorCode( 'invalid-chunk' );
		$this->sendChunk( '0123456789', [ 'offset' => 0, 'filesize' => 5 ] );
	}

	/**
	 * @covers ::execute
	 */
	public function testChunkTooSmall(): void {
		$this->expectApiErrorCode( 'chunk-too-small' );
		$this->sendChunk( '01234', [ 'offset' => 0, 'filesize' => 10000 ] );
	}

	/**
	 * @covers ::execute
	 */
	public function testFileKeyNotAllowedOnFirstChunk(): void {
		$this->expectApiErrorCode( 'filekeynotallowed' );
		$this->sendChunk( '01234', [ 'offset' => 0, 'filesize' => 5, 'filekey' => 'abc.xml' ] );
	}

	/**
	 * @covers ::execute
	 */
	public function testFileKeyRequiredAfterFirstChunk(): void {
		$this->expectApiErrorCode( 'filekeyneeded' );
		$this->sendChunk( '01234', [ 'offset' => 5, 'filesize' => 10 ] );
	}

	/**
	 * @covers ::execute
	 * @covers ::finish
	 */
	public function testSingleChunkUpload(): void {
		$xml = $this->getXml( 100 );
		[ $result, $filekey ] = $this->sendChunk( $xml, [ 'offset' => 0, 'filesize' => strlen( $xml ) ] );

		$this->assertSame( 'Success', $result['result'] );
		$this->assertNotSame( '', $filekey );

		$user = $this->getTestUser()->getUser();
		$repo = $this->getServiceContainer()->getRepoGroup()->getLocalRepo();
		$file = new UploadStash( $repo, $user )->getFile( $filekey );
		$this->assertSame( strlen( $xml ), $file->getSize() );
	}

	/**
	 * @covers ::execute
	 * @covers ::finish
	 */
	public function testMultipleChunkUpload(): void {
		$xml = $this->getXml( self::CHUNK_SIZE * 3 + 100 );
		$size = strlen( $xml );
		$firstKey = $this->uploadAllButLast( $xml );

		$offset = (int)( floor( ( $size - 1 ) / self::CHUNK_SIZE ) * self::CHUNK_SIZE );
		[ $result, $filekey ] = $this->sendChunk( substr( $xml, $offset ), [
			'offset' => $offset,
			'filesize' => $size,
			'filekey' => $firstKey,
		] );

		$this->assertSame( 'Success', $result['result'] );
		$this->assertNotSame( $firstKey, $filekey );

		$user = $this->getTestUser()->getUser();
		$repo = $this->getServiceContainer()->getRepoGroup()->getLocalRepo();
		$file = new UploadStash( $repo, $user )->getFile( $filekey );
		$this->assertSame( $size, $file->getSize() );
	}

	/**
	 * @covers ::execute
	 * @covers ::queueAssembly
	 * @covers ::checkStatus
	 */
	public function testAsyncAssembly(): void {
		$this->overrideConfigValue( MainConfigNames::EnableAsyncUploads, true );
		// The job exports the session of the user, so the context needs the same user.
		$this->apiContext->setAuthority( $this->getTestUser()->getAuthority() );

		$xml = $this->getXml( self::CHUNK_SIZE * 3 + 100 );
		$size = strlen( $xml );
		$firstKey = $this->uploadAllButLast( $xml );

		$progress = $this->checkStatus( $firstKey );
		$this->assertSame( 'Continue', $progress['result'] );
		$this->assertSame( 'uploading', $progress['stage'] );

		$offset = (int)( floor( ( $size - 1 ) / self::CHUNK_SIZE ) * self::CHUNK_SIZE );
		[ $result ] = $this->sendChunk( substr( $xml, $offset ), [
			'offset' => $offset,
			'filesize' => $size,
			'filekey' => $firstKey,
			'async' => 1,
		] );

		$this->assertSame( 'Poll', $result['result'] );
		$this->assertSame( 'queued', $result['stage'] );
		$this->assertSame( $firstKey, $result['filekey'] );

		$progress = $this->checkStatus( $firstKey );
		$this->assertSame( 'Poll', $progress['result'] );

		$this->runJobs( [ 'maxJobs' => 1 ], [ 'type' => AssembleChunksJob::JOB_NAME ] );

		$progress = $this->checkStatus( $firstKey );
		$this->assertSame( 'Success', $progress['result'] );
		$this->assertNotSame( $firstKey, $progress['filekey'] );

		$user = $this->getTestUser()->getUser();
		$repo = $this->getServiceContainer()->getRepoGroup()->getLocalRepo();
		$file = new UploadStash( $repo, $user )->getFile( $progress['filekey'] );
		$this->assertSame( $size, $file->getSize() );
	}

	/**
	 * @covers ::execute
	 */
	public function testAsyncIgnoredWhenDisabled(): void {
		$this->overrideConfigValue( MainConfigNames::EnableAsyncUploads, false );

		$xml = $this->getXml( self::CHUNK_SIZE * 2 + 100 );
		$size = strlen( $xml );
		$firstKey = $this->uploadAllButLast( $xml );

		$offset = self::CHUNK_SIZE * 2;
		[ $result ] = $this->sendChunk( substr( $xml, $offset ), [
			'offset' => $offset,
			'filesize' => $size,
			'filekey' => $firstKey,
			'async' => 1,
		] );

		$this->assertSame( 'Success', $result['result'] );
	}

	/**
	 * @covers ::checkStatus
	 */
	public function testCheckStatusMissingSession(): void {
		$this->expectApiErrorCode( 'missingresult' );
		$this->checkStatus( 'abc.xml' );
	}

	/**
	 * @covers ::checkStatus
	 */
	public function testCheckStatusInvalidKey(): void {
		$this->expectApiErrorCode( 'invalid-file-key' );
		$this->checkStatus( 'not a valid key' );
	}

	/**
	 * @covers ::execute
	 */
	public function testMissingOffset(): void {
		$chunk = '01234';
		$this->fakeUploadChunk( 'chunk', 'blob', 'application/xml', $chunk );

		$this->expectApiErrorCode( 'nooffset' );
		$this->doApiRequestWithToken( [
			'action' => 'requestimportupload',
			'type' => 'xml',
			'filesize' => 5,
		], null, $this->getTestUser()->getAuthority() );
	}

	/**
	 * @covers ::execute
	 * @covers ::getAllowedParams
	 */
	public function testIgnoresCoreMaxUploadSize(): void {
		$this->overrideConfigValue( MainConfigNames::MaxUploadSize, 10 );

		$xml = $this->getXml( self::CHUNK_SIZE * 3 + 100 );
		$size = strlen( $xml );
		$firstKey = $this->uploadAllButLast( $xml );

		$offset = (int)( floor( ( $size - 1 ) / self::CHUNK_SIZE ) * self::CHUNK_SIZE );
		[ $result ] = $this->sendChunk( substr( $xml, $offset ), [
			'offset' => $offset,
			'filesize' => $size,
			'filekey' => $firstKey,
		] );

		$this->assertSame( 'Success', $result['result'] );
	}

	/**
	 * @covers ::getAllowedParams
	 */
	public function testMaxUploadSizeLimit(): void {
		$this->overrideConfigValue( ConfigNames::MaxUploadSize, 2000 );

		$this->expectApiErrorCode( 'outofrange' );
		$this->sendChunk( $this->getXml( self::CHUNK_SIZE ), [
			'offset' => 0,
			'filesize' => 5000,
		] );
	}

	/**
	 * @covers ::execute
	 */
	public function testChunkRejectedAfterCompletion(): void {
		$xml = $this->getXml( self::CHUNK_SIZE * 2 + 100 );
		$size = strlen( $xml );
		$firstKey = $this->uploadAllButLast( $xml );

		$offset = self::CHUNK_SIZE * 2;
		$this->sendChunk( substr( $xml, $offset ), [
			'offset' => $offset,
			'filesize' => $size,
			'filekey' => $firstKey,
		] );

		$this->expectApiErrorCode( 'stashfailed' );
		$this->sendChunk( substr( $xml, $offset ), [
			'offset' => $offset,
			'filesize' => $size,
			'filekey' => $firstKey,
		] );
	}

	/**
	 * @covers ::execute
	 */
	public function testTypeMismatchAfterFirstChunk(): void {
		$xml = $this->getXml( self::CHUNK_SIZE * 2 + 100 );
		$size = strlen( $xml );
		$filekey = $this->uploadAllButLast( $xml );

		$user = $this->getTestUser()->getUser();
		UploadBase::setSessionStatus( $user, $filekey, [
			'result' => 'Continue',
			'stage' => 'uploading',
			'type' => 'other',
			'offset' => self::CHUNK_SIZE,
			'status' => Status::newGood(),
		] );

		$this->expectApiErrorCode( 'typemismatch' );
		$this->sendChunk( substr( $xml, self::CHUNK_SIZE, self::CHUNK_SIZE ), [
			'offset' => self::CHUNK_SIZE,
			'filesize' => $size,
			'filekey' => $filekey,
		] );
	}

	/**
	 * @covers ::execute
	 * @covers ::finish
	 */
	public function testMimeMismatchOnFinalChunk(): void {
		$this->expectApiErrorCode( 'verification-error' );
		$this->sendChunk( 'content', [ 'offset' => 0, 'filesize' => 7 ] );
	}
}
