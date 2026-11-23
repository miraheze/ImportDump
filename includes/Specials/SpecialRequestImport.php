<?php

declare( strict_types = 1 );

namespace Miraheze\ImportDump\Specials;

use MediaWiki\Api\ApiUpload;
use MediaWiki\Exception\ErrorPageError;
use MediaWiki\Exception\PermissionsError;
use MediaWiki\Exception\UserBlockedError;
use MediaWiki\FileRepo\FileRepo;
use MediaWiki\FileRepo\RepoGroup;
use MediaWiki\Html\Html;
use MediaWiki\JobQueue\JobQueueGroup;
use MediaWiki\JobQueue\JobSpecification;
use MediaWiki\Logging\ManualLogEntry;
use MediaWiki\MainConfigNames;
use MediaWiki\Message\Message;
use MediaWiki\Notification\NotificationService;
use MediaWiki\Notification\RecipientSet;
use MediaWiki\SpecialPage\FormSpecialPage;
use MediaWiki\SpecialPage\SpecialPage;
use MediaWiki\Status\Status;
use MediaWiki\Upload\Exception\UploadStashException;
use MediaWiki\Upload\UploadBase;
use MediaWiki\Upload\UploadFromFile;
use MediaWiki\Upload\UploadFromStash;
use MediaWiki\Upload\UploadFromUrl;
use MediaWiki\Upload\UploadStash;
use MediaWiki\Upload\UploadStashFile;
use MediaWiki\Upload\UploadVerification;
use MediaWiki\User\User;
use MediaWiki\User\UserFactory;
use MediaWiki\User\UserIdentity;
use MediaWiki\WikiMap\WikiMap;
use Miraheze\ImportDump\ConfigNames;
use Miraheze\ImportDump\Jobs\VerifyDumpJob;
use Miraheze\ImportDump\Notifications\AttentionNotifier;
use Miraheze\ImportDump\Notifications\RequestNotification;
use Miraheze\ImportDump\Request\RequestManager;
use Miraheze\ImportDump\Request\RequestStatus;
use Miraheze\ImportDump\Request\RequestType;
use Miraheze\ImportDump\Upload\ArchiveReader;
use Miraheze\ImportDump\Upload\DumpProcessor;
use Miraheze\ImportDump\Upload\DumpResult;
use Miraheze\ImportDump\Upload\RequestImportUploadFromChunks;
use Miraheze\ManageWiki\Helpers\Factories\ModuleFactory;
use Wikimedia\Mime\MimeAnalyzer;
use Wikimedia\Rdbms\IConnectionProvider;
use Wikimedia\Rdbms\Platform\ISQLPlatform;
use function array_filter;
use function array_map;
use function array_push;
use function array_values;
use function ctype_space;
use function get_class;
use function in_array;
use function max;
use function min;
use const PHP_INT_MAX;
use const UPLOAD_ERR_NO_FILE;

class SpecialRequestImport extends FormSpecialPage {

	/** What the archive formats are called in the help text. */
	private const array FORMAT_NAMES = [
		ArchiveReader::FORMAT_ZIP => 'ZIP',
		ArchiveReader::FORMAT_TAR => 'TAR',
		ArchiveReader::FORMAT_GZIP => 'GZIP',
		ArchiveReader::FORMAT_BZIP2 => 'BZIP2',
		ArchiveReader::FORMAT_XZ => 'XZ',
		ArchiveReader::FORMAT_SEVEN_ZIP => '7z',
	];

	/** The extensions that a TAR has when it is compressed with a format. */
	private const array COMPRESSED_TAR_EXTENSIONS = [
		ArchiveReader::FORMAT_GZIP => [ '.tar.gz', '.tgz' ],
		ArchiveReader::FORMAT_BZIP2 => [ '.tar.bz2' ],
		ArchiveReader::FORMAT_XZ => [ '.tar.xz', '.txz' ],
	];

