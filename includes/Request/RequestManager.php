<?php

declare( strict_types = 1 );

namespace Miraheze\ImportDump\Request;

use MediaWiki\Config\ServiceOptions;
use MediaWiki\FileRepo\FileRepo;
use MediaWiki\FileRepo\RepoGroup;
use MediaWiki\Interwiki\InterwikiLookup;
use MediaWiki\JobQueue\JobQueueGroupFactory;
use MediaWiki\JobQueue\JobSpecification;
use MediaWiki\Language\MessageLocalizer;
use MediaWiki\Logging\ManualLogEntry;
use MediaWiki\MainConfigNames;
use MediaWiki\Notification\NotificationService;
use MediaWiki\Notification\RecipientSet;
use MediaWiki\Permissions\Authority;
use MediaWiki\Shell\Shell;
use MediaWiki\SpecialPage\SpecialPage;
use MediaWiki\Status\Status;
use MediaWiki\User\User;
use MediaWiki\User\UserFactory;
use MediaWiki\User\UserIdentity;
use MediaWiki\WikiMap\WikiMap;
use Miraheze\ImportDump\ConfigNames;
use Miraheze\ImportDump\Hooks\HookRunner;
use Miraheze\ImportDump\Jobs\ImportDumpJob;
use Miraheze\ImportDump\Jobs\ImportImagesJob;
use Miraheze\ImportDump\Jobs\VerifyDumpJob;
use Miraheze\ImportDump\Notifications\RequestNotification;
use Miraheze\ImportDump\Progress\ImportProgress;
use Miraheze\ImportDump\Progress\VerificationProgress;
use Miraheze\ImportDump\Requirements\RequirementChecker;
use Miraheze\ImportDump\Requirements\RequirementProblem;
use Miraheze\ImportDump\Requirements\TargetState;
use Miraheze\ImportDump\Requirements\TargetStateProvider;
use Miraheze\ImportDump\Upload\SkippedFile;
use Miraheze\ManageWiki\Helpers\Factories\ModuleFactory;
use stdClass;
use Wikimedia\FileBackend\FileBackend;
use Wikimedia\FileBackend\FSFile\FSFile;
use Wikimedia\Rdbms\IConnectionProvider;
use Wikimedia\Rdbms\IDatabase;
use Wikimedia\Rdbms\IExpression;
use Wikimedia\Rdbms\LikeValue;
use Wikimedia\Rdbms\Platform\ISQLPlatform;
use Wikimedia\Rdbms\SelectQueryBuilder;
use Wikimedia\Timestamp\ConvertibleTimestamp;
use Wikimedia\Timestamp\TimestampFormat;
use function array_column;
use function array_filter;
use function array_map;
use function array_merge;
use function array_slice;
use function array_unique;
use function array_values;
use function basename;
use function boolval;
use function count;
use function explode;
use function in_array;
use function intval;
use function is_array;
use function json_decode;
use function json_encode;
use function max;
use function parse_url;
use function preg_replace;
use function str_contains;
use function str_replace;
use function strtr;
use function trim;
use function wfEscapeWikiText;
use function wfTimestamp;
use const JSON_INVALID_UTF8_SUBSTITUTE;
use const MW_INSTALL_PATH;
use const PHP_URL_HOST;

class RequestManager {

	public const string EXTENSION_USER = 'RequestImport Extension';
	public const string STATUS_UPDATE_USER = 'RequestImport Status Update';

	/** The virtual domain of the database that has the requests. */
	public const string VIRTUAL_DOMAIN = 'virtual-requestimport';

	/** The most skipped files that are kept for a request. */
	private const int MAX_STORED_SKIPPED = 500;

	private const array SYSTEM_USERS = [
		self::EXTENSION_USER,
		self::STATUS_UPDATE_USER,
	];

	/** @var RequestStatus[] */
	public const array EXPIRABLE_STATUSES = [
		RequestStatus::Complete,
		RequestStatus::Declined,
		RequestStatus::Failed,
	];

	public const array CONSTRUCTOR_OPTIONS = [
		ConfigNames::DumpCommand,
		ConfigNames::FileExpiryDays,
		ConfigNames::ImagesCommand,
		ConfigNames::InterwikiMap,
		ConfigNames::MaintenanceCommand,
		MainConfigNames::LocalDatabases,
	];

	/** The rights that the requester needs on the target wiki to be shown what it is missing. */
	public const array REQUIRED_TARGET_RIGHTS = [ 'read' ];

	public const string IMPORT_RIGHT = 'import';

	private IDatabase $dbw;
	private stdClass|false $row;
	private int $ID;

