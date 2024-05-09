<?php

namespace Miraheze\ImportDump;

use ExtensionRegistry;
use FileBackend;
use JobSpecification;
use ManualLogEntry;
use MediaWiki\Config\Config;
use MediaWiki\Config\ServiceOptions;
use MediaWiki\Extension\Notifications\Model\Event;
use MediaWiki\Interwiki\InterwikiLookup;
use MediaWiki\JobQueue\JobQueueGroupFactory;
use MediaWiki\Linker\LinkRenderer;
use MediaWiki\SpecialPage\SpecialPage;
use MediaWiki\User\ActorStoreFactory;
use MediaWiki\User\User;
use MediaWiki\User\UserFactory;
use MediaWiki\User\UserGroupManagerFactory;
use Message;
use MessageLocalizer;
use Miraheze\CreateWiki\Hooks\CreateWikiHookRunner;
use Miraheze\CreateWiki\RemoteWiki;
use Miraheze\ImportDump\Jobs\ImportDumpJob;
use RepoGroup;
use stdClass;
use Wikimedia\Rdbms\IConnectionProvider;
use Wikimedia\Rdbms\IDatabase;
use Wikimedia\Rdbms\SelectQueryBuilder;

class ImportDumpRequestManager {

	private const SYSTEM_USERS = [
		'ImportDump Extension',
		'ImportDump Status Update',
		'RequestImport Extension',
		'RequestImport Status Update',
	];

	public const CONSTRUCTOR_OPTIONS = [
		'ImportDumpCentralWiki',
		'ImportDumpInterwikiMap',
		'ImportDumpScriptCommand',
	];

	/** @var Config */
	private $config;

	/** @var IDatabase */
	private $dbw;

	/** @var int */
	private $ID;

	/** @var ActorStoreFactory */
	private $actorStoreFactory;

	/** @var IConnectionProvider */
	private $connectionProvider;

	/** @var CreateWikiHookRunner|null */
	private $createWikiHookRunner;

	/** @var InterwikiLookup */
	private $interwikiLookup;

	/** @var JobQueueGroupFactory */
	private $jobQueueGroupFactory;

	/** @var MessageLocalizer */
	private $messageLocalizer;

	/** @var LinkRenderer */
	private $linkRenderer;

	/** @var ServiceOptions */
	private $options;

	/** @var RepoGroup */
	private $repoGroup;

	/** @var stdClass|bool */
	private $row;

	/** @var UserFactory */
	private $userFactory;

	/** @var UserGroupManagerFactory */
	private $userGroupManagerFactory;

	/**
	 * @param Config $config
	 * @param ActorStoreFactory $actorStoreFactory
	 * @param IConnectionProvider $connectionProvider
	 * @param InterwikiLookup $interwikiLookup
	 * @param JobQueueGroupFactory $jobQueueGroupFactory
	 * @param LinkRenderer $linkRenderer
	 * @param RepoGroup $repoGroup
	 * @param MessageLocalizer $messageLocalizer
	 * @param ServiceOptions $options
	 * @param UserFactory $userFactory
	 * @param UserGroupManagerFactory $userGroupManagerFactory
	 * @param ?CreateWikiHookRunner $createWikiHookRunner
	 */
	public function __construct(
		Config $config,
		ActorStoreFactory $actorStoreFactory,
		IConnectionProvider $connectionProvider,
		InterwikiLookup $interwikiLookup,
		JobQueueGroupFactory $jobQueueGroupFactory,
		LinkRenderer $linkRenderer,
		RepoGroup $repoGroup,
		MessageLocalizer $messageLocalizer,
		ServiceOptions $options,
		UserFactory $userFactory,
		UserGroupManagerFactory $userGroupManagerFactory,
		?CreateWikiHookRunner $createWikiHookRunner
	) {
		$options->assertRequiredOptions( self::CONSTRUCTOR_OPTIONS );

		$this->config = $config;
		$this->actorStoreFactory = $actorStoreFactory;
		$this->connectionProvider = $connectionProvider;
		$this->createWikiHookRunner = $createWikiHookRunner;
		$this->interwikiLookup = $interwikiLookup;
		$this->jobQueueGroupFactory = $jobQueueGroupFactory;
		$this->linkRenderer = $linkRenderer;
		$this->messageLocalizer = $messageLocalizer;
		$this->options = $options;
		$this->repoGroup = $repoGroup;
		$this->userFactory = $userFactory;
		$this->userGroupManagerFactory = $userGroupManagerFactory;
	}

	/**
	 * @param int $requestID
	 */
	public function fromID( int $requestID ) {
		$this->ID = $requestID;

		$centralWiki = $this->options->get( 'ImportDumpCentralWiki' );
		$this->dbw = $this->connectionProvider->getPrimaryDatabase(
			$centralWiki ?: false
		);

		$this->row = $this->dbw->newSelectQueryBuilder()
			->table( 'import_requests' )
			->field( '*' )
			->where( [ 'request_id' => $requestID ] )
			->caller( __METHOD__ )
			->fetchRow();
	}

	/**
	 * @return bool
	 */
	public function exists(): bool {
		return (bool)$this->row;
	}

