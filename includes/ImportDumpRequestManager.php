<?php

namespace Miraheze\ImportDump;

use Config;
use ExtensionRegistry;
use GlobalVarConfig;
use MediaWiki\Config\ServiceOptions;
use MediaWiki\User\UserFactory;
use MediaWiki\User\UserGroupManagerFactory;
use MessageLocalizer;
use Miraheze\CreateWiki\RemoteWiki;
use stdClass;
use User;
use UserRightsProxy;
use Wikimedia\Rdbms\DBConnRef;
use Wikimedia\Rdbms\ILBFactory;

class ImportDumpRequestManager {

	public const CONSTRUCTOR_OPTIONS = [
		'ImportDumpCentralWiki',
		'ImportDumpInterwikiMap',
		'ImportDumpScriptCommand',
	];

	/** @var Config */
	private $config;

	/** @var DBConnRef */
	private $dbw;

	/** @var int */
	private $ID;

	/** @var ILBFactory */
	private $dbLoadBalancerFactory;

	/** @var MessageLocalizer */
	private $messageLocalizer;

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
	 * @param MessageLocalizer $messageLocalizer
	 * @param ServiceOptions $options
	 * @param UserFactory $userFactory
	 * @param UserGroupManagerFactory $userGroupManagerFactory
	 */
	public function __construct(
		Config $config,
		ILBFactory $dbLoadBalancerFactory,
		MessageLocalizer $messageLocalizer,
		ServiceOptions $options,
		UserFactory $userFactory,
		UserGroupManagerFactory $userGroupManagerFactory
	) {
		$options->assertRequiredOptions( self::CONSTRUCTOR_OPTIONS );

		$this->config = $config;
		$this->dbLoadBalancerFactory = $dbLoadBalancerFactory;
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

		if ( $this->options->get( 'ImportDumpCentralWiki' ) ) {
			$this->dbw = $this->dbLoadBalancerFactory->getMainLB(
				$this->options->get( 'ImportDumpCentralWiki' )
			)->getConnectionRef( DB_PRIMARY, [], $this->options->get( 'ImportDumpCentralWiki' ) );
		} else {
			$this->dbw = $this->dbLoadBalancerFactory->getMainLB()->getConnectionRef( DB_PRIMARY );
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
	 * @return string
	 */
	public function getInterwikiPrefix(): string {
		if ( $this->options->get( 'ImportDumpInterwikiMap' ) ) {
			$parsedSource = parse_url( $this->getSource() )['host'] ?? '';
			$domain = explode( '.', $parsedSource )[1] ?? '';
			$domain .= '.' . ( explode( '.', $parsedSource )[2] ?? '' );

			if ( $domain ) {
				if ( $this->options->get( 'ImportDumpInterwikiMap' )[$domain] ?? '' ) {
					$domain = $this->options->get( 'ImportDumpInterwikiMap' )[$domain];
					$subdomain = explode( '.', $parsedSource )[0] ?? '';

					if ( $subdomain ) {
						return $domain . ':' . $subdomain;
					}
				}
			}
		}

		$dbr = $this->dbLoadBalancerFactory->getMainLB(
			$this->getTarget()
		)->getConnectionRef( DB_REPLICA, [], $this->getTarget() );

		$row = $dbr->selectRow(
			'interwiki',
			[
				'iw_prefix',
			],
			[
				'iw_url' . $dbr->buildLike( $this->getSource(), $dbr->anyString() ),
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
			)->getConnectionRef( DB_REPLICA, [], $this->config->get( 'InterwikiCentralDB' ) );

			$row = $dbr->selectRow(
				'interwiki',
				[
					'iw_prefix',
				],
				[
					'iw_url' . $dbr->buildLike( $this->getSource(), $dbr->anyString() ),
				],
				__METHOD__
			);

			if ( $row->iw_prefix ?? '' ) {
				return $row->iw_prefix;
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

		$userNamePrefix = $this->getInterwikiPrefix() ?:
			$this->messageLocalizer->msg( 'importdump-unknown-username-prefix' )->text();

		return str_replace( [
			'{IP}',
			'{wiki}',
			'{username-prefix}',
			'{file}',
		], [
			$blankConfig->get( 'IP' ),
			$this->getTarget(),
			$userNamePrefix,
			$this->getFile(),
		], $command );
	}

	/**
	 * @return string[]
	 */
	public function getUserGroupsFromTarget() {
		$userName = $this->getRequester()->getName();
		$userRightsProxy = UserRightsProxy::newFromName( $userName );

		return $this->userGroupManagerFactory
			->getUserGroupManager( $this->getTarget() )
			->getUserGroups( $userRightsProxy );
	}

	/**
	 * @return string
	 */
	public function getFile(): string {
		return $this->row->request_file;
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
	public function isPrivate(): bool {
		if ( !ExtensionRegistry::getInstance()->isLoaded( 'CreateWiki' ) ) {
			return false;
		}

		// @phan-suppress-next-line PhanUndeclaredClassMethod
		$remoteWiki = new RemoteWiki( $this->getTarget() );

		// @phan-suppress-next-line PhanUndeclaredClassMethod
		return (bool)$remoteWiki->isPrivate();
	}

	/**
	 * @param string $fname
	 */
	public function startAtomic( string $fname ) {
		$this->dbw->startAtomic( $fname );
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