	public function __construct(
		private readonly IConnectionProvider $connectionProvider,
		private readonly HookRunner $hookRunner,
		private readonly InterwikiLookup $interwikiLookup,
		private readonly JobQueueGroupFactory $jobQueueGroupFactory,
		private readonly NotificationService $notificationService,
		private readonly RepoGroup $repoGroup,
		private readonly MessageLocalizer $messageLocalizer,
		private readonly ServiceOptions $options,
		private readonly UserFactory $userFactory,
		private readonly ImportProgress $importProgress,
		private readonly RequirementChecker $requirementChecker,
		private readonly TargetStateProvider $targetStateProvider,
		private readonly VerificationProgress $verificationProgress,
		private readonly ?ModuleFactory $moduleFactory,
	) {
		$options->assertRequiredOptions( self::CONSTRUCTOR_OPTIONS );
	}

	public function loadFromID( int $requestID ): void {
		$this->dbw = $this->connectionProvider->getPrimaryDatabase( self::VIRTUAL_DOMAIN );
		$this->ID = $requestID;
		$this->targetStateProvider->clear();

		$this->row = $this->dbw->newSelectQueryBuilder()
			->select( ISQLPlatform::ALL_ROWS )
			->from( 'import_requests' )
			->where( [ 'request_id' => $requestID ] )
			->caller( __METHOD__ )
			->fetchRow();
	}

	public function getID(): int {
		return $this->ID;
	}

	public function exists(): bool {
		return (bool)$this->row;
	}

	public function addComment( string $comment, User $user ): void {
		$this->dbw->newInsertQueryBuilder()
			->insertInto( 'import_request_comments' )
			->row( [
				'request_id' => $this->ID,
				'request_comment_text' => $comment,
				'request_comment_timestamp' => $this->dbw->timestamp(),
				'request_comment_actor' => $user->getActorId(),
			] )
			->caller( __METHOD__ )
			->execute();

		if ( !in_array( $user->getName(), self::SYSTEM_USERS, true ) ) {
			$this->sendNotification( $comment, 'requestimport-request-comment', $user );
		}
	}

	public function logStatusUpdate( string $comment, RequestStatus $newStatus, User $user ): void {
		$requestQueueLink = SpecialPage::getTitleValueFor( 'RequestImportQueue', (string)$this->ID );

		$logEntry = new ManualLogEntry(
			$this->isPrivate() ? 'requestimportprivate' : 'requestimport',
			'statusupdate'
		);

		$logEntry->setPerformer( $user );
		$logEntry->setTarget( $requestQueueLink );

		if ( $comment ) {
			$logEntry->setComment( $comment );
		}

		$logEntry->setParameters(
			[
				'4:request-link:requestID' => $this->ID,
				'5:request-status:requestStatus' => $newStatus->value,
			]
		);

		$logID = $logEntry->insert( $this->dbw );
		$logEntry->publish( $logID );
	}

	public function logStarted( User $user ): void {
		$requestQueueLink = SpecialPage::getTitleValueFor( 'RequestImportQueue', (string)$this->ID );

		$logEntry = new ManualLogEntry(
			$this->isPrivate() ? 'requestimportprivate' : 'requestimport',
			'started'
		);

		$logEntry->setPerformer( $user );
		$logEntry->setTarget( $requestQueueLink );

		$logEntry->setParameters(
			[
				'4::requestTarget' => $this->getTarget(),
				'5:request-link:requestID' => $this->ID,
			]
		);

		$logID = $logEntry->insert( $this->dbw );
		$logEntry->publish( $logID );
	}

	public function sendNotification( string $comment, string $type, UserIdentity $user ): void {
		$requestLink = SpecialPage::getTitleFor( 'RequestImportQueue', (string)$this->ID )->getFullURL();
		$involvedUsers = array_values( array_filter(
			$this->getInvolvedUsers(),
			static fn ( mixed $involved ): bool => $involved instanceof UserIdentity &&
				$involved->getName() !== $user->getName() &&
				!in_array( $involved->getName(), self::SYSTEM_USERS, true )
		) );

		if ( !$involvedUsers ) {
			return;
		}

		$this->notificationService->notify(
			new RequestNotification( $type, $user, [
				'request-id' => $this->ID,
				'request-url' => $requestLink,
				'comment' => $comment,
			] ),
			new RecipientSet( $involvedUsers )
		);
	}

