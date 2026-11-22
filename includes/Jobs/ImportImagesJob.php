<?php

declare( strict_types = 1 );

namespace Miraheze\ImportDump\Jobs;

use ImportImages;
use MediaWiki\Config\Config;
use MediaWiki\Context\RequestContext;
use MediaWiki\Exception\MWExceptionHandler;
use MediaWiki\JobQueue\Job;
use MediaWiki\JobQueue\JobQueueGroupFactory;
use MediaWiki\JobQueue\JobSpecification;
use MediaWiki\Language\MessageLocalizer;
use MediaWiki\Maintenance\FakeMaintenance;
use MediaWiki\User\User;
use Miraheze\ImportDump\ConfigNames;
use Miraheze\ImportDump\Progress\ImportHeartbeat;
use Miraheze\ImportDump\Request\RequestManager;
use Miraheze\ImportDump\Request\RequestStage;
use Miraheze\ImportDump\Request\RequestStatus;
use Throwable;
use Wikimedia\Rdbms\IConnectionProvider;
use function get_class;
use function max;

/**
 * Imports the files of an images request with the importImages maintenance script.
 * Files that are already on the wiki are skipped, so it is safe to run this again after a failure.
 */
class ImportImagesJob extends Job {

	public const string JOB_NAME = 'ImportImagesJob';

	private readonly MessageLocalizer $messageLocalizer;

	private readonly int $requestID;
	private readonly string $username;

	public function __construct(
		array $params,
		private readonly IConnectionProvider $connectionProvider,
		private readonly Config $config,
		private readonly ImportHeartbeat $heartbeat,
		private readonly RequestManager $requestManager,
		private readonly JobQueueGroupFactory $jobQueueGroupFactory,
	) {
		parent::__construct( self::JOB_NAME, $params );

		$this->requestID = $params['requestid'];
		$this->username = $params['username'];

		$this->messageLocalizer = RequestContext::getMain();
		$this->executionFlags |= self::JOB_NO_EXPLICIT_TRX_ROUND;
	}

	/** @inheritDoc */
	public function run(): true {
		$this->requestManager->loadFromID( $this->requestID );
		if ( $this->requestManager->getStatus() === RequestStatus::Complete ) {
			return true;
		}

		$this->beat();

		try {
			if ( !$this->requestManager->fileExists() ) {
				$this->queueNotification(
					RequestStatus::Failed,
					$this->messageLocalizer->msg( 'importdump-images-job-missing' )->inContentLanguage()->text()
				);

				return true;
			}

			$this->queueNotification( RequestStatus::InProgress, '' );
			$this->enterStage();
			$this->importImages();
		} catch ( Throwable $t ) {
			MWExceptionHandler::rollbackPrimaryChangesAndLog( $t );
			$this->queueNotification( RequestStatus::Failed, $this->getLogMessage( $t ) );
			return true;
		} finally {
			$this->heartbeat->clear( $this->requestID );
		}

		$this->queueNotification( RequestStatus::Complete, '' );
		return true;
	}

	private function importImages(): void {
		$user = User::newSystemUser( RequestManager::EXTENSION_USER, [ 'steal' => true ] );

		$maintenance = new FakeMaintenance;
		$importImages = $maintenance->createChild( ImportImages::class );
		$importImages->setArg( 0, $this->requestManager->getSplitFilePath() );
		$importImages->setOption( 'user', $user->getName() );
		$importImages->setOption( 'skip-dupes', true );
		$importImages->setOption( 'comment', $this->messageLocalizer->msg(
			'importdump-images-comment',
			$this->requestManager->getSource()
		)->inContentLanguage()->text() );

		$importImages->execute();
		$this->beat();
	}

	private function enterStage(): void {
		// Save the stage now, otherwise the notify jobs cannot update the request while the import runs.
		$ticket = $this->connectionProvider->getEmptyTransactionTicket( __METHOD__ );
		$this->requestManager->setStage( RequestStage::Images );
		$this->connectionProvider->commitAndWaitForReplication( __METHOD__, $ticket );
	}

	private function beat(): void {
		$hours = $this->config->get( ConfigNames::StalledImportHours );
		$this->heartbeat->beat( $this->requestID, max( 1, (int)( $hours ?: 24 ) ) * 3600 );
	}

	private function queueNotification( RequestStatus $status, string $error ): void {
		$dbr = $this->connectionProvider->getReplicaDatabase( RequestManager::VIRTUAL_DOMAIN );
		$this->jobQueueGroupFactory->makeJobQueueGroup( $dbr->getDomainID() )->push(
			new JobSpecification(
				ImportDumpNotifyJob::JOB_NAME,
				[
					'joberror' => $error,
					'requestid' => $this->requestID,
					'status' => $status->value,
					'username' => $this->username,
				]
			)
		);
	}

	private function getLogMessage( Throwable $t ): string {
		return '[' . $this->getRequestId() . ']   ' . get_class( $t ) . ': ' . $t->getMessage();
	}

	public function allowRetries(): false {
		return false;
	}
}
