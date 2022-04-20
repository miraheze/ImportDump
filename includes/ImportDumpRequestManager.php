<?php

namespace Miraheze\ImportDump;

use MediaWiki\Config\ServiceOptions;
use MediaWiki\User\UserFactory;
use MessageLocalizer;
use Wikimedia\Rdbms\LBFactory;

class CreateWikiNotificationsManager {

	public const CONSTRUCTOR_OPTIONS = [
		'ImportDumpCentralWiki',
	];

	/** @var int */
	private $id;

	/** @var LBFactory */
	private $lbFactory;

	/** @var MessageLocalizer */
	private $messageLocalizer;

	/** @var ServiceOptions */
	private $options;

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
		$this->id = $requestID;
	}
}