	/** @return list<array{comment: string, timestamp: string, user: User}> */
	public function getComments(): array {
		$res = $this->dbw->newSelectQueryBuilder()
			->select( ISQLPlatform::ALL_ROWS )
			->from( 'import_request_comments' )
			->where( [ 'request_id' => $this->ID ] )
			->orderBy( 'request_comment_timestamp', SelectQueryBuilder::SORT_ASC )
			->caller( __METHOD__ )
			->fetchResultSet();

		if ( !$res->numRows() ) {
			return [];
		}

		$comments = [];
		foreach ( $res as $row ) {
			$user = $this->userFactory->newFromActorId( (int)$row->request_comment_actor );
			$comments[] = [
				'comment' => $row->request_comment_text,
				'timestamp' => $row->request_comment_timestamp,
				'user' => $user,
			];
		}

		return $comments;
	}

	public function addHandler( User $user ): void {
		$this->dbw->newInsertQueryBuilder()
			->insertInto( 'import_request_handlers' )
			->ignore()
			->row( [
				'request_id' => $this->ID,
				'request_handler_actor' => $user->getActorId(),
			] )
			->caller( __METHOD__ )
			->execute();
	}

	/** @return User[] */
	public function getHandlers(): array {
		$actorIDs = $this->dbw->newSelectQueryBuilder()
			->select( 'request_handler_actor' )
			->from( 'import_request_handlers' )
			->where( [ 'request_id' => $this->ID ] )
			->caller( __METHOD__ )
			->fetchFieldValues();

		return array_map(
			fn ( string $actorID ): User => $this->userFactory->newFromActorId( (int)$actorID ),
			$actorIDs
		);
	}

	/** @return list<User> */
	public function getInvolvedUsers(): array {
		return array_values( array_unique( array_merge(
			array_column( $this->getComments(), 'user' ),
			$this->getHandlers(),
			[ $this->getRequester() ]
		) ) );
	}

	public function insertInterwikiPrefix( string $prefix, string $url, User $user ): bool {
		$dbw = $this->connectionProvider->getPrimaryDatabase( $this->getTarget() );
		$dbw->newInsertQueryBuilder()
			->insertInto( 'interwiki' )
			->ignore()
			->row( [
				'iw_prefix' => $prefix,
				'iw_url' => $url,
				'iw_api' => '',
				'iw_local' => 0,
				'iw_trans' => 0,
			] )
			->caller( __METHOD__ )
			->execute();

		if ( $dbw->affectedRows() === 0 ) {
			return false;
		}

		$this->interwikiLookup->invalidateCache( $prefix );

		$requestQueueLink = SpecialPage::getTitleValueFor( 'RequestImportQueue', (string)$this->ID );

		$logEntry = new ManualLogEntry(
			$this->isPrivate() ? 'requestimportprivate' : 'requestimport',
			'interwiki'
		);

		$logEntry->setPerformer( $user );
		$logEntry->setTarget( $requestQueueLink );

		$logEntry->setParameters(
			[
				'4::prefix' => $prefix,
				'5::target' => $this->getTarget(),
				'6:request-link:requestID' => $this->ID,
			]
		);

		$logID = $logEntry->insert( $this->dbw );
		$logEntry->publish( $logID );

		return true;
	}

	public function targetExists(): bool {
		return in_array( $this->getTarget(), $this->options->get( MainConfigNames::LocalDatabases ), true );
	}

	public function getInterwikiPrefix(): string {
		$sourceHost = parse_url( $this->getSource(), PHP_URL_HOST );
		if ( !$sourceHost ) {
			return '';
		}

		$sourceHost = '://' . $sourceHost;
		if ( $this->targetExists() ) {
			$dbr = $this->connectionProvider->getReplicaDatabase( $this->getTarget() );
			$row = $dbr->newSelectQueryBuilder()
				->select( 'iw_prefix' )
				->from( 'interwiki' )
				->where( $dbr->expr( 'iw_url', IExpression::LIKE,
					new LikeValue( $dbr->anyString(), $sourceHost, $dbr->anyString() )
				) )
				->caller( __METHOD__ )
				->fetchRow();

			if ( $row->iw_prefix ?? '' ) {
				return $row->iw_prefix;
			}
		}

		$dbr = $this->connectionProvider->getReplicaDatabase( 'virtual-interwiki' );
		$row = $dbr->newSelectQueryBuilder()
			->select( 'iw_prefix' )
			->from( 'interwiki' )
			->where( $dbr->expr( 'iw_url', IExpression::LIKE,
				new LikeValue( $dbr->anyString(), $sourceHost, $dbr->anyString() )
			) )
			->caller( __METHOD__ )
			->fetchRow();

		if ( $row->iw_prefix ?? '' ) {
			return $row->iw_prefix;
		}

		if ( $this->options->get( ConfigNames::InterwikiMap ) ) {
			$parsedSource = parse_url( $this->getSource(), PHP_URL_HOST ) ?: '';
			$domain = explode( '.', $parsedSource )[1] ?? '';
			if ( $domain ) {
				$domain .= '.' . ( explode( '.', $parsedSource )[2] ?? '' );
				if ( $this->options->get( ConfigNames::InterwikiMap )[$domain] ?? '' ) {
					$domain = $this->options->get( ConfigNames::InterwikiMap )[$domain];
					$subdomain = explode( '.', $parsedSource )[0] ?? '';
					if ( $subdomain ) {
						return $domain . ':' . $subdomain;
					}
				}
			}
		}

		return '';
	}