	public function __construct(
		private readonly IConnectionProvider $connectionProvider,
		private readonly AttentionNotifier $attentionNotifier,
		private readonly DumpProcessor $dumpProcessor,
		private readonly RequestManager $requestManager,
		private readonly JobQueueGroup $jobQueueGroup,
		private readonly MimeAnalyzer $mimeAnalyzer,
		private readonly NotificationService $notificationService,
		private readonly RepoGroup $repoGroup,
		private readonly UploadVerification $uploadVerification,
		private readonly UserFactory $userFactory,
		private readonly ?ModuleFactory $moduleFactory,
	) {
		parent::__construct( 'RequestImport' );
	}

	/**
	 * @param ?string $par
	 * @throws ErrorPageError
	 */
	public function execute( $par ): void {
		$this->requireLogin( 'importdump-notloggedin' );
		$this->setParameter( $par );
		$this->setHeaders();

		$dbr = $this->connectionProvider->getReplicaDatabase( RequestManager::VIRTUAL_DOMAIN );
		if ( !WikiMap::isCurrentWikiDbDomain( $dbr->getDomainID() ) ) {
			throw new ErrorPageError( 'importdump-notcentral', 'importdump-notcentral-text' );
		}

		$this->checkPermissions();

		if ( $this->getConfig()->get( ConfigNames::HelpUrl ) ) {
			$this->getOutput()->addHelpLink( $this->getConfig()->get( ConfigNames::HelpUrl ), true );
		}

		if ( $this->useChunkedUploads() ) {
			$this->addChunkedUploadModule();
		}

		$form = $this->getForm();
		if ( $form->show() ) {
			$this->onSuccess();
		}
	}

	/** @inheritDoc */
	protected function getFormFields(): array {
		$formDescriptor = [
			'source' => [
				'type' => 'url',
				'label-message' => 'importdump-label-source',
				'help-message' => 'importdump-help-source',
				'required' => true,
			],
			'target' => [
				'type' => 'text',
				'label-message' => 'importdump-label-target',
				'help-message' => 'importdump-help-target',
				'required' => true,
				'validation-callback' => [ $this, 'isValidDatabase' ],
			],
		];

		if ( $this->getConfig()->get( ConfigNames::EnableImageImports ) ) {
			$formDescriptor['UploadEither'] = [
				'type' => 'info',
				'default' => $this->msg( 'importdump-help-upload-either' )->text(),
			];
		}

		if (
			UploadFromUrl::isEnabled() &&
			UploadFromUrl::isAllowed( $this->getUser() ) === true
		) {
			$formDescriptor += [
				'UploadSourceType' => [
					'type' => 'radio',
					'label-message' => 'importdump-label-upload-source-type',
					'default' => 'File',
					'options-messages' => [
						'importdump-label-upload-source-file' => 'File',
						'importdump-label-upload-source-url' => 'Url',
					],
				],
				'UploadFile' => [
					'type' => 'file',
					'label-message' => 'importdump-label-upload-file',
					'help-messages' => $this->getUploadHelp(),
					'hide-if' => [ '!==', 'wpUploadSourceType', 'File' ],
					'accept' => $this->dumpProcessor->getAcceptedMimeTypes(),
				],
				'UploadFileURL' => [
					'type' => 'url',
					'label-message' => 'importdump-label-upload-file-url',
					'help-message' => 'importdump-help-upload-file-url',
					'hide-if' => [ '!==', 'wpUploadSourceType', 'Url' ],
					'required' => true,
				],
			];
		} else {
			$formDescriptor += [
				'UploadFile' => [
					'type' => 'file',
					'label-message' => 'importdump-label-upload-file',
					'help-messages' => $this->getUploadHelp(),
					'accept' => $this->dumpProcessor->getAcceptedMimeTypes(),
				],
			];
		}

		if ( $this->getConfig()->get( ConfigNames::EnableImageImports ) ) {
			$formDescriptor['UploadImages'] = [
				'type' => 'file',
				'label-message' => 'importdump-label-upload-images',
				'help-message' => 'importdump-help-upload-images',
				'accept' => $this->dumpProcessor->getArchiveMimeTypes(),
			];
		}

		if ( $this->useChunkedUploads() ) {
			$formDescriptor['UploadFileKey'] = [
				'type' => 'hidden',
				'default' => '',
			];
		}

		$formDescriptor += [
			'reason' => [
				'type' => 'textarea',
				'rows' => 6,
				'label-message' => 'importdump-label-reason',
				'help-message' => 'importdump-help-reason',
				'required' => true,
				'validation-callback' => [ $this, 'isValidReason' ],
			],
		];

		return $formDescriptor;
	}

