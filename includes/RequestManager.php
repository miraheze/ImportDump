<?php

namespace Miraheze\ImportDump;

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
use MediaWiki\SpecialPage\SpecialPage;
use MediaWiki\Status\Status;
use MediaWiki\User\ActorStoreFactory;
use MediaWiki\User\User;
use MediaWiki\User\UserFactory;
use MediaWiki\User\UserGroupManagerFactory;
use MediaWiki\User\UserIdentity;
use Miraheze\ImportDump\Hooks\HookRunner;
use Miraheze\ImportDump\Jobs\ImportDumpJob;
use Miraheze\ImportDump\Jobs\VerifyDumpJob;
use Miraheze\ImportDump\Notifications\RequestNotification;
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

class RequestManager {

	private const array SYSTEM_USERS = [
		'ImportDump Extension',
		'ImportDump Status Update',
		'RequestImport Extension',
		'RequestImport Status Update',
	];

	public const array EXPIRABLE_STATUSES = [
		RequestStatus::STATUS_COMPLETE,
		RequestStatus::STATUS_DECLINED,
		RequestStatus::STATUS_FAILED,
	];

	public const array CONSTRUCTOR_OPTIONS = [
		ConfigNames::ExtensionRequirements,
		ConfigNames::FileExpiryDays,
		ConfigNames::InterwikiMap,
		ConfigNames::MaintenanceCommand,
		ConfigNames::ScriptCommand,
		MainConfigNames::LocalDatabases,
	];

	/** The rights that the requester needs on the target wiki to be shown what it is missing. */
	public const array REQUIRED_TARGET_RIGHTS = [ 'read' ];

	private IDatabase $dbw;
	private stdClass|false $row;
	private int $ID;

	/** @var ?array{extensions: ?string[], settings: array<string, bool>, rights: string[]} */
	private ?array $targetState = null;

	public function __construct(
		private readonly ActorStoreFactory $actorStoreFactory,
		private readonly IConnectionProvider $connectionProvider,
		private readonly HookRunner $hookRunner,
		private readonly InterwikiLookup $interwikiLookup,
		private readonly JobQueueGroupFactory $jobQueueGroupFactory,
		private readonly NotificationService $notificationService,
		private readonly RepoGroup $repoGroup,
		private readonly MessageLocalizer $messageLocalizer,
		private readonly ServiceOptions $options,
		private readonly UserFactory $userFactory,
		private readonly UserGroupManagerFactory $userGroupManagerFactory,
		private readonly ImportProgress $importProgress,
		private readonly VerificationProgress $verificationProgress,
		private readonly ?ModuleFactory $moduleFactory,
	) {
		$options->assertRequiredOptions( self::CONSTRUCTOR_OPTIONS );
	}

	public function loadFromID( int $requestID ): void {
		$this->dbw = $this->connectionProvider->getPrimaryDatabase( 'virtual-importdump' );
		$this->ID = $requestID;
		$this->targetState = null;

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

	public function logStatusUpdate( string $comment, string $newStatus, User $user ): void {
		$requestQueueLink = SpecialPage::getTitleValueFor( 'RequestImportQueue', (string)$this->ID );

		$logEntry = new ManualLogEntry(
			$this->isPrivate( forced: false ) ? 'requestimportprivate' : 'requestimport',
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
				'5:request-status:requestStatus' => $newStatus,
			]
		);

		$logID = $logEntry->insert( $this->dbw );
		$logEntry->publish( $logID );
	}

