<?php

namespace Miraheze\ImportDump;

use JobSpecification;
use ManualLogEntry;
use MediaWiki\Config\ServiceOptions;
use MediaWiki\Extension\Notifications\Model\Event;
use MediaWiki\Interwiki\InterwikiLookup;
use MediaWiki\JobQueue\JobQueueGroupFactory;
use MediaWiki\Linker\LinkRenderer;
use MediaWiki\Message\Message;
use MediaWiki\Registration\ExtensionRegistry;
use MediaWiki\SpecialPage\SpecialPage;
use MediaWiki\User\ActorStoreFactory;
use MediaWiki\User\User;
use MediaWiki\User\UserFactory;
use MediaWiki\User\UserGroupManagerFactory;
use MessageLocalizer;
use Miraheze\ImportDump\Jobs\ImportDumpJob;
use Miraheze\ManageWiki\Helpers\Factories\ModuleFactory;
use RepoGroup;
use stdClass;
use Wikimedia\FileBackend\FileBackend;
use Wikimedia\Rdbms\IConnectionProvider;
use Wikimedia\Rdbms\IDatabase;
use Wikimedia\Rdbms\IExpression;
use Wikimedia\Rdbms\LikeValue;
use Wikimedia\Rdbms\Platform\ISQLPlatform;
use Wikimedia\Rdbms\SelectQueryBuilder;

class ImportDumpRequestManager {

	private const SYSTEM_USERS = [
		'ImportDump Extension',
		'ImportDump Status Update',
		'RequestImport Extension',
		'RequestImport Status Update',
	];

	public const CONSTRUCTOR_OPTIONS = [
		ConfigNames::InterwikiMap,
		ConfigNames::ScriptCommand,
	];

	private IDatabase $dbw;
	private stdClass|false $row;
	private int $ID;

	public function __construct(
		private readonly ActorStoreFactory $actorStoreFactory,
		private readonly IConnectionProvider $connectionProvider,
		private readonly ExtensionRegistry $ExtensionRegistry;
		private readonly InterwikiLookup $interwikiLookup,
		private readonly JobQueueGroupFactory $jobQueueGroupFactory,
		private readonly LinkRenderer $linkRenderer,
		private readonly RepoGroup $repoGroup,
		private readonly MessageLocalizer $messageLocalizer,
		private readonly ServiceOptions $options,
		private readonly UserFactory $userFactory,
		private readonly UserGroupManagerFactory $userGroupManagerFactory,
		private readonly ?ModuleFactory $moduleFactory
	) {
		$options->assertRequiredOptions( self::CONSTRUCTOR_OPTIONS );
	}

	public function loadFromID( int $requestID ) {
		$this->dbw = $this->connectionProvider->getPrimaryDatabase( 'virtual-importdump' );
		$this->ID = $requestID;

		$this->row = $this->dbw->newSelectQueryBuilder()
			->select( ISQLPlatform::ALL_ROWS )
			->from( 'import_requests' )
			->where( [ 'request_id' => $requestID ] )
			->caller( __METHOD__ )
			->fetchRow();
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

		if (
			$this->extensionRegistry->isLoaded( 'Echo' ) &&
			!in_array( $user->getName(), self::SYSTEM_USERS, true )
		) {
			$this->sendNotification( $comment, 'importdump-request-comment', $user );
		}
	}

	public function logStatusUpdate( string $comment, string $newStatus, User $user ): void {
		$requestQueueLink = SpecialPage::getTitleValueFor( 'RequestImportQueue', (string)$this->ID );
		$requestLink = $this->linkRenderer->makeLink( $requestQueueLink, "#{$this->ID}" );

		$logEntry = new ManualLogEntry(
			$this->isPrivate( forced: false ) ? 'importdumpprivate' : 'importdump',
			'statusupdate'
		);

		$logEntry->setPerformer( $user );
		$logEntry->setTarget( $requestQueueLink );

		if ( $comment ) {
			$logEntry->setComment( $comment );
		}

		$logEntry->setParameters(
			[
				'4::requestLink' => Message::rawParam( $requestLink ),
				'5::requestStatus' => mb_strtolower( $this->messageLocalizer->msg(
					"importdump-label-$newStatus"
				)->inContentLanguage()->text() ),
			]
		);

		$logID = $logEntry->insert( $this->dbw );
		$logEntry->publish( $logID );
	}