	/**
	 * @inheritDoc
	 * @throws PermissionsError
	 */
	public function onSubmit( array $data ): Status {
		$token = $this->getRequest()->getVal( 'wpEditToken' );
		$userToken = $this->getContext()->getCsrfTokenSet();
		if ( !$userToken->matchToken( $token ) ) {
			return Status::newFatal( 'sessionfailure' );
		}

		if (
			$this->getUser()->pingLimiter( 'request-import' ) ||
			$this->getUser()->pingLimiter( 'upload' )
		) {
			return Status::newFatal( 'actionthrottledtext' );
		}

		$dbw = $this->connectionProvider->getPrimaryDatabase( RequestManager::VIRTUAL_DOMAIN );
		$duplicate = $dbw->newSelectQueryBuilder()
			->select( ISQLPlatform::ALL_ROWS )
			->from( 'import_requests' )
			->where( [
				'request_reason' => $data['reason'],
				'request_status' => RequestStatus::Pending->value,
			] )
			->caller( __METHOD__ )
			->fetchRow();

		if ( (bool)$duplicate ) {
			return Status::newFatal( 'importdump-duplicate-request' );
		}

		$timestamp = $dbw->timestamp();

		$images = $this->getImagesUpload();
		if ( $images instanceof Status ) {
			return $images;
		}

		if ( $this->getConfig()->get( ConfigNames::EnableImageImports ) && !$this->hasXmlUpload( $data ) ) {
			return $images ?
				$this->submitImagesOnly( $data, $timestamp, $images ) :
				Status::newFatal( 'importdump-upload-nothing' );
		}
		$fileName = $data['target'] . '-' . $timestamp . '.xml';

		$request = $this->getRequest();
		$request->setVal( 'wpDestFile', $fileName );

		$stashKey = $this->useChunkedUploads() ? (string)( $data['UploadFileKey'] ?? '' ) : '';
		if ( $stashKey !== '' ) {
			if ( !UploadFromStash::isValidKey( $stashKey ) ) {
				return Status::newFatal( 'apierror-invalid-file-key' );
			}

			$uploadBase = new UploadFromStash( $this->getUser() );
			try {
				$uploadBase->initialize( $stashKey, $fileName );
			} catch ( UploadStashException $e ) {
				return Status::newFatal( 'uploadstash-exception', get_class( $e ), $e->getMessage() );
			}
		} else {
			$uploadBase = UploadBase::createFromRequest( $request, $data['UploadSourceType'] ?? 'File' );
		}

		if ( !$uploadBase->isEnabled() ) {
			return Status::newFatal( 'uploaddisabled' );
		}

		$permission = $uploadBase->isAllowed( $this->getUser() );
		if ( $permission !== true ) {
			throw new PermissionsError( $permission );
		}

		if ( $uploadBase->isEmptyFile() ) {
			return Status::newFatal( 'empty-file' );
		}

		$virus = $this->uploadVerification->detectVirus( $uploadBase->getTempPath() );
		if ( $virus ) {
			return Status::newFatal( 'uploadvirus', $virus );
		}

		$mime = $this->mimeAnalyzer->guessMimeType( $uploadBase->getTempPath() );
		if ( !in_array( $mime, $this->dumpProcessor->getAcceptedMimeTypes(), true ) ) {
			return Status::newFatal( 'filetype-mime-mismatch', 'xml', $mime );
		}

		// Archives, and files too big to check here, are checked by a job. The request waits
		// until then, and the file is kept apart from the ones that can be imported.
		$verifyLater = in_array( $mime, $this->dumpProcessor->getArchiveMimeTypes(), true ) ||
			$uploadBase->getFileSize() > DumpProcessor::INLINE_SIZE_LIMIT;

		$history = null;
		$requirements = [];
		if ( !$verifyLater ) {
			$processed = $this->dumpProcessor->process( $uploadBase->getTempPath(), null );
			if ( !$processed->isOK() ) {
				return $processed;
			}

			$result = $processed->getValue();
			'@phan-var DumpResult $result';
			$history = $result->history;
			$requirements = $result->requirements;
		}

		$repo = $this->repoGroup->getLocalRepo();
		$uploadStash = new UploadStash( $repo, $this->getUser() );

		if ( $stashKey !== '' ) {
			$fileKey = $stashKey;
		} else {
			$status = $uploadBase->tryStashFile( $this->getUser() );
			if ( !$status->isGood() ) {
				return $status;
			}

			$stashFile = $status->getStatusValue()->getValue();
			'@phan-var UploadStashFile $stashFile';
			$fileKey = $stashFile->getFileKey();
		}

		try {
			$file = $uploadStash->getFile( $fileKey );
		} catch ( UploadStashException $e ) {
			return Status::newFatal( 'uploadstash-exception', get_class( $e ), $e->getMessage() );
		}

		$status = $repo->publish(
			$file->getPath(),
			$verifyLater ? "/ImportDump/unverified/$fileName" : "/ImportDump/$fileName",
			"/ImportDump/archive/$fileName",
			FileRepo::DELETE_SOURCE
		);

		if ( !$status->isOK() ) {
			return $status;
		}

		$imagesName = $data['target'] . '-' . $timestamp . '-images';
		if ( $images ) {
			$stashed = $this->publishImages( $images, $imagesName );
			if ( !$stashed->isOK() ) {
				$backend = $repo->getBackend();
				foreach ( [ "/ImportDump/unverified/$fileName", "/ImportDump/$fileName" ] as $path ) {
					$backend->quickDelete( [
						'src' => $repo->getZonePath( 'public' ) . $path,
						'ignoreMissingSource' => true,
					] );
				}

				return $stashed;
			}
		}

		$dbw->newInsertQueryBuilder()
			->insertInto( 'import_requests' )
			->ignore()
			->row( [
				'request_source' => $data['source'],
				'request_target' => $data['target'],
				'request_reason' => $data['reason'],
				'request_status' => ( $verifyLater ? RequestStatus::Verifying : RequestStatus::Pending )->value,
				'request_history' => $history?->value,
				'request_actor' => $this->getUser()->getActorId(),
				'request_timestamp' => $timestamp,
				'request_type' => RequestType::Xml->value,
			] )
			->caller( __METHOD__ )
			->execute();

		$requestID = (string)$dbw->insertId();
		if ( $verifyLater ) {
			$this->jobQueueGroup->push(
				new JobSpecification( VerifyDumpJob::JOB_NAME, [ 'requestid' => (int)$requestID ] )
			);
		} else {
			$this->requestManager->loadFromID( (int)$requestID );
			$this->requestManager->setRequirements( $requirements );
			$this->attentionNotifier->notify( $this->requestManager );
		}

		$requestQueueLink = SpecialPage::getTitleValueFor( 'RequestImportQueue', $requestID );

		$logEntry = new ManualLogEntry( $this->getLogType( $data['target'] ), 'request' );

		$logEntry->setPerformer( $this->getUser() );
		$logEntry->setTarget( $requestQueueLink );
		$logEntry->setComment( $data['reason'] );

		$logEntry->setParameters(
			[
				'4::requestTarget' => $data['target'],
				'5:request-link:requestID' => (int)$requestID,
			]
		);

		$logID = $logEntry->insert( $dbw );
		$logEntry->publish( $logID );

		$imagesID = 0;
		if ( $images && (int)$requestID > 0 ) {
			$imagesID = $this->addImagesRequest( $data, $timestamp, (int)$requestID );
		}

		$this->showSuccess( (int)$requestID, $imagesID, ( $verifyLater ? 1 : 0 ) + ( $imagesID > 0 ? 1 : 0 ) );

		if ( $this->getConfig()->get( ConfigNames::UsersNotifiedOnAllRequests ) ) {
			$this->sendNotifications( $data['reason'], $requestID, $data['target'], $this->getUser() );
		}

		return Status::newGood();
	}