	public function getCommand(): string {
		$command = $this->options->get(
			$this->getType() === RequestType::Images ? ConfigNames::ImagesCommand : ConfigNames::DumpCommand
		);

		if ( !$this->getInterwikiPrefix() ) {
			$command = preg_replace( '/--username-prefix=?/', '', $command );
		}

		return str_replace( [
			'{IP}',
			'{wiki}',
			'{username-prefix}',
			'{file-name}',
			'{file-path}',
			'{comment}',
		], [
			MW_INSTALL_PATH,
			$this->getTarget(),
			$this->getInterwikiPrefix(),
			$this->getFileName(),
			$this->getSplitFilePath(),
			str_contains( $command, '{comment}' ) ? Shell::escape( $this->getImportComment() ) : '',
		], $command );
	}

	public function getImportComment(): string {
		$wiki = WikiMap::getWiki( $this->dbw->getDomainID() );
		$request = $wiki ?
			$wiki->getCanonicalUrl( 'Special:RequestImportQueue/' . $this->ID ) :
			'#' . $this->ID;

		return $this->messageLocalizer->msg(
			'importdump-images-comment',
			// The source is used as wikitext on the pages of the files. Signatures are expanded when
			// a page is saved, which wfEscapeWikiText does not stop.
			strtr( wfEscapeWikiText( $this->getSource() ), [ '~~~' => '~~&#126;' ] ),
			$request
		)->inContentLanguage()->text();
	}

	/** Whether the requester has all of the rights that are needed to see the state of the target wiki. */
	public function requesterHasTargetAccess(): bool {
		if ( !$this->targetExists() || $this->getRequesterGroupsOnTarget() === null ) {
			return false;
		}

		return $this->getTargetState()->hasRights( self::REQUIRED_TARGET_RIGHTS );
	}

	/**
	 * Whether the requester has a right on the target wiki, as the ImportDumpGetTargetState hook
	 * reports it. This is null when that is not known, which is without an account on the target
	 * wiki or when the hook gives no rights.
	 */
	public function requesterHasTargetRight( string $right ): ?bool {
		if ( !$this->targetExists() || $this->getRequesterGroupsOnTarget() === null ) {
			return null;
		}

		$rights = $this->getTargetState()->rights;
		return $rights === [] ? null : in_array( $right, $rights, true );
	}

	/** @return ?string[] The groups of the requester on the target wiki, or null without an account there. */
	private function getRequesterGroupsOnTarget(): ?array {
		return $this->targetStateProvider->getGroups( $this->getTarget(), $this->getRequester()->getName() );
	}

	private function getTargetState(): TargetState {
		if ( !$this->targetExists() ) {
			return TargetState::empty();
		}

		return $this->targetStateProvider->getState( $this->getTarget(), $this->getRequester()->getName() );
	}

	/** @return string[] */
	public function getUserGroupsFromTarget(): array {
		if ( !$this->targetExists() ) {
			return [ $this->messageLocalizer->msg( 'importdump-usergroups-none' )->text() ];
		}

		return $this->getRequesterGroupsOnTarget() ?: [
			$this->messageLocalizer->msg( 'importdump-usergroups-none' )->text(),
		];
	}

	public function getFilePath(): string {
		$fileName = $this->getFileName();

		$localRepo = $this->repoGroup->getLocalRepo();
		$zonePath = $localRepo->getZonePath( 'public' ) . '/ImportDump';

		return $zonePath . '/' . $fileName;
	}

	public function getUnverifiedFilePath(): string {
		$localRepo = $this->repoGroup->getLocalRepo();
		$zonePath = $localRepo->getZonePath( 'public' ) . '/ImportDump/unverified';

		return $zonePath . '/' . $this->getFileName();
	}

	public function getLocalUnverifiedFile(): ?FSFile {
		$backend = $this->repoGroup->getLocalRepo()->getBackend();
		return $backend->getLocalReference( [ 'src' => $this->getUnverifiedFilePath() ] ) ?: null;
	}

