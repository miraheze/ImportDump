<?php

namespace Miraheze\ImportDump;

use Config;
use EchoEvent;
use ExtensionRegistry;
use GlobalVarConfig;
use ManualLogEntry;
use MediaWiki\Config\ServiceOptions;
use MediaWiki\Interwiki\InterwikiLookup;
use MediaWiki\Linker\LinkRenderer;
use MediaWiki\User\UserFactory;
use MediaWiki\User\UserGroupManagerFactory;
use Message;
use MessageLocalizer;
use Miraheze\CreateWiki\RemoteWiki;
use SpecialPage;
use stdClass;
use User;
use UserRightsProxy;
use Wikimedia\Rdbms\DBConnRef;
use Wikimedia\Rdbms\ILBFactory;

class ImportDumpRequestManager {

	private const SYSTEM_USERS = [
		'ImportDump Extension',
		'ImportDump Status Update',
	];

	public const CONSTRUCTOR_OPTIONS = [
		'ImportDumpCentralWiki',
		'ImportDumpInterwikiMap',
		'ImportDumpScriptCommand',
		'UploadDirectory',
	];

	/** @var Config */
	private $config;

	/** @var DBConnRef */
	private $dbw;

	/** @var int */
	private $ID;

	/** @var ILBFactory */
	private $dbLoadBalancerFactory;

	/** @var InterwikiLookup */
	private $interwikiLookup;

	/** @var MessageLocalizer */
	private $messageLocalizer;

	/** @var LinkRenderer */
	private $linkRenderer;

	/** @var ServiceOptions */
	private $options;

	/** @var stdClass|bool */
	private $row;

	/** @var UserFactory */
	private $userFactory;

	/** @var UserGroupManagerFactory */
	private $userGroupManagerFactory;

	/**
	 * @param Config $config
	 * @param ILBFactory $dbLoadBalancerFactory
	 * @param InterwikiLookup $interwikiLookup
	 * @param LinkRenderer $linkRenderer
	 * @param MessageLocalizer $messageLocalizer
	 * @param ServiceOptions $options
	 * @param UserFactory $userFactory
	 * @param UserGroupManagerFactory $userGroupManagerFactory
	 */
	public function __construct(
		Config $config,
		ILBFactory $dbLoadBalancerFactory,
		InterwikiLookup $interwikiLookup,
		LinkRenderer $linkRenderer,
		MessageLocalizer $messageLocalizer,
		ServiceOptions $options,
		UserFactory $userFactory,
		UserGroupManagerFactory $userGroupManagerFactory
	) {
		$options->assertRequiredOptions( self::CONSTRUCTOR_OPTIONS );

		$this->config = $config;
		$this->dbLoadBalancerFactory = $dbLoadBalancerFactory;
		$this->interwikiLookup = $interwikiLookup;
		$this->linkRenderer = $linkRenderer;
		$this->messageLocalizer = $messageLocalizer;
		$this->options = $options;
		$this->userFactory = $userFactory;
		$this->userGroupManagerFactory = $userGroupManagerFactory;
	}

	/**
	 * @param int $requestID
	 */
	public function fromID( int $requestID ) {
		$this->ID = $requestID;

		$centralWiki = $this->options->get( 'ImportDumpCentralWiki' );
		if ( $centralWiki ) {
			$this->dbw = $this->dbLoadBalancerFactory->getMainLB(
				$centralWiki
			)->getConnection( DB_PRIMARY, [], $centralWiki );
		} else {
			$this->dbw = $this->dbLoadBalancerFactory->getMainLB()->getConnection( DB_PRIMARY );
		}

		$this->row = $this->dbw->selectRow(
			'importdump_requests',
			'*',
			[
				'request_id' => $requestID,
			],
			__METHOD__
		);
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
		$this->dbw->insert(
			'importdump_request_comments',
			[
				'request_id' => $this->ID,
				'request_comment_text' => $comment,
				'request_comment_timestamp' => $this->dbw->timestamp(),
				'request_comment_actor' => $user->getActorId(),
			],
			__METHOD__
		);

		if ( !in_array( $user->getName(), self::SYSTEM_USERS ) ) {
			$this->sendNotification( $comment, 'importdump-request-comment', $user );
		}
	}