	private function hasXmlUpload( array $data ): bool {
		if ( $this->useChunkedUploads() && (string)( $data['UploadFileKey'] ?? '' ) !== '' ) {
			return true;
		}

		if ( ( $data['UploadSourceType'] ?? 'File' ) === 'Url' ) {
			return true;
		}

		$upload = $this->getRequest()->getUpload( 'wpUploadFile' );
		return $upload->exists() && $upload->getError() !== UPLOAD_ERR_NO_FILE;
	}

	/** A request for images that has no XML request to be linked to. */
	private function submitImagesOnly( array $data, string $timestamp, UploadFromFile $images ): Status {
		$stashed = $this->publishImages( $images, $data['target'] . '-' . $timestamp . '-images' );
		if ( !$stashed->isOK() ) {
			return $stashed;
		}

		$requestID = $this->addImagesRequest( $data, $timestamp, null );
		if ( $requestID <= 0 ) {
			return Status::newFatal( 'importdump-duplicate-request' );
		}

		$this->showSuccess( $requestID, 0, 1 );

		if ( $this->getConfig()->get( ConfigNames::UsersNotifiedOnAllRequests ) ) {
			$this->sendNotifications( $data['reason'], (string)$requestID, $data['target'], $this->getUser() );
		}

		return Status::newGood();
	}