	/**
	 * @param string $comment
	 * @param User $user
	 */
	public function addComment( string $comment, User $user ) {
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
			ExtensionRegistry::getInstance()->isLoaded( 'Echo' ) &&
			!in_array( $user->getName(), self::SYSTEM_USERS )
		) {
			$this->sendNotification( $comment, 'importdump-request-comment', $user );
		}
	}

	/**
	 * @param string $comment
	 * @param string $newStatus
	 * @param User $user
	 */
	public function logStatusUpdate( string $comment, string $newStatus, User $user ) {
		$requestQueueLink = SpecialPage::getTitleValueFor( 'RequestImportQueue', (string)$this->ID );
		$requestLink = $this->linkRenderer->makeLink( $requestQueueLink, "#{$this->ID}" );

		$logEntry = new ManualLogEntry(
			$this->isPrivate() ? 'importdumpprivate' : 'importdump',
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
				'5::requestStatus' => strtolower( $this->messageLocalizer->msg(
					'importdump-label-' . $newStatus
				)->inContentLanguage()->text() ),
			]
		);

		$logID = $logEntry->insert( $this->dbw );
		$logEntry->publish( $logID );
	}

	/**
	 * @param User $user
	 */
	public function logStarted( User $user ) {
		$requestQueueLink = SpecialPage::getTitleValueFor( 'RequestImportQueue', (string)$this->ID );
		$requestLink = $this->linkRenderer->makeLink( $requestQueueLink, "#{$this->ID}" );

		$logEntry = new ManualLogEntry(
			$this->isPrivate() ? 'importdumpprivate' : 'importdump',
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

	/**
	 * @param string $comment
	 * @param string $type
	 * @param User $user
	 */
	public function sendNotification( string $comment, string $type, User $user ) {
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

	/**
	 * @return array
	 */
	public function getComments(): array {
		$res = $this->dbw->newSelectQueryBuilder()
			->table( 'import_request_comments' )
			->field( '*' )
			->where( [ 'request_id' => $this->ID ] )
			->orderBy( 'request_comment_timestamp', SelectQueryBuilder::SORT_DESC )
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

	/**
	 * @return array
	 */
	public function getInvolvedUsers(): array {
		return array_unique( array_merge( array_column( $this->getComments(), 'user' ), [ $this->getRequester() ] ) );
	}

	/**
	 * @param string $prefix
	 * @param string $url
	 * @param User $user
	 * @return bool
	 */
	public function insertInterwikiPrefix( string $prefix, string $url, User $user ): bool {
		$dbw = $this->connectionProvider->getPrimaryDatabase(
			$this->getTarget()
		);

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
			$this->isPrivate() ? 'importdumpprivate' : 'importdump',
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

	/**
	 * @return string
	 */
	public function getInterwikiPrefix(): string {
		$dbr = $this->connectionProvider->getReplicaDatabase(
			$this->getTarget()
		);

		$sourceHost = parse_url( $this->getSource(), PHP_URL_HOST );
		if ( !$sourceHost ) {
			return '';
		}

		$sourceHost = '://' . $sourceHost;

		$row = $dbr->newSelectQueryBuilder()
			->table( 'interwiki' )
			->field( 'iw_prefix' )
			->where( [
				'iw_url' . $dbr->buildLike( $dbr->anyString(), $sourceHost, $dbr->anyString() ),
			] )
			->caller( __METHOD__ )
			->fetchRow();

		if ( $row->iw_prefix ?? '' ) {
			return $row->iw_prefix;
		}

		if (
			ExtensionRegistry::getInstance()->isLoaded( 'Interwiki' ) &&
			$this->config->get( 'InterwikiCentralDB' )
		) {
			$dbr = $this->connectionProvider->getReplicaDatabase(
				$this->config->get( 'InterwikiCentralDB' )
			);

			$row = $dbr->newSelectQueryBuilder()
				->table( 'interwiki' )
				->field( 'iw_prefix' )
				->where( [
					'iw_url' . $dbr->buildLike( $dbr->anyString(), $sourceHost, $dbr->anyString() ),
				] )
				->caller( __METHOD__ )
				->fetchRow();

			if ( $row->iw_prefix ?? '' ) {
				return $row->iw_prefix;
			}
		}

		if ( $this->options->get( 'ImportDumpInterwikiMap' ) ) {
			$parsedSource = parse_url( $this->getSource(), PHP_URL_HOST ) ?: '';
			$domain = explode( '.', $parsedSource )[1] ?? '';

			if ( $domain ) {
				$domain .= '.' . ( explode( '.', $parsedSource )[2] ?? '' );
				if ( $this->options->get( 'ImportDumpInterwikiMap' )[$domain] ?? '' ) {
					$domain = $this->options->get( 'ImportDumpInterwikiMap' )[$domain];
					$subdomain = explode( '.', $parsedSource )[0] ?? '';

					if ( $subdomain ) {
						return $domain . ':' . $subdomain;
					}
				}
			}
		}

		return '';
	}

	/**
	 * @return string
	 */
	public function getCommand(): string {
		$command = $this->options->get( 'ImportDumpScriptCommand' );

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

	/**
	 * @return string[]
	 */
	public function getUserGroupsFromTarget() {
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

	/**
	 * @return string
	 */
	public function getFilePath(): string {
		$fileName = $this->getFileName();

		$localRepo = $this->repoGroup->getLocalRepo();
		$zonePath = $localRepo->getZonePath( 'public' ) . '/ImportDump';

		return $zonePath . '/' . $fileName;
	}

	/**
	 * @return string
	 */
	public function getSplitFilePath(): string {
		return FileBackend::splitStoragePath( $this->getFilePath() )[2];
	}

	/**
	 * @return string
	 */
	public function getFileName(): string {
		return $this->getTarget() . '-' . $this->getTimestamp() . '.xml';
	}

	/**
	 * @return bool
	 */
	public function fileExists(): bool {
		$localRepo = $this->repoGroup->getLocalRepo();
		$backend = $localRepo->getBackend();

		if ( $backend->fileExists( [ 'src' => $this->getFilePath() ] ) ) {
			return true;
		}

		return false;
	}

	/**
	 * @return int
	 */
	public function getFileSize(): int {
		if ( !$this->fileExists() ) {
			return 0;
		}

		$localRepo = $this->repoGroup->getLocalRepo();
		$backend = $localRepo->getBackend();

		return (int)$backend->getFileSize( [ 'src' => $this->getFilePath() ] );
	}

	/**
	 * @return string
	 */
	public function getReason(): string {
		return $this->row->request_reason;
	}

	/**
	 * @return User
	 */
	public function getRequester(): User {
		return $this->userFactory->newFromActorId( $this->row->request_actor );
	}

	/**
	 * @return string
	 */
	public function getSource(): string {
		return $this->row->request_source;
	}

	/**
	 * @return string
	 */
	public function getStatus(): string {
		return $this->row->request_status;
	}

	/**
	 * @return string
	 */
	public function getTarget(): string {
		return $this->row->request_target;
	}

	/**
	 * @return string
	 */
	public function getTimestamp(): string {
		return $this->row->request_timestamp;
	}

	/**
	 * @return bool
	 */
	public function isLocked(): bool {
		return (bool)$this->row->request_locked;
	}

	/**
	 * @param bool $forced
	 * @return bool
	 */
	public function isPrivate( bool $forced = false ): bool {
		if ( !$forced && $this->row->request_private ) {
			return true;
		}

		if (
			!ExtensionRegistry::getInstance()->isLoaded( 'CreateWiki' ) ||
			!$this->config->get( 'CreateWikiUsePrivateWikis' ) ||
			!$this->createWikiHookRunner
		) {
			return false;
		}

		$remoteWiki = new RemoteWiki( $this->getTarget(), $this->createWikiHookRunner );
		return (bool)$remoteWiki->isPrivate();
	}

	/**
	 * @param string $fname
	 */
	public function startAtomic( string $fname ) {
		$this->dbw->startAtomic( $fname );
	}

	/**
	 * @param int $locked
	 */
	public function setLocked( int $locked ) {
		$this->dbw->newUpdateQueryBuilder()
			->update( 'import_requests' )
			->set( [ 'request_locked' => $locked ] )
			->where( [ 'request_id' => $this->ID ] )
			->caller( __METHOD__ )
			->execute();
	}

	/**
	 * @param int $private
	 */
	public function setPrivate( int $private ) {
		$this->dbw->newUpdateQueryBuilder()
			->update( 'import_requests' )
			->set( [ 'request_private' => $private ] )
			->where( [ 'request_id' => $this->ID ] )
			->caller( __METHOD__ )
			->execute();
	}

	/**
	 * @param string $reason
	 */
	public function setReason( string $reason ) {
		$this->dbw->newUpdateQueryBuilder()
			->update( 'import_requests' )
			->set( [ 'request_reason' => $reason ] )
			->where( [ 'request_id' => $this->ID ] )
			->caller( __METHOD__ )
			->execute();
	}

	/**
	 * @param string $source
	 */
	public function setSource( string $source ) {
		$this->dbw->newUpdateQueryBuilder()
			->update( 'import_requests' )
			->set( [ 'request_source' => $source ] )
			->where( [ 'request_id' => $this->ID ] )
			->caller( __METHOD__ )
			->execute();
	}

	/**
	 * @param string $status
	 */
	public function setStatus( string $status ) {
		$this->dbw->newUpdateQueryBuilder()
			->update( 'import_requests' )
			->set( [ 'request_status' => $status ] )
			->where( [ 'request_id' => $this->ID ] )
			->caller( __METHOD__ )
			->execute();
	}

	/**
	 * @param string $target
	 */
	public function setTarget( string $target ) {
		$this->dbw->newUpdateQueryBuilder()
			->update( 'import_requests' )
			->set( [ 'request_target' => $target ] )
			->where( [ 'request_id' => $this->ID ] )
			->caller( __METHOD__ )
			->execute();
	}

	/**
	 * @param string $username
	 */
	public function executeJob( string $username ) {
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

	/**
	 * @param string $fname
	 */
	public function endAtomic( string $fname ) {
		$this->dbw->endAtomic( $fname );
	}
}
