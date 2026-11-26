<?php

declare( strict_types = 1 );

namespace Miraheze\ImportDump\Request;

use Closure;
use Generator;
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
use Miraheze\ImportDump\Progress\ImportOutput;
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
use Wikimedia\FileBackend\FSFile\TempFSFile;
use Wikimedia\Rdbms\IConnectionProvider;
use Wikimedia\Rdbms\IDatabase;
use Wikimedia\Rdbms\IExpression;
use Wikimedia\Rdbms\LikeValue;
use Wikimedia\Rdbms\Platform\ISQLPlatform;
use Wikimedia\Rdbms\SelectQueryBuilder;
use Wikimedia\Timestamp\ConvertibleTimestamp;
use Wikimedia\Timestamp\TimestampFormat;
use function array_chunk;
use function array_column;
use function array_diff;
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
use function intdiv;
use function intval;
use function is_array;
use function is_string;
use function json_decode;
use function json_encode;
use function ksort;
use function max;
use function parse_url;
use function preg_match;
use function preg_replace;
use function preg_replace_callback;
use function rawurlencode;
use function sort;
use function sprintf;
use function str_contains;
use function str_ends_with;
use function str_replace;
use function str_starts_with;
use function substr_count;
use function trim;
use function wfEscapeWikiText;
use function wfTimestamp;
use const JSON_INVALID_UTF8_SUBSTITUTE;
use const MW_INSTALL_PATH;
use const PHP_URL_HOST;
use const PHP_URL_SCHEME;

class RequestManager {

	public const string EXTENSION_USER = 'RequestImport Extension';
	public const string STATUS_UPDATE_USER = 'RequestImport Status Update';

	/** The virtual domain of the database that has the requests. */
	public const string VIRTUAL_DOMAIN = 'virtual-requestimport';

	/** The most skipped files that are kept for a request. */
	private const int MAX_STORED_SKIPPED = 500;