	/**
	 * @return UploadFromFile|Status|null The upload of the images archive, a status with the problem if it
	 *  cannot be used, or null if there is none.
	 * @throws PermissionsError
	 */
	private function getImagesUpload(): UploadFromFile|Status|null {
		if ( !$this->getConfig()->get( ConfigNames::EnableImageImports ) ) {
			return null;
		}

		$upload = $this->getRequest()->getUpload( 'wpUploadImages' );
		if ( !$upload->exists() || $upload->getError() === UPLOAD_ERR_NO_FILE ) {
			return null;
		}

		$uploadBase = new UploadFromFile();
		$uploadBase->initialize( $upload->getName() ?? '', $upload );

		if ( !$uploadBase->isEnabled() ) {
			return Status::newFatal( 'uploaddisabled' );
		}

		$permission = $uploadBase->isAllowed( $this->getUser() );
		if ( $permission !== true ) {
			throw new PermissionsError( $permission );
		}

		if ( $uploadBase->isEmptyFile() ) {
			return Status::newFatal( 'empty-file' );
		}

		$virus = $this->uploadVerification->detectVirus( $uploadBase->getTempPath() );
		if ( $virus ) {
			return Status::newFatal( 'uploadvirus', $virus );
		}

		$mime = $this->mimeAnalyzer->guessMimeType( $uploadBase->getTempPath() );
		if ( !in_array( $mime, $this->dumpProcessor->getArchiveMimeTypes(), true ) ) {
			return Status::newFatal( 'importdump-images-not-archive' );
		}

		return $uploadBase;
	}

	/** The archive always waits for the job that checks it, then goes where the images import reads it from. */
	private function publishImages( UploadFromFile $uploadBase, string $imagesName ): Status {
		$status = $uploadBase->tryStashFile( $this->getUser() );
		if ( !$status->isGood() ) {
			return $status;
		}

		$stashFile = $status->getStatusValue()->getValue();
		'@phan-var UploadStashFile $stashFile';

		$repo = $this->repoGroup->getLocalRepo();
		try {
			$file = ( new UploadStash( $repo, $this->getUser() ) )->getFile( $stashFile->getFileKey() );
		} catch ( UploadStashException $e ) {
			return Status::newFatal( 'uploadstash-exception', get_class( $e ), $e->getMessage() );
		}

		return $repo->publish(
			$file->getPath(),
			"/ImportDump/unverified/$imagesName",
			"/ImportDump/archive/$imagesName",
			FileRepo::DELETE_SOURCE
		);
	}