	public function logStarted( User $user ): void {
		$requestQueueLink = SpecialPage::getTitleValueFor( 'RequestImportQueue', (string)$this->ID );

		$logEntry = new ManualLogEntry(
			$this->isPrivate( forced: false ) ? 'requestimportprivate' : 'requestimport',
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
			$user = $this->userFactory->newFromActorId( $row->request_comment_actor );
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

	public function getInvolvedUsers(): array {
		return array_unique( array_merge(
			array_column( $this->getComments(), 'user' ),
			$this->getHandlers(),
			[ $this->getRequester() ]
		) );
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
			$this->isPrivate( forced: false ) ? 'requestimportprivate' : 'requestimport',
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
		$command = $this->options->get( ConfigNames::ScriptCommand );
		if ( !$this->getInterwikiPrefix() ) {
			$command = preg_replace( '/--username-prefix=?/', '', $command );
		}

		return str_replace( [
			'{IP}',
			'{wiki}',
			'{username-prefix}',
			'{file-name}',
			'{file-path}',
		], [
			MW_INSTALL_PATH,
			$this->getTarget(),
			$this->getInterwikiPrefix(),
			$this->getFileName(),
			$this->getSplitFilePath(),
		], $command );
	}

	/** Whether the requester has all of the rights that are needed to see the state of the target wiki. */
	public function requesterHasTargetAccess(): bool {
		if ( !$this->targetExists() || $this->getRequesterGroupsOnTarget() === null ) {
			return false;
		}

		return array_diff( self::REQUIRED_TARGET_RIGHTS, $this->getTargetState()['rights'] ) === [];
	}

	/** @return ?string[] The groups of the requester on the target wiki, or null without an account there. */
	private function getRequesterGroupsOnTarget(): ?array {
		$remoteUser = $this->actorStoreFactory
			->getUserIdentityLookup( $this->getTarget() )
			->getUserIdentityByName( $this->getRequester()->getName() );

		if ( !$remoteUser ) {
			return null;
		}

		return $this->userGroupManagerFactory
			->getUserGroupManager( $this->getTarget() )
			->getUserGroups( $remoteUser );
	}

	/** @return array{extensions: ?string[], settings: array<string, bool>, rights: string[]} */
	private function getTargetState(): array {
		if ( $this->targetState !== null ) {
			return $this->targetState;
		}

		$extensions = null;
		$settings = [];
		$rights = [];

		if ( $this->targetExists() ) {
			$this->hookRunner->onImportDumpGetTargetState(
				$this->getTarget(),
				$this->getRequesterGroupsOnTarget() ?? [],
				$extensions,
				$settings,
				$rights
			);
		}

		return $this->targetState = [
			'extensions' => $extensions,
			'settings' => $settings,
			'rights' => $rights,
		];
	}

	public function getUserGroupsFromTarget(): array {
		if ( !$this->targetExists() ) {
			return [ $this->messageLocalizer->msg( 'importdump-usergroups-none' )->text() ];
		}

		return $this->getRequesterGroupsOnTarget() ?: [ $this->messageLocalizer->msg( 'importdump-usergroups-none' )->text() ];
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

	public function getFileName(): string {
		return $this->getTarget() . '-' . $this->getTimestamp() . '.xml';
	}

	public function fileExists(): bool {
		$localRepo = $this->repoGroup->getLocalRepo();
		$backend = $localRepo->getBackend();
		if ( $backend->fileExists( [ 'src' => $this->getFilePath() ] ) ) {
			return true;
		}

		return false;
	}

	public function deleteFile(): bool {
		$backend = $this->repoGroup->getLocalRepo()->getBackend();

		$success = true;
		$paths = [ $this->getFilePath(), $this->getArchiveFilePath(), $this->getUnverifiedFilePath() ];
		foreach ( $paths as $path ) {
			$status = $backend->quickDelete( [
				'src' => $path,
				'ignoreMissingSource' => true,
			] );

			$success = $status->isOK() && $success;
		}

		return $success;
	}

	public function getFileExpiryDays(): int {
		if ( !in_array( $this->getStatus(), self::EXPIRABLE_STATUSES, true ) ) {
			return 0;
		}

		$expiry = $this->options->get( ConfigNames::FileExpiryDays );
		return is_array( $expiry ) ? (int)( $expiry[$this->getStatus()] ?? 0 ) : 0;
	}

	public function isPastFileExpiry(): bool {
		$days = $this->getFileExpiryDays();
		if ( $days <= 0 ) {
			return false;
		}

		$expires = (int)wfTimestamp( TS_UNIX, $this->getTimestamp() ) + $days * 86400;
		return $expires <= (int)ConvertibleTimestamp::now( TS_UNIX );
	}

	public function hasFileExpired(): bool {
		return $this->isPastFileExpiry() && !$this->fileExists();
	}

	public function getFileSize(): int {
		if ( !$this->fileExists() ) {
			return 0;
		}

		$localRepo = $this->repoGroup->getLocalRepo();
		$backend = $localRepo->getBackend();

		return (int)$backend->getFileSize( [ 'src' => $this->getFilePath() ] );
	}

	public function getReason(): string {
		return $this->row->request_reason;
	}

	public function getRequester(): User {
		return $this->userFactory->newFromActorId( $this->row->request_actor );
	}

	public function getSource(): string {
		return $this->row->request_source;
	}

	public function getHistory(): ?string {
		return $this->row->request_history ?? null;
	}

	/** @return ?array{stage: string, percent: ?int} */
	public function getVerificationProgress(): ?array {
		return $this->verificationProgress->get( $this->ID );
	}

	public function getStage(): string {
		return $this->row->request_stage ?? '';
	}

	public function getStatus(): string {
		return $this->row->request_status;
	}

	public function hasImported(): bool {
		$stage = $this->getStage();
		return !in_array( $stage, [ '', RequestStage::STAGE_DOWNLOAD, RequestStage::STAGE_IMPORT ], true );
	}

	/** @return string[] */
	public function getRemainingCommands(): array {
		$scripts = [
			RequestStage::STAGE_SITESTATS => [ 'initSiteStats.php' => '--update' ],
			RequestStage::STAGE_TEXTINDEX => [ 'rebuildtextindex.php' => '' ],
			RequestStage::STAGE_RECENTCHANGES => [ 'rebuildrecentchanges.php' => '' ],
			RequestStage::STAGE_REFRESHLINKS => [ 'refreshLinks.php' => '' ],
			RequestStage::STAGE_COUNTS => [
				'initEditCount.php' => '',
				'updateArticleCount.php' => '--update',
			],
		];

		$commands = [];
		$remaining = array_slice(
			RequestStage::STAGES,
			(int)array_search( $this->getStage(), RequestStage::STAGES, true )
		);

		foreach ( $remaining as $stage ) {
			foreach ( $scripts[$stage] ?? [] as $script => $arguments ) {
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
		return !$this->isPrivate( forced: false ) ||
			$authority->getUser()->getName() === $this->getRequester()->getName() ||
			$authority->isAllowed( 'view-private-import-requests' );
	}

	public function isPrivate( bool $forced ): bool {
		if ( !$forced && $this->row->request_private ) {
			return true;
		}

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

	public function setLocked( int $locked ): void {
		$this->dbw->newUpdateQueryBuilder()
			->update( 'import_requests' )
			->set( [ 'request_locked' => $locked ] )
			->where( [ 'request_id' => $this->ID ] )
			->caller( __METHOD__ )
			->execute();
	}

	public function setPrivate( int $private ): void {
		$this->dbw->newUpdateQueryBuilder()
			->update( 'import_requests' )
			->set( [ 'request_private' => $private ] )
			->where( [ 'request_id' => $this->ID ] )
			->caller( __METHOD__ )
			->execute();
	}

	public function setReason( string $reason ): void {
		$this->dbw->newUpdateQueryBuilder()
			->update( 'import_requests' )
			->set( [ 'request_reason' => $reason ] )
			->where( [ 'request_id' => $this->ID ] )
			->caller( __METHOD__ )
			->execute();
	}

	public function setSource( string $source ): void {
		$this->dbw->newUpdateQueryBuilder()
			->update( 'import_requests' )
			->set( [ 'request_source' => $source ] )
			->where( [ 'request_id' => $this->ID ] )
			->caller( __METHOD__ )
			->execute();
	}

	public function setImportPercent( int $percent, ?int $done, ?int $total ): void {
		$this->importProgress->set( $this->ID, $this->getStage(), $percent, $done, $total );
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
		if ( $progress === null || $progress['stage'] !== $this->getStage() ) {
			return null;
		}

		return $progress;
	}

	public function setStage( string $stage ): void {
		$this->dbw->newUpdateQueryBuilder()
			->update( 'import_requests' )
			->set( [ 'request_stage' => $stage ?: null ] )
			->where( [ 'request_id' => $this->ID ] )
			->caller( __METHOD__ )
			->execute();

		$this->row->request_stage = $stage ?: null;
	}

	public function setStatus( string $status ): void {
		$oldStatus = $this->getStatus();

		$set = [ 'request_status' => $status ];
		if ( $status === RequestStatus::STATUS_PENDING || $status === RequestStatus::STATUS_COMPLETE ) {
			$set['request_stage'] = null;
			$this->row->request_stage = null;
		}

		$this->dbw->newUpdateQueryBuilder()
			->update( 'import_requests' )
			->set( $set )
			->where( [ 'request_id' => $this->ID ] )
			->caller( __METHOD__ )
			->execute();

		$this->row->request_status = $status;

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
	 * Compares what the dump needs to what the target wiki has, as the ImportDumpGetTargetState
	 * hook reports it. A problem blocks the import if the dump certainly needs the extension and
	 * the wiki certainly does not have it or its setting.
	 *
	 * @return list<array{name: string, problem: string, setting: string, blocking: bool}>
	 *  A problem is one of unknown, missing, setting-unknown and setting. The setting is empty
	 *  when it does not apply.
	 */
	public function getRequirementProblems(): array {
		$requirements = $this->getRequirements();
		if ( !$requirements ) {
			return [];
		}

		$state = $this->getTargetState();
		$extensions = $state['extensions'];
		$settings = $state['settings'];

		$definitions = ExtensionRequirement::fromConfig(
			(array)$this->options->get( ConfigNames::ExtensionRequirements )
		);

		$problems = [];
		foreach ( $requirements as $name => $certain ) {
			$name = (string)$name;
			if ( $extensions === null ) {
				$problems[] = [ 'name' => $name, 'problem' => 'unknown', 'setting' => '', 'blocking' => false ];
				continue;
			}

			if ( !in_array( $name, $extensions, true ) ) {
				$problems[] = [ 'name' => $name, 'problem' => 'missing', 'setting' => '', 'blocking' => $certain ];
				continue;
			}

			foreach ( isset( $definitions[$name] ) ? $definitions[$name]->settings : [] as $setting ) {
				if ( !isset( $settings[$setting] ) ) {
					$problems[] = [
						'name' => $name,
						'problem' => 'setting-unknown',
						'setting' => $setting,
						'blocking' => false,
					];
				} elseif ( !$settings[$setting] ) {
					$problems[] = [
						'name' => $name,
						'problem' => 'setting',
						'setting' => $setting,
						'blocking' => $certain,
					];
				}
			}
		}

		return $problems;
	}

	/** @return list<array{name: string, problem: string, setting: string, blocking: bool}> */
	public function getBlockingRequirementProblems(): array {
		return array_values( array_filter(
			$this->getRequirementProblems(),
			static fn ( array $problem ): bool => $problem['blocking']
		) );
	}

	public function setHistory( ?string $history ): void {
		$this->dbw->newUpdateQueryBuilder()
			->update( 'import_requests' )
			->set( [ 'request_history' => $history ] )
			->where( [ 'request_id' => $this->ID ] )
			->caller( __METHOD__ )
			->execute();

		$this->row->request_history = $history;
	}

	public function setTarget( string $target ): void {
		$this->dbw->newUpdateQueryBuilder()
			->update( 'import_requests' )
			->set( [ 'request_target' => $target ] )
			->where( [ 'request_id' => $this->ID ] )
			->caller( __METHOD__ )
			->execute();
	}

	public function retryVerification(): void {
		$this->verificationProgress->clear( $this->ID );
		$this->jobQueueGroupFactory->makeJobQueueGroup()->push(
			new JobSpecification( VerifyDumpJob::JOB_NAME, [ 'requestid' => $this->ID ] )
		);
	}

	public function executeJob( string $username ): void {
		$this->jobQueueGroupFactory->makeJobQueueGroup( $this->getTarget() )->push(
			new JobSpecification(
				ImportDumpJob::JOB_NAME,
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