	/** Moves the unverified file itself to where the import reads it from. */
	public function publishUnverifiedFile(): Status {
		return $this->publishVerified( $this->getUnverifiedFilePath(), FileRepo::DELETE_SOURCE );
	}

	/**
	 * Stores the files that were extracted from the unverified archive in the images directory of
	 * this request, and removes the archive.
	 *
	 * @param array<string, string> $files The name that each file gets, and the path that it is read from.
	 */
	public function publishExtractedImages( array $files ): Status {
		$backend = $this->repoGroup->getLocalRepo()->getBackend();
		$directory = $this->getFilePath();

		$status = Status::wrap( $backend->prepare( [ 'dir' => $directory ] ) );
		foreach ( $files as $name => $source ) {
			if ( !$status->isOK() ) {
				return $status;
			}

			$status->merge( Status::wrap( $backend->quickStore( [ 'src' => $source, 'dst' => "$directory/$name" ] ) ) );
		}

		if ( !$status->isOK() ) {
			return $status;
		}

		$this->deleteUnverifiedFile();
		return $status;
	}

	/** Publishes a file that was extracted from the unverified file, which is then removed. */
	public function publishExtractedFile( string $extractedPath ): Status {
		return $this->publishVerified( $extractedPath, 0 );
	}

	private function publishVerified( string $source, int $flags ): Status {
		$fileName = $this->getFileName();
		$status = $this->repoGroup->getLocalRepo()->publish(
			$source,
			"/ImportDump/$fileName",
			"/ImportDump/archive/$fileName",
			$flags
		);

		if ( $status->isOK() ) {
			$this->deleteUnverifiedFile();
		}

		return $status;
	}

	public function deleteUnverifiedFile(): void {
		$this->repoGroup->getLocalRepo()->getBackend()->quickDelete( [
			'src' => $this->getUnverifiedFilePath(),
			'ignoreMissingSource' => true,
		] );
	}

	public function getArchiveFilePath(): string {
		$localRepo = $this->repoGroup->getLocalRepo();
		$zonePath = $localRepo->getZonePath( 'public' ) . '/ImportDump/archive';

		return $zonePath . '/' . $this->getFileName();
	}

	public function getSplitFilePath(): string {
		return FileBackend::splitStoragePath( $this->getFilePath() )[2];
	}

	/** The images of a request are kept in a directory, which has no extension. */
	public function getFileName(): string {
		$name = $this->getTarget() . '-' . $this->getTimestamp();
		return $this->getType() === RequestType::Images ? "$name-images" : "$name.xml";
	}

	public function fileExists(): bool {
		if ( $this->getType() === RequestType::Images ) {
			return $this->listImageFiles() !== [];
		}

		$backend = $this->repoGroup->getLocalRepo()->getBackend();
		return $backend->fileExists( [ 'src' => $this->getFilePath() ] ) === true;
	}

	/** @return list<string> The paths of the stored image files, or nothing for any other request. */
	private function listImageFiles(): array {
		if ( $this->getType() !== RequestType::Images ) {
			return [];
		}

		$backend = $this->repoGroup->getLocalRepo()->getBackend();
		$files = $backend->getFileList( [ 'dir' => $this->getFilePath() ] );
		if ( $files === null ) {
			return [];
		}

		$paths = [];
		foreach ( $files as $file ) {
			$paths[] = $this->getFilePath() . '/' . $file;
		}

		return $paths;
	}

	public function deleteFile(): bool {
		$backend = $this->repoGroup->getLocalRepo()->getBackend();

		$paths = [ $this->getFilePath(), $this->getArchiveFilePath(), $this->getUnverifiedFilePath() ];
		if ( $this->getType() === RequestType::Images ) {
			$paths = [ ...$this->listImageFiles(), $this->getArchiveFilePath(), $this->getUnverifiedFilePath() ];
		}

		$success = true;
		foreach ( $paths as $path ) {
			$status = $backend->quickDelete( [
				'src' => $path,
				'ignoreMissingSource' => true,
			] );

			$success = $status->isOK() && $success;
		}

		if ( $this->getType() === RequestType::Images ) {
			$backend->clean( [ 'dir' => $this->getFilePath() ] );
		}

		return $success;
	}

	public function getFileExpiryDays(): int {
		if ( !in_array( $this->getStatus(), self::EXPIRABLE_STATUSES, true ) ) {
			return 0;
		}

		$expiry = $this->options->get( ConfigNames::FileExpiryDays );
		return is_array( $expiry ) ? (int)( $expiry[$this->getStatus()->value] ?? 0 ) : 0;
	}