	/** How many files are sent to the file backend together. */
	private const int FILE_BATCH_SIZE = 100;

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
		ConfigNames::RequiredRights,
		MainConfigNames::LocalDatabases,
	];

	/** The rights that the requester needs on the target wiki to be shown what it is missing. */
	public const array REQUIRED_TARGET_RIGHTS = [ 'read' ];

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
		private readonly ImportOutput $importOutput,
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
			'{skip-dupes}',
			'{comment}',
		], [
			MW_INSTALL_PATH,
			$this->getTarget(),
			$this->getInterwikiPrefix(),
			$this->getFileName(),
			$this->getSplitFilePath(),
			$this->shouldSkipDupes() ? ' --skip-dupes' : '',
			str_contains( $command, '{comment}' ) ? Shell::escape( $this->getImportComment() ) : '',
		], $command );
	}

	public function getImportComment(): string {
		$wiki = WikiMap::getWiki( $this->dbw->getDomainID() );
		$source = $this->getCommentUrl( $this->getSource() );
		if ( !$wiki ) {
			return $this->messageLocalizer->msg( 'requestimport-images-comment-plain', $source, $this->ID )
				->inContentLanguage()
				->text();
		}

		return $this->messageLocalizer->msg(
			'requestimport-images-comment',
			$source,
			$wiki->getCanonicalUrl( 'Special:RequestImportQueue/' . $this->ID ),
			$this->ID
		)->inContentLanguage()->text();
	}

	/**
	 * The comment is wikitext on the pages of the files. A web address stays a link,
	 * with the characters that wikitext treats as markup encoded. Anything else is escaped.
	 */
	private function getCommentUrl( string $url ): string {
		if ( !in_array( parse_url( $url, PHP_URL_SCHEME ), [ 'http', 'https' ], true ) ) {
			return wfEscapeWikiText( $url );
		}

		$url = str_replace( [ '__', '~~~', '&#' ], [ '_%5F', '%7E%7E%7E', '%26#' ], $url );

		return preg_replace_callback(
			'/[\x00-\x20"\'<>\[\]{}|\\\\^`\x7f]/',
			static fn ( array $match ): string => rawurlencode( $match[0] ),
			$url
		) ?? wfEscapeWikiText( $url );
	}

	/** Whether the requester has all of the rights that are needed to see the state of the target wiki. */
	public function requesterHasTargetAccess(): bool {
		if ( !$this->targetExists() || $this->getRequesterGroupsOnTarget() === null ) {
			return false;
		}

		return $this->getTargetState()->hasRights( self::REQUIRED_TARGET_RIGHTS );
	}

	/** @return list<string> The rights that the requester needs on the target wiki for this type of request. */
	public function getRequiredTargetRights(): array {
		$rights = $this->options->get( ConfigNames::RequiredRights )[$this->getType()->value] ?? [];
		return array_values( array_unique( (array)$rights ) );
	}

	/**
	 * The required rights that the requester does not have on the target wiki, as the
	 * ImportDumpGetTargetState hook reports them. This is null when that is not known, which is
	 * without an account on the target wiki or when the hook gives no rights.
	 *
	 * @return ?list<string>
	 */
	public function getMissingTargetRights(): ?array {
		if ( !$this->targetExists() || $this->getRequesterGroupsOnTarget() === null ) {
			return null;
		}

		$rights = $this->getTargetState()->rights;
		if ( $rights === [] ) {
			return null;
		}

		return array_values( array_diff( $this->getRequiredTargetRights(), $rights ) );
	}

	/** @return ?string[] The groups of the requester on the target wiki, or null without an account there. */
	private function getRequesterGroupsOnTarget(): ?array {
		return $this->targetStateProvider->getGroups( $this->getTarget(), $this->getRequester()->getName() );
	}

	/** @return ?list<string> The file extensions that the target wiki accepts, or null if they are not known. */
	public function getTargetFileExtensions(): ?array {
		return $this->getTargetState()->getFileExtensions();
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
			return [ $this->messageLocalizer->msg( 'requestimport-usergroups-none' )->text() ];
		}

		return $this->getRequesterGroupsOnTarget() ?: [
			$this->messageLocalizer->msg( 'requestimport-usergroups-none' )->text(),
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
	 * this request, and removes the archive. They are stored in batches, which the backend sends together.
	 *
	 * @param array<string, string> $files The name that each file gets, and the path that it is read from.
	 * @param Closure( int ): void $onProgress Called with the percentage that is stored.
	 */
	public function publishExtractedImages( array $files, Closure $onProgress ): Status {
		$backend = $this->repoGroup->getLocalRepo()->getBackend();
		$directory = $this->getFilePath();

		$status = Status::wrap( $backend->prepare( [ 'dir' => $directory ] ) );
		if ( !$status->isOK() ) {
			return $status;
		}

		$total = count( $files );
		$done = 0;
		foreach ( array_chunk( $files, self::FILE_BATCH_SIZE, true ) as $batch ) {
			$operations = [];
			foreach ( $batch as $name => $source ) {
				$operations[] = [ 'op' => 'store', 'src' => $source, 'dst' => "$directory/$name" ];
			}

			$status->merge( Status::wrap( $backend->doQuickOperations( $operations ) ) );
			if ( !$status->isOK() ) {
				return $status;
			}

			$done += count( $batch );
			$onProgress( intdiv( $done * 100, $total ) );
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
		$backend = $this->repoGroup->getLocalRepo()->getBackend();
		foreach ( [ $this->getUnverifiedFilePath(), $this->getPartsReferencePath() ] as $path ) {
			$backend->quickDelete( [ 'src' => $path, 'ignoreMissingSource' => true ] );
		}
	}

	/** Whether an upload of this request still waits to be checked, as a stored file or as uploaded parts. */
	public function hasUnverifiedUpload(): bool {
		$backend = $this->repoGroup->getLocalRepo()->getBackend();
		foreach ( [ $this->getUnverifiedFilePath(), $this->getPartsReferencePath() ] as $path ) {
			if ( $backend->fileExists( [ 'src' => $path ] ) === true ) {
				return true;
			}
		}

		return false;
	}

	/** Where it is noted which uploaded parts an archive of images is joined from, instead of a stored file. */
	private function getPartsReferencePath(): string {
		return $this->getUnverifiedFilePath() . '.parts';
	}

	/** Notes that the archive of this request was uploaded in parts, which the verification joins. */
	public function setPartsReference( string $fileKey, int $userID ): Status {
		$backend = $this->repoGroup->getLocalRepo()->getBackend();
		$path = $this->getPartsReferencePath();

		$status = Status::wrap( $backend->prepare( [ 'dir' => FileBackend::parentStoragePath( $path ) ] ) );
		if ( !$status->isOK() ) {
			return $status;
		}

		return Status::wrap( $backend->quickCreate( [
			'dst' => $path,
			'content' => json_encode( [ 'filekey' => $fileKey, 'user' => $userID ] ),
			'overwrite' => true,
		] ) );
	}

	/** @return ?array{filekey: string, user: int} The uploaded parts of the archive, if it has any. */
	public function getPartsReference(): ?array {
		$content = $this->repoGroup->getLocalRepo()->getBackend()->getFileContents( [
			'src' => $this->getPartsReferencePath(),
		] );

		$reference = is_string( $content ) ? json_decode( $content, true ) : null;
		if ( !is_array( $reference ) || !is_string( $reference['filekey'] ?? null ) ) {
			return null;
		}

		return [ 'filekey' => $reference['filekey'], 'user' => (int)( $reference['user'] ?? 0 ) ];
	}

	public function getArchiveFilePath(): string {
		$localRepo = $this->repoGroup->getLocalRepo();
		$zonePath = $localRepo->getZonePath( 'public' ) . '/ImportDump/archive';

		return $zonePath . '/' . $this->getFileName();
	}

	public function getSplitFilePath(): string {
		return FileBackend::splitStoragePath( $this->getFilePath() )[2];
	}

	/** Where the output of the import is stored. It is deleted together with the images. */
	public function getOutputDirectory(): string {
		return $this->getFilePath() . '-output';
	}

	/** Stores a part of the output of the import. The name has the number of lines, so that nothing else is read. */
	public function storeOutputPart( int $part, string $text ): Status {
		$backend = $this->repoGroup->getLocalRepo()->getBackend();
		$directory = $this->getOutputDirectory();

		$lines = substr_count( $text, "\n" ) + ( str_ends_with( $text, "\n" ) ? 0 : 1 );
		$status = Status::wrap( $backend->prepare( [ 'dir' => $directory ] ) );
		if ( !$status->isOK() ) {
			return $status;
		}

		$name = sprintf( '%04d-l%d.txt', $part, $lines );
		$status = Status::wrap( $backend->quickCreate( [
			'dst' => "$directory/$name",
			'content' => $text,
			'overwrite' => true,
		] ) );

		if ( !$status->isOK() ) {
			return $status;
		}

		// A part that was stored before has another name if its number of lines was not the same.
		foreach ( $backend->getFileList( [ 'dir' => $directory ] ) ?? [] as $file ) {
			if ( $file !== $name && str_starts_with( $file, sprintf( '%04d-l', $part ) ) ) {
				$backend->quickDelete( [ 'src' => "$directory/$file", 'ignoreMissingSource' => true ] );
			}
		}

		return $status;
	}

	/** @return array<int, array{part: int, lines: int, name: string}> The stored parts of the output, by number. */
	private function getStoredOutput(): array {
		$backend = $this->repoGroup->getLocalRepo()->getBackend();
		$files = $backend->getFileList( [ 'dir' => $this->getOutputDirectory() ] );

		$parts = [];
		foreach ( $files ?? [] as $file ) {
			if ( preg_match( '/^(\d{4})-l(\d+)\.txt$/', $file, $matches ) ) {
				$parts[(int)$matches[1]] = [ 'part' => (int)$matches[1], 'lines' => (int)$matches[2], 'name' => $file ];
			}
		}

		ksort( $parts );
		return $parts;
	}

	/** @return list<array{part: int, lines: int}> The stored parts of the output, in order. */
	public function getOutputParts(): array {
		return array_values( array_map(
			static fn ( array $part ): array => [ 'part' => $part['part'], 'lines' => $part['lines'] ],
			$this->getStoredOutput()
		) );
	}

	public function getOutputPart( int $part ): ?string {
		$stored = $this->getStoredOutput()[$part] ?? null;
		if ( $stored === null ) {
			return null;
		}

		$backend = $this->repoGroup->getLocalRepo()->getBackend();
		$text = $backend->getFileContents( [ 'src' => $this->getOutputDirectory() . '/' . $stored['name'] ] );
		return $text === false ? null : $text;
	}

	/** The images of a request are kept in a directory, which has no extension. */
	public function getFileName(): string {
		$name = $this->getTarget() . '-' . $this->getTimestamp();
		return $this->getType() === RequestType::Images ? "$name-images" : "$name.xml";
	}

	public function fileExists(): bool {
		if ( $this->getType() === RequestType::Images ) {
			// One entry of the listing is enough, so a large import is not listed in full.
			return $this->getImageFiles()->valid();
		}

		$backend = $this->repoGroup->getLocalRepo()->getBackend();
		return $backend->fileExists( [ 'src' => $this->getFilePath() ] ) === true;
	}

	/** @return Generator<string> The paths of the stored image files, or nothing for any other request. */
	private function getImageFiles(): Generator {
		if ( $this->getType() !== RequestType::Images ) {
			return;
		}

		$backend = $this->repoGroup->getLocalRepo()->getBackend();
		$directory = $this->getFilePath();
		$files = $backend->getFileList( [ 'dir' => $directory ] );
		if ( $files === null ) {
			return;
		}

		foreach ( $files as $file ) {
			yield "$directory/$file";
		}
	}

	/** @return list<string> The names of the stored image files in the container, in order. */
	public function getImageObjectNames(): array {
		$names = [];
		foreach ( $this->getImageFiles() as $path ) {
			$names[] = FileBackend::splitStoragePath( $path )[2];
		}

		sort( $names );
		return $names;
	}

	/** Where the files are kept that were only left out because of their extension. */
	private function getHeldDirectory(): string {
		return $this->getFilePath() . '-held';
	}

	/**
	 * Keeps files that were left out because their extension is not accepted yet, apart from the images
	 * that are imported, so they can be checked again if the extension is accepted later.
	 *
	 * @param array<string, string> $files The name of each file and the path that it is read from.
	 */
	public function publishHeldImages( array $files ): Status {
		$backend = $this->repoGroup->getLocalRepo()->getBackend();
		$directory = $this->getHeldDirectory();

		$status = Status::wrap( $backend->prepare( [ 'dir' => $directory ] ) );
		foreach ( array_chunk( $files, self::FILE_BATCH_SIZE, true ) as $batch ) {
			if ( !$status->isOK() ) {
				break;
			}

			$operations = [];
			foreach ( $batch as $name => $source ) {
				$operations[] = [ 'op' => 'store', 'src' => $source, 'dst' => "$directory/$name" ];
			}

			$status->merge( Status::wrap( $backend->doQuickOperations( $operations ) ) );
		}

		return $status;
	}

	/** @return list<string> The names of the held files. */
	public function getHeldImageNames(): array {
		$backend = $this->repoGroup->getLocalRepo()->getBackend();
		$files = $backend->getFileList( [ 'dir' => $this->getHeldDirectory() ] );
		if ( $files === null ) {
			return [];
		}

		$names = [];
		foreach ( $files as $file ) {
			$names[] = (string)$file;
		}

		sort( $names );
		return $names;
	}

	/**
	 * @param list<string> $names
	 * @return array<string, TempFSFile> The local copy of each held file that could be read.
	 */
	public function getHeldImageCopies( array $names ): array {
		$backend = $this->repoGroup->getLocalRepo()->getBackend();
		$directory = $this->getHeldDirectory();

		$sources = [];
		foreach ( $names as $name ) {
			$sources[$name] = "$directory/$name";
		}

		// The copies are keyed by the path that they were read from, so they are keyed by name again.
		$copies = $backend->getLocalCopyMulti( [ 'srcs' => $sources ] );

		$byName = [];
		foreach ( $sources as $name => $source ) {
			$copy = $copies[$source] ?? null;
			if ( $copy instanceof TempFSFile ) {
				$byName[$name] = $copy;
			}
		}

		return $byName;
	}

	/**
	 * Moves held files to the images that are imported.
	 *
	 * @param list<string> $names
	 */
	public function releaseHeldImages( array $names ): Status {
		$backend = $this->repoGroup->getLocalRepo()->getBackend();
		$held = $this->getHeldDirectory();
		$directory = $this->getFilePath();

		$status = Status::wrap( $backend->prepare( [ 'dir' => $directory ] ) );
		foreach ( array_chunk( $names, self::FILE_BATCH_SIZE ) as $batch ) {
			if ( !$status->isOK() ) {
				break;
			}

			$operations = [];
			foreach ( $batch as $name ) {
				$operations[] = [ 'op' => 'move', 'src' => "$held/$name", 'dst' => "$directory/$name" ];
			}

			$status->merge( Status::wrap( $backend->doQuickOperations( $operations ) ) );
		}

		return $status;
	}

	public function deleteAllHeldImages(): bool {
		return $this->deleteHeldImages( $this->getHeldImageNames() );
	}

	/** @param list<string> $names */
	public function deleteHeldImages( array $names ): bool {
		$backend = $this->repoGroup->getLocalRepo()->getBackend();
		$directory = $this->getHeldDirectory();

		$success = true;
		foreach ( array_chunk( $names, self::FILE_BATCH_SIZE ) as $batch ) {
			$operations = [];
			foreach ( $batch as $name ) {
				$operations[] = [ 'op' => 'delete', 'src' => "$directory/$name", 'ignoreMissingSource' => true ];
			}

			$success = $backend->doQuickOperations( $operations )->isOK() && $success;
		}

		$backend->clean( [ 'dir' => $directory ] );
		return $success;
	}

	public function deleteFile(): bool {
		$backend = $this->repoGroup->getLocalRepo()->getBackend();

		$paths = [ $this->getFilePath(), $this->getArchiveFilePath(), $this->getUnverifiedFilePath() ];
		$success = true;
		if ( $this->getType() === RequestType::Images ) {
			$success = $this->deleteImageFiles( $backend );
			$success = $this->deleteOutputFiles( $backend ) && $success;
			$success = $this->deleteAllHeldImages() && $success;
			$paths = [ $this->getArchiveFilePath(), $this->getUnverifiedFilePath() ];
		}

		foreach ( $paths as $path ) {
			$status = $backend->quickDelete( [
				'src' => $path,
				'ignoreMissingSource' => true,
			] );

			$success = $status->isOK() && $success;
		}

		return $success;
	}

	private function deleteOutputFiles( FileBackend $backend ): bool {
		$success = true;
		foreach ( $this->getStoredOutput() as $part ) {
			$status = $backend->quickDelete( [
				'src' => $this->getOutputDirectory() . '/' . $part['name'],
				'ignoreMissingSource' => true,
			] );

			$success = $status->isOK() && $success;
		}

		$backend->clean( [ 'dir' => $this->getOutputDirectory() ] );
		return $success;
	}

	/** Deletes the stored image files in batches, which the backend sends together. */
	private function deleteImageFiles( FileBackend $backend ): bool {
		$success = true;
		$operations = [];
		foreach ( $this->getImageFiles() as $path ) {
			$operations[] = [ 'op' => 'delete', 'src' => $path, 'ignoreMissingSource' => true ];
			if ( count( $operations ) >= self::FILE_BATCH_SIZE ) {
				$success = $backend->doQuickOperations( $operations )->isOK() && $success;
				$operations = [];
			}
		}

		if ( $operations ) {
			$success = $backend->doQuickOperations( $operations )->isOK() && $success;
		}

		$backend->clean( [ 'dir' => $this->getFilePath() ] );
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
			$stored = $this->row->request_file_size ?? null;
			if ( $stored !== null ) {
				return (int)$stored;
			}

			// Requests from before the size was kept.
			$size = 0;
			foreach ( $this->getImageFiles() as $path ) {
				$size += (int)$backend->getFileSize( [ 'src' => $path ] );
			}

			return $size;
		}

		return (int)$backend->getFileSize( [ 'src' => $this->getFilePath() ] );
	}

	/** @return ?int How many image files were stored, or null if it was not recorded. */
	public function getFileCount(): ?int {
		$count = $this->row->request_file_count ?? null;
		return $count === null ? null : (int)$count;
	}

	/** Keeps how many image files were stored and their total size, so the page does not have to ask the backend. */
	public function setFileStats( int $count, int $size ): void {
		$this->dbw->newUpdateQueryBuilder()
			->update( 'import_requests' )
			->set( [ 'request_file_count' => $count, 'request_file_size' => $size ] )
			->where( [ 'request_id' => $this->ID ] )
			->caller( __METHOD__ )
			->execute();

		$this->row->request_file_count = $count;
		$this->row->request_file_size = $size;
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

	/** Whether the images that are already on the wiki are replaced, instead of being skipped. */
	public function shouldOverwrite(): bool {
		return (bool)( $this->row->request_overwrite ?? false );
	}

	public function setOverwrite( bool $overwrite ): void {
		$this->dbw->newUpdateQueryBuilder()
			->update( 'import_requests' )
			->set( [ 'request_overwrite' => (int)$overwrite ] )
			->where( [ 'request_id' => $this->ID ] )
			->caller( __METHOD__ )
			->execute();

		$this->row->request_overwrite = (int)$overwrite;
	}

	/** Whether a file is skipped when the wiki already has the same file under another name. */
	public function shouldSkipDupes(): bool {
		return (bool)( $this->row->request_skip_dupes ?? false );
	}

	public function setSkipDupes( bool $skipDupes ): void {
		$this->dbw->newUpdateQueryBuilder()
			->update( 'import_requests' )
			->set( [ 'request_skip_dupes' => (int)$skipDupes ] )
			->where( [ 'request_id' => $this->ID ] )
			->caller( __METHOD__ )
			->execute();

		$this->row->request_skip_dupes = (int)$skipDupes;
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

	/** Adds to what the script of the import is printing, in the given part of the output. */
	public function appendImportOutput( int $part, string $text ): void {
		$this->importOutput->append( $this->ID, $part, $text );
	}

	/** Clears the live part of the output. The stored parts are on another wiki, see clearStoredOutput(). */
	public function clearImportOutput(): void {
		$this->importOutput->clear( $this->ID );
	}

	/** Removes the stored parts of the output. This has to run on the wiki that stores the requests. */
	public function clearStoredOutput(): bool {
		return $this->deleteOutputFiles( $this->repoGroup->getLocalRepo()->getBackend() );
	}

	/** @return ?array{part: int, text: string} The part of the output that is being printed. */
	public function getImportOutput(): ?array {
		return $this->importOutput->get( $this->ID );
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
	 * @param int $held How many of the skipped files are kept to be checked again.
	 * @param int $total How many files were skipped, which is more than the list has when it was cut.
	 */
	public function setSkippedImages( array $skipped, int $held, int $total ): void {
		$value = $skipped ? json_encode( [
			'total' => max( $total, count( $skipped ) ),
			'held' => $held,
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

	/** @return int How many of the skipped files are kept to be checked again. */
	public function getHeldImageCount(): int {
		$value = $this->row->request_skipped ?? null;
		$data = $value ? json_decode( $value, true ) : null;
		return is_array( $data ) ? max( 0, (int)( $data['held'] ?? 0 ) ) : 0;
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

	/** Checks the files that were held back for their extension again, now that the target may accept it. */
	public function recheckHeldImages(): void {
		$this->setStatus( RequestStatus::Verifying );
		$this->verificationProgress->clear( $this->ID );

		// The job only runs when it sees the new status, so it is queued once that is committed.
		$requestID = $this->ID;
		$this->dbw->onTransactionCommitOrIdle(
			function () use ( $requestID ): void {
				$this->jobQueueGroupFactory->makeJobQueueGroup()->push(
					new JobSpecification( VerifyDumpJob::JOB_NAME, [ 'requestid' => $requestID ] )
				);
			},
			__METHOD__
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
