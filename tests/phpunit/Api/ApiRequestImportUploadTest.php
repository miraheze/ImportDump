<?php

declare( strict_types = 1 );

namespace Miraheze\ImportDump\Tests\Api;

use MediaWiki\FileRepo\LocalRepo;
use MediaWiki\FileRepo\RepoGroup;
use MediaWiki\MainConfigNames;
use MediaWiki\Request\FauxRequest;
use MediaWiki\Request\WebRequestUpload;
use MediaWiki\Status\Status;
use MediaWiki\Tests\Api\ApiUploadTestCase;
use MediaWiki\Upload\Exception\UploadStashFileNotFoundException;
use MediaWiki\Upload\UploadBase;
use MediaWiki\Upload\UploadStash;
use MediaWiki\WikiMap\WikiMap;
use Miraheze\ImportDump\Api\ApiRequestImportUpload;
use Miraheze\ImportDump\ConfigNames;
use Miraheze\ImportDump\Tests\Upload\ZipFixture;
use Miraheze\ImportDump\Upload\RequestImportUploadFromChunks;
use Wikimedia\FileBackend\FSFile\TempFSFile;
use Wikimedia\FileBackend\FSFileBackend;
use Wikimedia\Mime\MimeAnalyzer;
use Wikimedia\ObjectCache\WANObjectCache;
use function file_get_contents;
use function floor;
use function random_bytes;
use function strlen;
use function substr;

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
			WANObjectCache::newEmpty(),
			$this->createMock( MimeAnalyzer::class )
		) );

		$this->overrideConfigValues( [
			MainConfigNames::VirtualDomainsMapping => [
				'virtual-requestimport' => [ 'db' => WikiMap::getCurrentWikiId() ],
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

		[ $result ] = $this->doApiRequestWithToken( $params + [
			'action' => 'requestimportupload',
			'type' => 'xml',
		], null, $this->getTestUser()->getAuthority() );

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
	 * @covers ::finishParts
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
	 * @covers ::finishParts
	 */
	public function testImagesArchiveUpload(): void {
		$zip = ZipFixture::build( [ 'a.png' => 'image' ] );
		[ $result, $filekey ] = $this->sendChunk( $zip, [
			'offset' => 0,
			'filesize' => strlen( $zip ),
			'type' => 'images',
		] );

		$this->assertSame( 'Success', $result['result'] );

		$user = $this->getTestUser()->getUser();
		$repo = $this->getServiceContainer()->getRepoGroup()->getLocalRepo();
		$file = new UploadStash( $repo, $user )->getFile( $filekey );
		$this->assertSame( strlen( $zip ), $file->getSize() );
	}

	/**
	 * @covers ::execute
	 * @covers ::finishParts
	 */
	public function testImagesUploadThatIsNotAnArchive(): void {
		$this->expectApiErrorCode( 'verification-error' );
		$this->sendChunk( $this->getXml( 100 ), [
			'offset' => 0,
			'filesize' => strlen( $this->getXml( 100 ) ),
			'type' => 'images',
		] );
	}

	/**
	 * @covers ::execute
	 * @covers ::finishParts
	 */
	public function testImagesAreKeptInParts(): void {
		$archive = ZipFixture::build( [ 'a.bin' => random_bytes( self::CHUNK_SIZE * 2 + 100 ) ] );
		$size = strlen( $archive );

		$firstKey = '';
		$result = [];
		for ( $offset = 0; $offset < $size; $offset += self::CHUNK_SIZE ) {
			$params = [ 'offset' => $offset, 'filesize' => $size, 'type' => 'images' ];
			if ( $firstKey !== '' ) {
				$params['filekey'] = $firstKey;
			}

			[ $result, $key ] = $this->sendChunk( substr( $archive, $offset, self::CHUNK_SIZE ), $params );
			$firstKey = $firstKey === '' ? $key : $firstKey;
		}

		$this->assertSame( 'Success', $result['result'] );
		$this->assertSame( $firstKey, $result['filekey'] );

		$progress = UploadBase::getSessionStatus( $this->getTestUser()->getUser(), $firstKey );
		$this->assertSame( ApiRequestImportUpload::STAGE_PARTS, $progress['stage'] );

		$user = $this->getTestUser()->getUser();
		$upload = new RequestImportUploadFromChunks( $user, 'images' );
		$upload->continueChunks(
			$upload->getStashName(),
			$firstKey,
			new WebRequestUpload( new FauxRequest(), 'null' )
		);

		$joined = $upload->joinChunks( null );
		$this->assertStatusGood( $joined );
		$file = $joined->getValue();
		'@phan-var TempFSFile $file';
		$this->assertSame( $archive, file_get_contents( $file->getPath() ) );

		$upload->purgeChunks();

		$repo = $this->getServiceContainer()->getRepoGroup()->getLocalRepo();
		$this->expectException( UploadStashFileNotFoundException::class );
		new UploadStash( $repo, $user )->getFile( $firstKey );
	}

	/**
	 * @covers ::execute
	 * @covers ::finishParts
	 */
	public function testMultipleChunkUploadIsKeptInParts(): void {
		$xml = $this->getXml( self::CHUNK_SIZE * 3 + 100 );
		$size = strlen( $xml );
		$firstKey = $this->uploadAllButLast( $xml );

		$offset = (int)( floor( ( $size - 1 ) / self::CHUNK_SIZE ) * self::CHUNK_SIZE );
		[ $result, $filekey ] = $this->sendChunk( substr( $xml, $offset ), [
			'offset' => $offset,
			'filesize' => $size,
			'filekey' => $firstKey,
		] );

		// The key of the first part stands for all of them, and nothing is joined.
		$this->assertSame( 'Success', $result['result'] );
		$this->assertSame( $firstKey, $filekey );

		$progress = $this->checkStatus( $firstKey );
		$this->assertSame( 'Success', $progress['result'] );
		$this->assertSame( ApiRequestImportUpload::STAGE_PARTS, $progress['stage'] );
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
	 * @covers ::finishParts
	 */
	public function testMimeMismatchOnFinalChunk(): void {
		$this->expectApiErrorCode( 'verification-error' );
		$this->sendChunk( 'content', [ 'offset' => 0, 'filesize' => 7 ] );
	}
}