	public function isPastFileExpiry(): bool {
		$days = $this->getFileExpiryDays();
		if ( $days <= 0 ) {
			return false;
		}

		$expires = (int)wfTimestamp( TimestampFormat::UNIX, $this->getTimestamp() ) + $days * 86400;
		return $expires <= (int)ConvertibleTimestamp::now( TimestampFormat::UNIX );
	}

	public function hasFileExpired(): bool {
		return $this->isPastFileExpiry() && !$this->fileExists();
	}

	public function getFileSize(): int {
		if ( !$this->fileExists() ) {
			return 0;
		}

		$backend = $this->repoGroup->getLocalRepo()->getBackend();
		if ( $this->getType() === RequestType::Images ) {
			$size = 0;
			foreach ( $this->listImageFiles() as $path ) {
				$size += (int)$backend->getFileSize( [ 'src' => $path ] );
			}

			return $size;
		}

		return (int)$backend->getFileSize( [ 'src' => $this->getFilePath() ] );
	}

	public function getReason(): string {
		return $this->row->request_reason;
	}

	public function getRequester(): User {
		return $this->userFactory->newFromActorId( (int)$this->row->request_actor );
	}

	public function getSource(): string {
		return $this->row->request_source;
	}

	public function getHistory(): ?RequestHistory {
		return RequestHistory::tryFrom( $this->row->request_history ?? '' );
	}

	/** @return ?array{stage: string, percent: ?int} */
	public function getVerificationProgress(): ?array {
		return $this->verificationProgress->get( $this->ID );
	}

	public function getStage(): ?RequestStage {
		return RequestStage::tryFrom( $this->row->request_stage ?? '' );
	}

	public function getStatus(): RequestStatus {
		return RequestStatus::from( $this->row->request_status );
	}

	public function hasImported(): bool {
		return $this->getType() === RequestType::Xml && ( $this->getStage()?->hasImported() ?? false );
	}

	/** @return list<RequestStage> The stages that this request goes through, in order. */
	public function getStages(): array {
		return RequestStage::forType( $this->getType() );
	}

	public function getType(): RequestType {
		return RequestType::tryFrom( $this->row->request_type ?? '' ) ?? RequestType::Xml;
	}

	/** The request that this one was made together with, which is the XML request for an images request. */
	public function getLinkedID(): ?int {
		$linked = $this->row->request_linked_id ?? null;
		return $linked === null ? null : (int)$linked;
	}

	/**
	 * The IDs of the requests that were made together with this one, whichever of them was made first.
	 *
	 * @return list<int>
	 */
	public function getLinkedIDs(): array {
		$linked = $this->getLinkedID();
		if ( $linked !== null ) {
			return [ $linked ];
		}

		$ids = $this->dbw->newSelectQueryBuilder()
			->select( 'request_id' )
			->from( 'import_requests' )
			->where( [ 'request_linked_id' => $this->ID ] )
			->orderBy( 'request_id' )
			->caller( __METHOD__ )
			->fetchFieldValues();

		return array_values( array_map( intval( ... ), $ids ) );
	}

	public function getLinkedStatus( int $linkedID ): ?RequestStatus {
		$status = $this->dbw->newSelectQueryBuilder()
			->select( 'request_status' )
			->from( 'import_requests' )
			->where( [ 'request_id' => $linkedID ] )
			->caller( __METHOD__ )
			->fetchField();

		return $status === false ? null : RequestStatus::tryFrom( $status );
	}

	/** @return list<string> */
	public function getRemainingCommands(): array {
		if ( $this->getType() === RequestType::Images ) {
			return [];
		}

		$scripts = [
			RequestStage::SiteStats->value => [ 'initSiteStats.php' => '--update' ],
			RequestStage::TextIndex->value => [ 'rebuildtextindex.php' => '' ],
			RequestStage::RecentChanges->value => [ 'rebuildrecentchanges.php' => '' ],
			RequestStage::RefreshLinks->value => [ 'refreshLinks.php' => '' ],
			RequestStage::Counts->value => [
				'initEditCount.php' => '',
				'updateArticleCount.php' => '--update',
			],
		];

		$commands = [];
		$remaining = array_slice( RequestStage::cases(), $this->getStage()?->getPosition() ?? 0 );

		foreach ( $remaining as $stage ) {
			foreach ( $scripts[$stage->value] ?? [] as $script => $arguments ) {
				$commands[] = trim( str_replace(
					[ '{IP}', '{wiki}', '{script}', '{script-name}', '{arguments}' ],
					[ MW_INSTALL_PATH, $this->getTarget(), $script, basename( $script, '.php' ), $arguments ],
					$this->options->get( ConfigNames::MaintenanceCommand )
				) );
			}
		}

		return $commands;
	}