	/**
	 * @param string $comment
	 * @param string $newStatus
	 * @param User $user
	 */
	public function logStatusUpdate( string $comment, string $newStatus, User $user ) {
		$requestQueueLink = SpecialPage::getTitleValueFor( 'ImportDumpRequestQueue', (string)$this->ID );
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
	 * @param string $comment
	 * @param string $type
	 * @param User $user
	 */
	public function sendNotification( string $comment, string $type, User $user ) {
		$requestLink = SpecialPage::getTitleFor( 'ImportDumpRequestQueue', (string)$this->ID )->getFullURL();

		$involvedUsers = array_values( array_filter(
			array_diff( $this->getInvolvedUsers(), [ $user ] )
		) );

		foreach ( $involvedUsers as $receiver ) {
			EchoEvent::create( [
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
		$res = $this->dbw->select(
			'importdump_request_comments',
			'*',
			[
				'request_id' => $this->ID,
			],
			__METHOD__,
			[
				'ORDER BY' => 'request_comment_timestamp DESC',
			]
		);

		if ( !$res ) {
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
		return array_unique( array_column( $this->getComments(), 'user' ) + [ $this->getRequester() ] );
	}

	/**
	 * @param string $prefix
	 * @param string $url
	 * @param User $user
	 * @return bool
	 */
	public function insertInterwikiPrefix( string $prefix, string $url, User $user ): bool {
		$dbw = $this->dbLoadBalancerFactory->getMainLB(
			$this->getTarget()
		)->getConnection( DB_PRIMARY, [], $this->getTarget() );

		$dbw->insert(
			'interwiki',
			[
				'iw_prefix' => $prefix,
				'iw_url' => $url,
				'iw_api' => '',
				'iw_local' => 0,
				'iw_trans' => 0,
			],
			__METHOD__,
			[ 'IGNORE' ]
		);

		if ( $dbw->affectedRows() === 0 ) {
			return false;
		}

		$this->interwikiLookup->invalidateCache( $prefix );

		$requestQueueLink = SpecialPage::getTitleValueFor( 'ImportDumpRequestQueue', (string)$this->ID );
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
		$dbr = $this->dbLoadBalancerFactory->getMainLB(
			$this->getTarget()
		)->getConnection( DB_REPLICA, [], $this->getTarget() );

		$sourceHost = parse_url( $this->getSource(), PHP_URL_HOST );
		if ( !$sourceHost ) {
			return '';
		}

		$sourceHost = '://' . $sourceHost;

		$row = $dbr->selectRow(
			'interwiki',
			[
				'iw_prefix',
			],
			[
				'iw_url' . $dbr->buildLike( $dbr->anyString(), $sourceHost, $dbr->anyString() ),
			],
			__METHOD__
		);

		if ( $row->iw_prefix ?? '' ) {
			return $row->iw_prefix;
		}

		if (
			ExtensionRegistry::getInstance()->isLoaded( 'Interwiki' ) &&
			$this->config->get( 'InterwikiCentralDB' )
		) {
			$dbr = $this->dbLoadBalancerFactory->getMainLB(
				$this->config->get( 'InterwikiCentralDB' )
			)->getConnection( DB_REPLICA, [], $this->config->get( 'InterwikiCentralDB' ) );

			$row = $dbr->selectRow(
				'interwiki',
				[
					'iw_prefix',
				],
				[
					'iw_url' . $dbr->buildLike( $dbr->anyString(), $sourceHost, $dbr->anyString() ),
				],
				__METHOD__
			);

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
		$blankConfig = new GlobalVarConfig( '' );

		$command = $this->options->get( 'ImportDumpScriptCommand' );

		if ( !$this->getInterwikiPrefix() ) {
			$command = preg_replace( '/--username-prefix=?/', '', $command );
		}

		return str_replace( [
			'{IP}',
			'{wiki}',
			'{username-prefix}',
			'{file}',
		], [
			$blankConfig->get( 'IP' ),
			$this->getTarget(),
			$this->getInterwikiPrefix(),
			$this->getFilePath(),
		], $command );
	}

	/**
	 * @return string[]
	 */
	public function getUserGroupsFromTarget() {
		$userName = $this->getRequester()->getName();
		$userRightsProxy = UserRightsProxy::newFromName( $this->getTarget(), $userName );

		if ( !$userRightsProxy ) {
			return [ $this->messageLocalizer->msg( 'importdump-usergroups-none' )->text() ];
		}

		return $this->userGroupManagerFactory
			->getUserGroupManager( $this->getTarget() )
			->getUserGroups( $userRightsProxy );
	}

	/**
	 * @return string
	 */
	public function getFilePath(): string {
		$fileName = $this->getTarget() . '-' . $this->getTimestamp() . '.xml';

		return $this->options->get( 'UploadDirectory' ) . '/ImportDump/' . $fileName;
	}

	/**
	 * @return int
	 */
	public function getFileSize(): int {
		if ( !file_exists( $this->getFilePath() ) ) {
			return 0;
		}

		return (int)filesize( $this->getFilePath() );
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
			!$this->config->get( 'CreateWikiUsePrivateWikis' )
		) {
			return false;
		}

		$remoteWiki = new RemoteWiki( $this->getTarget() );
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
		$this->dbw->update(
			'importdump_requests',
			[
				'request_locked' => $locked,
			],
			[
				'request_id' => $this->ID,
			],
			__METHOD__
		);
	}

	/**
	 * @param int $private
	 */
	public function setPrivate( int $private ) {
		$this->dbw->update(
			'importdump_requests',
			[
				'request_private' => $private,
			],
			[
				'request_id' => $this->ID,
			],
			__METHOD__
		);
	}

	/**
	 * @param string $reason
	 */
	public function setReason( string $reason ) {
		$this->dbw->update(
			'importdump_requests',
			[
				'request_reason' => $reason,
			],
			[
				'request_id' => $this->ID,
			],
			__METHOD__
		);
	}

	/**
	 * @param string $source
	 */
	public function setSource( string $source ) {
		$this->dbw->update(
			'importdump_requests',
			[
				'request_source' => $source,
			],
			[
				'request_id' => $this->ID,
			],
			__METHOD__
		);
	}

	/**
	 * @param string $status
	 */
	public function setStatus( string $status ) {
		$this->dbw->update(
			'importdump_requests',
			[
				'request_status' => $status,
			],
			[
				'request_id' => $this->ID,
			],
			__METHOD__
		);
	}

	/**
	 * @param string $target
	 */
	public function setTarget( string $target ) {
		$this->dbw->update(
			'importdump_requests',
			[
				'request_target' => $target,
			],
			[
				'request_id' => $this->ID,
			],
			__METHOD__
		);
	}

	/**
	 * @param string $fname
	 */
	public function endAtomic( string $fname ) {
		$this->dbw->endAtomic( $fname );
	}
}
