<?php

namespace Miraheze\ImportDump;

use ExtensionRegistry;
use MediaWiki\Config\ServiceOptions;
use MediaWiki\User\UserFactory;
use MessageLocalizer;
use Miraheze\CreateWiki\RemoteWiki;
use stdClass;
use User;
use Wikimedia\Rdbms\DBConnRef;
use Wikimedia\Rdbms\LBFactory;

class ImportDumpRequestManager {

	public const CONSTRUCTOR_OPTIONS = [
		'ImportDumpCentralWiki',
	];

	/** @var DBConnRef */
	private $dbr;

	/** @var int */
	private $ID;

	/** @var LBFactory */
	private $lbFactory;

	/** @var MessageLocalizer */
	private $messageLocalizer;

	/** @var ServiceOptions */
	private $options;

	/** @var stdClass|bool */
	private $row;

	/** @var UserFactory */
	private $userFactory;

	/**
	 * @param LBFactory $lbFactory
	 * @param MessageLocalizer $messageLocalizer
	 * @param ServiceOptions $options
	 * @param UserFactory $userFactory
	 */
	public function __construct(
		LBFactory $lbFactory,
		MessageLocalizer $messageLocalizer,
		ServiceOptions $options,
		UserFactory $userFactory
	) {
		$options->assertRequiredOptions( self::CONSTRUCTOR_OPTIONS );

		$this->lbFactory = $lbFactory;
		$this->messageLocalizer = $messageLocalizer;

		$this->options = $options;
		$this->userFactory = $userFactory;
	}

	/**
	 * @param int $requestID
	 */
	public function fromID( int $requestID ) {
		$this->ID = $requestID;

		if ( $this->options->get( 'ImportDumpCentralWiki' ) ) {
			$this->dbr = $this->lbFactory->getMainLB(
				$this->options->get( 'ImportDumpCentralWiki' )
			)->getConnectionRef( DB_REPLICA, [], $this->options->get( 'ImportDumpCentralWiki' ) );
		} else {
			$this->dbr = $this->lbFactory->getMainLB()->getConnectionRef( DB_REPLICA );
		}

		$this->row = $this->dbr->selectRow(
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
	 * @return array
	 */
	public function getComments(): array {
		$row = $this->dbr->selectRow(
			'importdump_requests',
			'*',
			[
				'request_id' => $this->ID,
			],
			__METHOD__
		);

		if ( !$row ) {
			return [];
		}

		$user = $this->userFactory->newFromActorId( $row->request_comment_actor );

		return [
			'comment' => $row->request_comment_text,
			'timestamp' => $row->request_comment_timestamp,
			'user' => $user,
		];
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
	 * @return ?string
	 */
	public function getSource(): ?string {
		return $this->row->request_source;
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

		$remoteWiki = new RemoteWiki( $this->getTarget() );
		return (bool)$remoteWiki->isPrivate();
	}
}