	public function getTarget(): string {
		return $this->row->request_target;
	}

	public function getTimestamp(): string {
		return $this->row->request_timestamp;
	}

	public function isLocked(): bool {
		return (bool)$this->row->request_locked;
	}

	/** Private requests can only be seen by their requester and by those who may view private requests. */
	public function isViewableBy( Authority $authority ): bool {
		return !$this->isPrivate() ||
			$authority->getUser()->getName() === $this->getRequester()->getName() ||
			$authority->isAllowed( 'view-private-import-requests' );
	}

	/** Whether the request was marked as private, or the target wiki is private. */
	public function isPrivate(): bool {
		return (bool)$this->row->request_private || $this->isTargetPrivate();
	}

	/** Whether the target wiki is private, whatever the request is marked as. */
	public function isTargetPrivate(): bool {
		// The privacy of a deleted wiki cannot be known, so assume it was private.
		if ( !$this->targetExists() ) {
			return true;
		}

		if ( !$this->moduleFactory?->isEnabled( 'core' ) ) {
			return false;
		}

		$mwCore = $this->moduleFactory->core( $this->getTarget() );
		if ( !$mwCore->isEnabled( 'private-wikis' ) ) {
			return false;
		}

		return $mwCore->isPrivate();
	}

	public function startAtomic( string $fname ): void {
		$this->dbw->startAtomic( $fname );
	}

	public function setLocked( bool $locked ): void {
		$this->dbw->newUpdateQueryBuilder()
			->update( 'import_requests' )
			->set( [ 'request_locked' => (int)$locked ] )
			->where( [ 'request_id' => $this->ID ] )
			->caller( __METHOD__ )
			->execute();

		$this->row->request_locked = (int)$locked;
	}

	public function setPrivate( bool $private ): void {
		$this->dbw->newUpdateQueryBuilder()
			->update( 'import_requests' )
			->set( [ 'request_private' => (int)$private ] )
			->where( [ 'request_id' => $this->ID ] )
			->caller( __METHOD__ )
			->execute();

		$this->row->request_private = (int)$private;
	}

	public function setReason( string $reason ): void {
		$this->dbw->newUpdateQueryBuilder()
			->update( 'import_requests' )
			->set( [ 'request_reason' => $reason ] )
			->where( [ 'request_id' => $this->ID ] )
			->caller( __METHOD__ )
			->execute();

		$this->row->request_reason = $reason;
	}

	public function setSource( string $source ): void {
		$this->dbw->newUpdateQueryBuilder()
			->update( 'import_requests' )
			->set( [ 'request_source' => $source ] )
			->where( [ 'request_id' => $this->ID ] )
			->caller( __METHOD__ )
			->execute();

		$this->row->request_source = $source;
	}

	public function setImportPercent( int $percent, ?int $done, ?int $total ): void {
		$this->importProgress->set( $this->ID, $this->getStage()?->value ?? '', $percent, $done, $total );
	}

	/** The percentage of the current stage that is done, or null if it is not known. */
	public function getImportPercent(): ?int {
		return $this->getImportProgress()['percent'] ?? null;
	}

	/** @return ?array{done: int, total: int} The pages imported so far, or null if it is not known. */
	public function getImportPages(): ?array {
		$progress = $this->getImportProgress();
		if ( $progress === null || $progress['done'] === null || $progress['total'] === null ) {
			return null;
		}

		return [ 'done' => $progress['done'], 'total' => $progress['total'] ];
	}

	/** @return ?array{stage: string, percent: int, done: ?int, total: ?int} */
	private function getImportProgress(): ?array {
		$progress = $this->importProgress->get( $this->ID );
		if ( $progress === null || $progress['stage'] !== $this->getStage()?->value ) {
			return null;
		}

		return $progress;
	}

	public function setStage( ?RequestStage $stage ): void {
		$this->dbw->newUpdateQueryBuilder()
			->update( 'import_requests' )
			->set( [ 'request_stage' => $stage?->value ] )
			->where( [ 'request_id' => $this->ID ] )
			->caller( __METHOD__ )
			->execute();

		$this->row->request_stage = $stage?->value;
	}

	public function setStatus( RequestStatus $status ): void {
		$oldStatus = $this->getStatus();

		$set = [ 'request_status' => $status->value ];
		if ( $status === RequestStatus::Pending || $status === RequestStatus::Complete ) {
			$set['request_stage'] = null;
			$this->row->request_stage = null;
		}

		$this->dbw->newUpdateQueryBuilder()
			->update( 'import_requests' )
			->set( $set )
			->where( [ 'request_id' => $this->ID ] )
			->caller( __METHOD__ )
			->execute();

		$this->row->request_status = $status->value;

		if ( $oldStatus !== $status ) {
			$this->hookRunner->onImportDumpRequestStatusUpdate( $this, $status, $oldStatus );
		}
	}

