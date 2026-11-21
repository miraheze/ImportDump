<?php

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
use MediaWiki\Upload\UploadFromStash;
use MediaWiki\Upload\UploadFromUrl;
use MediaWiki\Upload\UploadStash;
use MediaWiki\Upload\UploadVerification;
use MediaWiki\User\User;
use MediaWiki\User\UserFactory;
use MediaWiki\User\UserIdentity;
use MediaWiki\WikiMap\WikiMap;
use Miraheze\ImportDump\ConfigNames;
use Miraheze\ImportDump\Jobs\VerifyDumpJob;
use Miraheze\ImportDump\Notifications\RequestNotification;
use Miraheze\ImportDump\RequestManager;
use Miraheze\ImportDump\RequestStatus;
use Miraheze\ImportDump\Upload\ArchiveReader;
use Miraheze\ImportDump\Upload\DumpProcessor;
use Miraheze\ImportDump\Upload\RequestImportUploadFromChunks;
use Miraheze\ManageWiki\Helpers\Factories\ModuleFactory;
use Wikimedia\Mime\MimeAnalyzer;
use Wikimedia\Rdbms\IConnectionProvider;
use Wikimedia\Rdbms\Platform\ISQLPlatform;

class SpecialRequestImport extends FormSpecialPage
	implements RequestStatus {

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

		$dbr = $this->connectionProvider->getReplicaDatabase( 'virtual-requestimport' );
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

		$dbw = $this->connectionProvider->getPrimaryDatabase( 'virtual-requestimport' );
		$duplicate = $dbw->newSelectQueryBuilder()
			->select( ISQLPlatform::ALL_ROWS )
			->from( 'import_requests' )
			->where( [
				'request_reason' => $data['reason'],
				'request_status' => self::STATUS_PENDING,
			] )
			->caller( __METHOD__ )
			->fetchRow();

		if ( (bool)$duplicate ) {
			return Status::newFatal( 'importdump-duplicate-request' );
		}

		$timestamp = $dbw->timestamp();
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

			$history = $processed->getValue()->history;
			$requirements = $processed->getValue()->requirements;
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

			$fileKey = $status->getStatusValue()->getValue()->getFileKey();
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

		$dbw->newInsertQueryBuilder()
			->insertInto( 'import_requests' )
			->ignore()
			->row( [
				'request_source' => $data['source'],
				'request_target' => $data['target'],
				'request_reason' => $data['reason'],
				'request_status' => $verifyLater ? self::STATUS_VERIFYING : self::STATUS_PENDING,
				'request_history' => $history,
				'request_actor' => $this->getUser()->getActorId(),
				'request_timestamp' => $timestamp,
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
			$this->requestManager->notifyRequester();
		}

		$requestQueueLink = SpecialPage::getTitleValueFor( 'RequestImportQueue', $requestID );

		$requestLink = $this->getLinkRenderer()->makeLink( $requestQueueLink, "#$requestID" );

		$this->getOutput()->addHTML(
			Html::successBox(
				$this->msg( 'importdump-success' )->rawParams( $requestLink )->escaped()
			)
		);

		if ( $verifyLater ) {
			$this->getOutput()->addHTML(
				Html::noticeBox( $this->msg( 'importdump-success-verifying' )->escaped(), '' )
			);
		}

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

		if ( $this->getConfig()->get( ConfigNames::UsersNotifiedOnAllRequests ) ) {
			$this->sendNotifications( $data['reason'], $requestID, $data['target'], $this->getUser() );
		}

		return Status::newGood();
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