	public function logStarted( User $user ): void {
		$requestQueueLink = SpecialPage::getTitleValueFor( 'RequestImportQueue', (string)$this->ID );
		$requestLink = $this->linkRenderer->makeLink( $requestQueueLink, "#{$this->ID}" );

		$logEntry = new ManualLogEntry(
			$this->isPrivate( forced: false ) ? 'importdumpprivate' : 'importdump',
			'started'
		);

		$logEntry->setPerformer( $user );
		$logEntry->setTarget( $requestQueueLink );

		$logEntry->setParameters(
			[
				'4::requestTarget' => $this->getTarget(),
				'5::requestLink' => Message::rawParam( $requestLink ),
			]
		);

		$logID = $logEntry->insert( $this->dbw );
		$logEntry->publish( $logID );
	}

	public function sendNotification( string $comment, string $type, User $user ): void {
		$requestLink = SpecialPage::getTitleFor( 'RequestImportQueue', (string)$this->ID )->getFullURL();
		$involvedUsers = array_values( array_filter(
			array_diff( $this->getInvolvedUsers(), [ $user ] )
		) );

		foreach ( $involvedUsers as $receiver ) {
			Event::create( [
				'type' => $type,
				'extra' => [
					'request-id' => $this->ID,
					'request-url' => $requestLink,
					'comment' => $comment,
					'notifyAgent' => true,
				],
				'agent' => $receiver,
			] );
		}
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

	public function getInvolvedUsers(): array {
		return array_unique( array_merge( array_column( $this->getComments(), 'user' ), [ $this->getRequester() ] ) );
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
		$requestLink = $this->linkRenderer->makeLink( $requestQueueLink, "#{$this->ID}" );

		$logEntry = new ManualLogEntry(
			$this->isPrivate( forced: false ) ? 'importdumpprivate' : 'importdump',
			'interwiki'
		);

		$logEntry->setPerformer( $user );
		$logEntry->setTarget( $requestQueueLink );

		$logEntry->setParameters(
			[
				'4::prefix' => $prefix,
				'5::target' => $this->getTarget(),
				'6::requestLink' => Message::rawParam( $requestLink ),
			]
		);

		$logID = $logEntry->insert( $this->dbw );
		$logEntry->publish( $logID );

		return true;
	}

	public function getInterwikiPrefix(): string {
		$dbr = $this->connectionProvider->getReplicaDatabase( $this->getTarget() );
		$sourceHost = parse_url( $this->getSource(), PHP_URL_HOST );
		if ( !$sourceHost ) {
			return '';
		}

		$sourceHost = '://' . $sourceHost;
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

	/** @return string[] */
	public function getUserGroupsFromTarget(): array {
		$userName = $this->getRequester()->getName();
		$remoteUser = $this->actorStoreFactory
			->getUserIdentityLookup( $this->getTarget() )
			->getUserIdentityByName( $userName );

		if ( !$remoteUser ) {
			return [ $this->messageLocalizer->msg( 'importdump-usergroups-none' )->text() ];
		}

		return $this->userGroupManagerFactory
			->getUserGroupManager( $this->getTarget() )
			->getUserGroups( $remoteUser );
	}

	public function getFilePath(): string {
		$fileName = $this->getFileName();

		$localRepo = $this->repoGroup->getLocalRepo();
		$zonePath = $localRepo->getZonePath( 'public' ) . '/ImportDump';

		return $zonePath . '/' . $fileName;
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

	public function getStatus(): string {
		return $this->row->request_status;
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

	public function isPrivate( bool $forced ): bool {
		if ( !$forced && $this->row->request_private ) {
			return true;
		}

		if (
			!$this->extensionRegistry->isLoaded( 'ManageWiki' ) ||
			!$this->moduleFactory ||
			!$this->moduleFactory->isEnabled( 'core' )
		) {
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

	public function setStatus( string $status ): void {
		$this->dbw->newUpdateQueryBuilder()
			->update( 'import_requests' )
			->set( [ 'request_status' => $status ] )
			->where( [ 'request_id' => $this->ID ] )
			->caller( __METHOD__ )
			->execute();
	}

	public function setTarget( string $target ): void {
		$this->dbw->newUpdateQueryBuilder()
			->update( 'import_requests' )
			->set( [ 'request_target' => $target ] )
			->where( [ 'request_id' => $this->ID ] )
			->caller( __METHOD__ )
			->execute();
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
