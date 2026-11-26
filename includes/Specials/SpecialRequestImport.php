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
use Miraheze\ImportDump\Api\ApiRequestImportUpload;
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
use Miraheze\ImportDump\Upload\UploadedParts;
use Miraheze\ManageWiki\Helpers\Factories\ModuleFactory;
use Wikimedia\Mime\MimeAnalyzer;
use Wikimedia\Rdbms\IConnectionProvider;
use Wikimedia\Rdbms\IDatabase;
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
		$this->requireLogin( 'requestimport-notloggedin' );
		$this->setParameter( $par );
		$this->setHeaders();

		$dbr = $this->connectionProvider->getReplicaDatabase( RequestManager::VIRTUAL_DOMAIN );
		if ( !WikiMap::isCurrentWikiDbDomain( $dbr->getDomainID() ) ) {
			throw new ErrorPageError( 'requestimport-notcentral', 'requestimport-notcentral-text' );
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
				'label-message' => 'requestimport-label-source',
				'help-message' => 'requestimport-help-source',
				'required' => true,
			],
			'target' => [
				'type' => 'text',
				'label-message' => 'requestimport-label-target',
				'help-message' => 'requestimport-help-target',
				'required' => true,
				'validation-callback' => [ $this, 'isValidDatabase' ],
			],
		];

		// With images that can also be uploaded, say that this field is for the XML.
		// Messages that can be used here:
		// * requestimport-label-upload-file
		// * requestimport-label-upload-xml
		$fileLabel = $this->getConfig()->get( ConfigNames::EnableImageImports ) ?
			'requestimport-label-upload-xml' :
			'requestimport-label-upload-file';

		if ( $this->getConfig()->get( ConfigNames::EnableImageImports ) ) {
			$formDescriptor['UploadEither'] = [
				'type' => 'info',
				'default' => $this->msg( 'requestimport-help-upload-either' )->text(),
			];
		}

		if (
			UploadFromUrl::isEnabled() &&
			UploadFromUrl::isAllowed( $this->getUser() ) === true
		) {
			$formDescriptor += [
				'UploadSourceType' => [
					'type' => 'radio',
					'label-message' => 'requestimport-label-upload-source-type',
					'default' => 'File',
					'options-messages' => [
						'requestimport-label-upload-source-file' => 'File',
						'requestimport-label-upload-source-url' => 'Url',
					],
				],
				'UploadFile' => [
					'type' => 'file',
					'label-message' => $fileLabel,
					'help-messages' => $this->getUploadHelp(),
					'hide-if' => [ '!==', 'wpUploadSourceType', 'File' ],
					'accept' => $this->dumpProcessor->getAcceptedClientMimeTypes(),
				],
				'UploadFileURL' => [
					'type' => 'url',
					'label-message' => 'requestimport-label-upload-xml-url',
					'help-message' => 'requestimport-help-upload-xml-url',
					'hide-if' => [ '!==', 'wpUploadSourceType', 'Url' ],
					'required' => true,
				],
			];
		} else {
			$formDescriptor += [
				'UploadFile' => [
					'type' => 'file',
					'label-message' => $fileLabel,
					'help-messages' => $this->getUploadHelp(),
					'accept' => $this->dumpProcessor->getAcceptedClientMimeTypes(),
				],
			];
		}

		if ( $this->getConfig()->get( ConfigNames::EnableImageImports ) ) {
			$formDescriptor['UploadImages'] = [
				'type' => 'file',
				'label-message' => 'requestimport-label-upload-images',
				'help-message' => 'requestimport-help-upload-images',
				'accept' => $this->dumpProcessor->getArchiveClientMimeTypes(),
			];

			$formDescriptor['SkipDupes'] = [
				'type' => 'check',
				'label-message' => 'requestimport-label-skip-dupes',
				'help-message' => 'requestimport-help-skip-dupes',
				'default' => false,
			];
		}

		if ( $this->useChunkedUploads() ) {
			$formDescriptor['UploadFileKey'] = [
				'type' => 'hidden',
				'default' => '',
			];

			if ( $this->getConfig()->get( ConfigNames::EnableImageImports ) ) {
				$formDescriptor['UploadImagesFileKey'] = [
					'type' => 'hidden',
					'default' => '',
				];
			}
		}

		$formDescriptor += [
			'reason' => [
				'type' => 'textarea',
				'rows' => 6,
				'label-message' => 'requestimport-label-reason',
				'help-message' => 'requestimport-help-reason',
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
			return Status::newFatal( 'requestimport-duplicate-request' );
		}

		$timestamp = $dbw->timestamp();

		$imagesKey = $this->getImagesStashKey( $data );
		$images = $this->getImagesUpload( $imagesKey );
		if ( $images instanceof Status ) {
			return $images;
		}

		if ( $this->getConfig()->get( ConfigNames::EnableImageImports ) && !$this->hasXmlUpload( $data ) ) {
			return $images ?
				$this->submitImagesOnly( $data, $timestamp, $images, $imagesKey ) :
				Status::newFatal( 'requestimport-upload-nothing' );
		}
		$fileName = $data['target'] . '-' . $timestamp . '.xml';

		$request = $this->getRequest();
		$request->setVal( 'wpDestFile', $fileName );

		$stashKey = $this->useChunkedUploads() ? (string)( $data['UploadFileKey'] ?? '' ) : '';

		$parts = null;
		if ( $stashKey !== '' ) {
			if ( !UploadFromStash::isValidKey( $stashKey ) ) {
				return Status::newFatal( 'apierror-invalid-file-key' );
			}

			// Parts are not joined here, because that is a lot of work for a request that a person waits for.
			$stage = UploadBase::getSessionStatus( $this->getUser(), $stashKey )['stage'] ?? '';
			if ( $stage === ApiRequestImportUpload::STAGE_PARTS ) {
				$parts = new UploadedParts( $stashKey );
			} elseif ( $stage === 'uploading' ) {
				return Status::newFatal( 'requestimport-xml-parts-incomplete' );
			}
		}

		$repo = $this->repoGroup->getLocalRepo();
		$verifyLater = true;
		$history = null;
		$requirements = [];

		if ( !$parts ) {
			if ( $stashKey !== '' ) {
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
		}

		$imagesName = $data['target'] . '-' . $timestamp . '-images';
		if ( $images ) {
			$stashed = $this->publishImages( $images, $imagesName, $imagesKey );
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
		if ( $parts ) {
			// The job reads this, so it has to be there before the job is queued.
			$referenced = $this->setPartsReference( (int)$requestID, $parts );
			if ( !$referenced->isOK() ) {
				return $referenced;
			}
		}

		if ( $verifyLater ) {
			$this->queueVerification( $dbw, (int)$requestID );
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
			$added = $this->addImagesRequest(
				$data,
				$timestamp,
				(int)$requestID,
				$images instanceof UploadedParts ? $images : null
			);
			$imagesID = $added->isOK() ? $added->getValue() : 0;
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
	private function submitImagesOnly(
		array $data,
		string $timestamp,
		UploadBase|UploadedParts $images,
		string $stashKey
	): Status {
		$stashed = $this->publishImages( $images, $data['target'] . '-' . $timestamp . '-images', $stashKey );
		if ( !$stashed->isOK() ) {
			return $stashed;
		}

		$added = $this->addImagesRequest(
			$data,
			$timestamp,
			null,
			$images instanceof UploadedParts ? $images : null
		);
		if ( !$added->isOK() ) {
			return $added;
		}

		$requestID = $added->getValue();

		$this->showSuccess( $requestID, 0, 1 );

		if ( $this->getConfig()->get( ConfigNames::UsersNotifiedOnAllRequests ) ) {
			$this->sendNotifications( $data['reason'], (string)$requestID, $data['target'], $this->getUser() );
		}

		return Status::newGood();
	}

	/** The key of an archive of images that was uploaded in parts, or an empty string if there is none. */
	private function getImagesStashKey( array $data ): string {
		if ( !$this->useChunkedUploads() || !$this->getConfig()->get( ConfigNames::EnableImageImports ) ) {
			return '';
		}

		return (string)( $data['UploadImagesFileKey'] ?? '' );
	}

	/**
	 * @param string $stashKey The key of the archive if it was uploaded in parts.
	 * @return UploadBase|UploadedParts|Status|null The upload of the images archive, the parts that it was
	 *  uploaded in, a status with the problem if it cannot be used, or null if there is none.
	 * @throws PermissionsError
	 */
	private function getImagesUpload( string $stashKey ): UploadBase|UploadedParts|Status|null {
		if ( !$this->getConfig()->get( ConfigNames::EnableImageImports ) ) {
			return null;
		}

		if ( $stashKey !== '' ) {
			if ( !UploadFromStash::isValidKey( $stashKey ) ) {
				return Status::newFatal( 'apierror-invalid-file-key' );
			}

			// Parts are not joined here, because that is a lot of work for a request that a person waits for.
			$progress = UploadBase::getSessionStatus( $this->getUser(), $stashKey );
			if ( ( $progress['stage'] ?? '' ) === ApiRequestImportUpload::STAGE_PARTS ) {
				return new UploadedParts( $stashKey );
			}

			if ( ( $progress['stage'] ?? '' ) === 'uploading' ) {
				return Status::newFatal( 'requestimport-images-parts-incomplete' );
			}

			$uploadBase = new UploadFromStash( $this->getUser() );
			try {
				$uploadBase->initialize( $stashKey, 'requestimport.archive' );
			} catch ( UploadStashException $e ) {
				return Status::newFatal( 'uploadstash-exception', get_class( $e ), $e->getMessage() );
			}
		} else {
			$upload = $this->getRequest()->getUpload( 'wpUploadImages' );
			if ( !$upload->exists() || $upload->getError() === UPLOAD_ERR_NO_FILE ) {
				return null;
			}

			$uploadBase = new UploadFromFile();
			$uploadBase->initialize( $upload->getName() ?? '', $upload );
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
		if ( !in_array( $mime, $this->dumpProcessor->getArchiveMimeTypes(), true ) ) {
			return Status::newFatal( 'requestimport-images-not-archive' );
		}

		return $uploadBase;
	}

	/** The archive always waits for the job that checks it, then goes where the images import reads it from. */
	private function publishImages(
		UploadBase|UploadedParts $uploadBase,
		string $imagesName,
		string $stashKey
	): Status {
		if ( $uploadBase instanceof UploadedParts ) {
			return Status::newGood();
		}

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

		$repo = $this->repoGroup->getLocalRepo();
		try {
			$file = new UploadStash( $repo, $this->getUser() )->getFile( $fileKey );
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
	 * Queues the check of a request once the request is committed. A job that runs before that cannot
	 * see the request, and would be dropped without checking anything.
	 */
	private function queueVerification( IDatabase $dbw, int $requestID ): void {
		$dbw->onTransactionCommitOrIdle(
			function () use ( $requestID ): void {
				$this->jobQueueGroup->push(
					new JobSpecification( VerifyDumpJob::JOB_NAME, [ 'requestid' => $requestID ] )
				);
			},
			__METHOD__
		);
	}

	/**
	 * Notes the uploaded parts of a request, and removes the request again if that does not work,
	 * so that it is not left without a file.
	 */
	private function setPartsReference( int $requestID, UploadedParts $parts ): Status {
		if ( $requestID <= 0 ) {
			return Status::newFatal( 'requestimport-duplicate-request' );
		}

		$this->requestManager->loadFromID( $requestID );
		$referenced = $this->requestManager->setPartsReference( $parts->fileKey, $this->getUser()->getId() );
		if ( $referenced->isOK() ) {
			return $referenced;
		}

		// Roll back the row so it is not left without a file.
		$this->connectionProvider->getPrimaryDatabase( RequestManager::VIRTUAL_DOMAIN )
			->newDeleteQueryBuilder()
			->deleteFrom( 'import_requests' )
			->where( [ 'request_id' => $requestID ] )
			->caller( __METHOD__ )
			->execute();

		return $referenced;
	}

	/**
	 * Adds the request for an archive of images, linked to the XML request that it was uploaded with, if any.
	 *
	 * @return Status<int> The ID of the new request, or why it could not be added.
	 */
	private function addImagesRequest( array $data, string $timestamp, ?int $linkedID, ?UploadedParts $parts ): Status {
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
				'request_skip_dupes' => (int)( $data['SkipDupes'] ?? false ),
			] )
			->caller( __METHOD__ )
			->execute();

		$requestID = $dbw->insertId();
		if ( $requestID <= 0 ) {
			return Status::newFatal( 'requestimport-duplicate-request' );
		}

		// The job reads this, so it has to be there before the job is queued.
		if ( $parts ) {
			$referenced = $this->setPartsReference( $requestID, $parts );
			if ( !$referenced->isOK() ) {
				return $referenced;
			}
		}

		$this->queueVerification( $dbw, $requestID );

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

		return Status::newGood( $requestID );
	}

	/**
	 * @param int $requestID The request that was submitted.
	 * @param int $imagesID The linked request for the images, or 0 if there is none.
	 * @param int $verifying How many of the uploaded files are checked in the background.
	 */
	private function showSuccess( int $requestID, int $imagesID, int $verifying ): void {
		$requestLink = $this->makeRequestLink( $requestID );
		$message = $imagesID > 0 ?
			$this->msg( 'requestimport-success-both' )->rawParams( $requestLink, $this->makeRequestLink( $imagesID ) ) :
			$this->msg( 'requestimport-success' )->rawParams( $requestLink );

		$this->getOutput()->addHTML( Html::successBox( $message->escaped() ) );

		if ( $verifying > 0 ) {
			$this->getOutput()->addHTML( Html::noticeBox(
				$this->msg( 'requestimport-success-verifying' )->numParams( $verifying )->escaped(),
				''
			) );
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
			$this->msg( 'requestimport-help-upload-xml' ),
			$this->msg( 'requestimport-help-upload-xml-types' )->params(
				$language->listToText( $names ),
				$language->listToText( $tarExtensions )
			),
		];

		if ( $this->getConfig()->get( ConfigNames::EnableHistoryDetection ) ) {
			$help[] = $this->msg( 'requestimport-help-upload-xml-history' );
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
			'images' => (bool)$this->getConfig()->get( ConfigNames::EnableImageImports ),
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
			return $this->msg( 'requestimport-invalid-target' );
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