	/**
	 * Adds the request for an archive of images, linked to the XML request that it was uploaded with, if any.
	 *
	 * @return int The ID of the new request, or 0 if it could not be added.
	 */
	private function addImagesRequest( array $data, string $timestamp, ?int $linkedID ): int {
		$dbw = $this->connectionProvider->getPrimaryDatabase( RequestManager::VIRTUAL_DOMAIN );
		$dbw->newInsertQueryBuilder()
			->insertInto( 'import_requests' )
			->ignore()
			->row( [
				'request_source' => $data['source'],
				'request_target' => $data['target'],
				'request_reason' => $data['reason'],
				'request_status' => RequestStatus::Verifying->value,
				'request_actor' => $this->getUser()->getActorId(),
				'request_timestamp' => $timestamp,
				'request_type' => RequestType::Images->value,
				'request_linked_id' => $linkedID,
			] )
			->caller( __METHOD__ )
			->execute();

		$requestID = $dbw->insertId();
		if ( $requestID <= 0 ) {
			return 0;
		}

		$this->jobQueueGroup->push(
			new JobSpecification( VerifyDumpJob::JOB_NAME, [ 'requestid' => $requestID ] )
		);

		$requestQueueLink = SpecialPage::getTitleValueFor( 'RequestImportQueue', (string)$requestID );

		$logEntry = new ManualLogEntry( $this->getLogType( $data['target'] ), 'request' );
		$logEntry->setPerformer( $this->getUser() );
		$logEntry->setTarget( $requestQueueLink );
		$logEntry->setComment( $data['reason'] );
		$logEntry->setParameters( [
			'4::requestTarget' => $data['target'],
			'5:request-link:requestID' => $requestID,
		] );

		$logID = $logEntry->insert( $dbw );
		$logEntry->publish( $logID );

		return $requestID;
	}

	/**
	 * @param int $requestID The request that was submitted.
	 * @param int $imagesID The linked request for the images, or 0 if there is none.
	 * @param int $verifying How many of the uploaded files are checked in the background.
	 */
	private function showSuccess( int $requestID, int $imagesID, int $verifying ): void {
		$requestLink = $this->makeRequestLink( $requestID );
		$message = $imagesID > 0 ?
			$this->msg( 'importdump-success-both' )->rawParams( $requestLink, $this->makeRequestLink( $imagesID ) ) :
			$this->msg( 'importdump-success' )->rawParams( $requestLink );

		$this->getOutput()->addHTML( Html::successBox( $message->escaped() ) );

		if ( $verifying > 0 ) {
			$this->getOutput()->addHTML(
				Html::noticeBox( $this->msg( 'importdump-success-verifying' )->numParams( $verifying )->escaped(), '' )
			);
		}
	}

	private function makeRequestLink( int $requestID ): string {
		return $this->getLinkRenderer()->makeLink(
			SpecialPage::getTitleValueFor( 'RequestImportQueue', (string)$requestID ),
			"#$requestID"
		);
	}

	/** @return list<Message> */
	private function getUploadHelp(): array {
		$formats = $this->dumpProcessor->getArchiveFormats();

		$names = [];
		$tarExtensions = [];
		foreach ( $formats as $format ) {
			$names[] = self::FORMAT_NAMES[$format];
			array_push( $tarExtensions, ...( self::COMPRESSED_TAR_EXTENSIONS[$format] ?? [] ) );
		}

		$language = $this->getLanguage();
		$help = [
			$this->msg( 'importdump-help-upload-file' ),
			$this->msg( 'importdump-help-upload-file-types' )->params(
				$language->listToText( $names ),
				$language->listToText( $tarExtensions )
			),
		];

		if ( $this->getConfig()->get( ConfigNames::EnableHistoryDetection ) ) {
			$help[] = $this->msg( 'importdump-help-upload-file-history' );
		}

		return $help;
	}

	private function useChunkedUploads(): bool {
		return (bool)$this->getConfig()->get( ConfigNames::EnableChunkedUploads );
	}