	/** @param array<string, bool> $requirements The extensions that the dump needs, and if it is certain. */
	public function setRequirements( array $requirements ): void {
		$value = $requirements ? json_encode( $requirements ) : null;

		$this->dbw->newUpdateQueryBuilder()
			->update( 'import_requests' )
			->set( [ 'request_requirements' => $value ] )
			->where( [ 'request_id' => $this->ID ] )
			->caller( __METHOD__ )
			->execute();

		$this->row->request_requirements = $value;
	}

	/** @return array<string, bool> The extensions that the dump needs, and if it is certain. */
	public function getRequirements(): array {
		$value = $this->row->request_requirements ?? null;
		return $value ? array_map( boolval( ... ), (array)json_decode( $value, true ) ) : [];
	}

	/**
	 * @param list<SkippedFile> $skipped The image files that are left out of the import.
	 *  Only the first ones are kept, but the total is.
	 */
	public function setSkippedImages( array $skipped ): void {
		$value = $skipped ? json_encode( [
			'total' => count( $skipped ),
			'files' => array_map(
				static fn ( SkippedFile $file ): array => [
					'name' => $file->name,
					'reason' => $file->reason,
					'params' => $file->params,
				],
				array_slice( $skipped, 0, self::MAX_STORED_SKIPPED )
			),
		], JSON_INVALID_UTF8_SUBSTITUTE ) : null;

		$this->dbw->newUpdateQueryBuilder()
			->update( 'import_requests' )
			->set( [ 'request_skipped' => $value ] )
			->where( [ 'request_id' => $this->ID ] )
			->caller( __METHOD__ )
			->execute();

		$this->row->request_skipped = $value;
	}

	/** @return array{total:int,files:list<SkippedFile>} */
	public function getSkippedImages(): array {
		$value = $this->row->request_skipped ?? null;
		$data = $value ? json_decode( $value, true ) : null;
		if ( !is_array( $data ) ) {
			return [ 'total' => 0, 'files' => [] ];
		}

		$files = [];
		foreach ( (array)( $data['files'] ?? [] ) as $file ) {
			$files[] = new SkippedFile(
				(string)( $file['name'] ?? '' ),
				(string)( $file['reason'] ?? '' ),
				array_values( (array)( $file['params'] ?? [] ) )
			);
		}

		return [ 'total' => max( (int)( $data['total'] ?? 0 ), count( $files ) ), 'files' => $files ];
	}

	/** @return list<RequirementProblem> */
	public function getRequirementProblems(): array {
		$requirements = $this->getRequirements();
		if ( $requirements === [] ) {
			return [];
		}

		return $this->requirementChecker->getProblems( $requirements, $this->getTargetState() );
	}

	/** @return list<RequirementProblem> */
	public function getBlockingRequirementProblems(): array {
		return $this->requirementChecker->getBlocking( $this->getRequirementProblems() );
	}

	public function setHistory( ?RequestHistory $history ): void {
		$this->dbw->newUpdateQueryBuilder()
			->update( 'import_requests' )
			->set( [ 'request_history' => $history?->value ] )
			->where( [ 'request_id' => $this->ID ] )
			->caller( __METHOD__ )
			->execute();

		$this->row->request_history = $history?->value;
	}

	public function setTarget( string $target ): void {
		$this->dbw->newUpdateQueryBuilder()
			->update( 'import_requests' )
			->set( [ 'request_target' => $target ] )
			->where( [ 'request_id' => $this->ID ] )
			->caller( __METHOD__ )
			->execute();

		$this->row->request_target = $target;
	}

	public function retryVerification(): void {
		$this->verificationProgress->clear( $this->ID );
		$this->jobQueueGroupFactory->makeJobQueueGroup()->push(
			new JobSpecification( VerifyDumpJob::JOB_NAME, [ 'requestid' => $this->ID ] )
		);
	}

	public function executeJob( string $username ): void {
		$jobName = $this->getType() === RequestType::Images ? ImportImagesJob::JOB_NAME : ImportDumpJob::JOB_NAME;
		$this->jobQueueGroupFactory->makeJobQueueGroup( $this->getTarget() )->push(
			new JobSpecification(
				$jobName,
				[
					'requestid' => $this->ID,
					'username' => $username,
				]
			)
		);
	}

	public function endAtomic( string $fname ): void {
		$this->dbw->endAtomic( $fname );
	}
}
