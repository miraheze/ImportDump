<?php

namespace Miraheze\ImportDump\Jobs;

use Config;
use ConfigFactory;
use ExtensionRegistry;
use FakeMaintenance;
use ImportStreamSource;
use Job;
use MediaWiki\Extension\Notifications\Model\Event;
use MediaWiki\MainConfigNames;
use MediaWiki\Permissions\UltimateAuthority;
use MediaWiki\User\UserFactory;
use MessageLocalizer;
use Miraheze\ImportDump\Hooks\ImportDumpHookRunner;
use Miraheze\ImportDump\ImportDumpRequestManager;
use Miraheze\ImportDump\ImportDumpStatus;
use RebuildRecentchanges;
use RebuildTextIndex;
use RefreshLinks;
use RequestContext;
use SiteStatsInit;
use SiteStatsUpdate;
use SpecialPage;
use Throwable;
use Title;
use User;
use WikiImporterFactory;
use Wikimedia\Rdbms\ILBFactory;

class ImportDumpNotifyJob extends Job
	implements ImportDumpStatus {

	public const JOB_NAME = 'ImportDumpNotifyJob';

	/** @var int */
	private $requestID;

	/** @var string */
	private $type;

	/** @var Config */
	private $config;

	/** @var ImportDumpRequestManager */
	private $importDumpRequestManager;

	/** @var MessageLocalizer */
	private $messageLocalizer;

	/** @var UserFactory */
	private $userFactory;

	/**
	 * @param Title $title
	 * @param array $params
	 * @param ConfigFactory $configFactory
	 * @param ImportDumpRequestManager $importDumpRequestManager
	 * @param UserFactory $userFactory
	 */
	public function __construct(
		Title $title,
		array $params,
		ConfigFactory $configFactory,
		ImportDumpRequestManager $importDumpRequestManager,
		UserFactory $userFactory
	) {
		parent::__construct( self::JOB_NAME, $params );

		$this->requestID = $params['requestid'];
		$this->type = $params['type'];

		$this->importDumpRequestManager = $importDumpRequestManager;
		$this->userFactory = $userFactory;

		$this->config = $configFactory->makeConfig( 'ImportDump' );
		$this->messageLocalizer = RequestContext::getMain();
	}

	/**
	 * @return bool
	 */
	public function run(): bool {
		if ( $this->type === 'failed' ) {
			$this->notifyFailed();
		}

		return true;
	}

	private function notifyFailed() {
		$notifiedUsers = array_filter(
			array_map(
				function ( string $userName ): ?User {
					return $this->userFactory->newFromName( $userName );
				}, $this->config->get( 'ImportDumpUsersNotifiedOnFailedImports' )
			)
		);

		$requestLink = SpecialPage::getTitleFor( 'RequestImportDumpQueue', (string)$this->requestID )->getFullURL();

		foreach ( $notifiedUsers as $receiver ) {
			if (
				!$receiver->isAllowed( 'handle-import-dump-requests' ) ||
				(
					$this->importDumpRequestManager->isPrivate() &&
					!$receiver->isAllowed( 'view-private-import-dump-requests' )
				)
			) {
				continue;
			}

			Event::create( [
				'type' => 'importdump-import-failed',
				'extra' => [
					'request-id' => $this->requestID,
					'request-url' => $requestLink,
					'reason' => $this->getLastError(),
					'notifyAgent' => true,
				],
				'agent' => $receiver,
			] );
		}

		$commentUser = User::newSystemUser( 'ImportDump Status Update' );
		$comment = $this->messageLocalizer->msg( 'importdump-import-failed-comment' )
			->inContentLanguage()
			->escaped();

		$this->importDumpRequestManager->addComment( $comment, $commentUser );
		$this->importDumpRequestManager->sendNotification( $comment, 'importdump-request-comment', $commentUser );
	}
}