	private function addChunkedUploadModule(): void {
		$out = $this->getOutput();
		$out->addModules( [ 'ext.importdump.chunkedupload' ] );
		$maxSize = RequestImportUploadFromChunks::getMaxUploadSize();
		$maxPhpSize = UploadBase::getMaxPhpUploadSize();

		$out->addJsConfigVars( 'wgRequestImportChunkedUpload', [
			'maxUploadSize' => $maxSize < PHP_INT_MAX ? $maxSize : null,
			'maxPhpUploadSize' => $maxPhpSize,
			'maxDirectUploadSize' => $this->getMaxDirectUploadSize( $maxPhpSize ),
			'minChunkSize' => ApiUpload::getMinUploadChunkSize( $this->getConfig() ),
			'type' => 'xml',
		] );
	}

	private function getMaxDirectUploadSize( int $maxPhpSize ): int {
		$limit = min( $maxPhpSize, UploadBase::getMaxUploadSize( 'file' ) );
		$threshold = $this->getConfig()->get( ConfigNames::ChunkedUploadThreshold );

		return $threshold === null ? $limit : min( $limit, max( 1, (int)$threshold ) );
	}

	public function getLogType( string $target ): string {
		if ( !$this->moduleFactory?->isEnabled( 'core' ) ) {
			return 'requestimport';
		}

		$mwCore = $this->moduleFactory->core( $target );
		if ( !$mwCore->isEnabled( 'private-wikis' ) ) {
			return 'requestimport';
		}

		return $mwCore->isPrivate() ? 'requestimportprivate' : 'requestimport';
	}

	public function sendNotifications(
		string $reason,
		string $requestID,
		string $target,
		UserIdentity $requester
	): void {
		$notifiedUsers = array_filter(
			array_map(
				fn ( string $userName ): ?User => $this->userFactory->newFromName( $userName ),
				$this->getConfig()->get( ConfigNames::UsersNotifiedOnAllRequests )
			)
		);

		$isPrivate = $this->getLogType( $target ) === 'requestimportprivate';
		$receivers = array_values( array_filter(
			$notifiedUsers,
			static fn ( User $receiver ): bool => $receiver->isAllowed( 'handle-import-requests' ) &&
				( !$isPrivate || $receiver->isAllowed( 'view-private-import-requests' ) )
		) );

		if ( !$receivers ) {
			return;
		}

		$requestLink = SpecialPage::getTitleFor( 'RequestImportQueue', $requestID )->getFullURL();
		$this->notificationService->notify(
			new RequestNotification( 'requestimport-new-request', $requester, [
				'request-id' => $requestID,
				'request-url' => $requestLink,
				'reason' => $reason,
				'requester' => $requester->getName(),
				'target' => $target,
			] ),
			new RecipientSet( $receivers )
		);
	}

	public function isValidDatabase( ?string $target ): Message|true {
		if ( !in_array( $target, $this->getConfig()->get( MainConfigNames::LocalDatabases ), true ) ) {
			return $this->msg( 'importdump-invalid-target' );
		}

		return true;
	}

	public function isValidReason( ?string $reason ): Message|true {
		if ( !$reason || ctype_space( $reason ) ) {
			return $this->msg( 'htmlform-required' );
		}

		return true;
	}

	/** @throws ErrorPageError|PermissionsError|UserBlockedError */
	public function checkPermissions(): void {
		parent::checkPermissions();

		$user = $this->getUser();
		$permissionRequired = UploadBase::isAllowed( $user );
		if ( $permissionRequired !== true ) {
			throw new PermissionsError( $permissionRequired );
		}

		$block = $user->getBlock();
		if (
			$block && (
				$user->isBlockedFromUpload() ||
				$block->appliesToRight( 'request-import' )
			)
		) {
			throw new UserBlockedError( $block );
		}

		$this->checkReadOnly();
		if ( !UploadBase::isEnabled() ) {
			throw new ErrorPageError( 'uploaddisabled', 'uploaddisabledtext' );
		}
	}

	/** @inheritDoc */
	protected function getDisplayFormat(): string {
		return 'ooui';
	}

	/** @inheritDoc */
	protected function getGroupName(): string {
		return 'other';
	}

	/** @inheritDoc */
	public function getRestriction(): string {
		return 'request-import';
	}
}
