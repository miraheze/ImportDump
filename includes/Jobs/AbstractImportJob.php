<?php

declare( strict_types = 1 );

namespace Miraheze\ImportDump\Jobs;

use MediaWiki\Config\Config;
use MediaWiki\Context\RequestContext;
use MediaWiki\Exception\MWExceptionHandler;
use MediaWiki\JobQueue\Job;
use MediaWiki\JobQueue\JobQueueGroupFactory;
use MediaWiki\JobQueue\JobSpecification;
use MediaWiki\Language\MessageLocalizer;
use Miraheze\ImportDump\ConfigNames;
use Miraheze\ImportDump\Progress\ImportHeartbeat;
use Miraheze\ImportDump\Request\RequestManager;
use Miraheze\ImportDump\Request\RequestStage;
use Miraheze\ImportDump\Request\RequestStatus;
use Throwable;
use Wikimedia\Rdbms\IConnectionProvider;
use function get_class;
use function max;
use function time;

/**
 * What a job that imports the file of a request has in common. It keeps the heartbeat that shows
 * the import is alive, and it tells the requester how it went with a ImportDumpNotifyJob.
 */
abstract class AbstractImportJob extends Job {

	private const int HEARTBEAT_INTERVAL = 60;

	protected readonly MessageLocalizer $messageLocalizer;

	protected readonly int $requestID;
	protected readonly string $username;

	private int $lastBeat = 0;

	public function __construct(
		string $command,
		array $params,
		protected readonly IConnectionProvider $connectionProvider,
		protected readonly Config $config,
		protected readonly ImportHeartbeat $heartbeat,
		protected readonly RequestManager $requestManager,
		protected readonly JobQueueGroupFactory $jobQueueGroupFactory,
	) {
		parent::__construct( $command, $params );

		$this->requestID = $params['requestid'];
		$this->username = $params['username'];

		$this->messageLocalizer = RequestContext::getMain();
		$this->executionFlags |= self::JOB_NO_EXPLICIT_TRX_ROUND;
	}

	/**
	 * Does the import. It tells the requester itself if it cannot be done, and then returns false.
	 *
	 * @return bool Whether the import is complete.
	 */
	abstract protected function execute(): bool;

	/** @inheritDoc */
	public function run(): true {
		$this->requestManager->loadFromID( $this->requestID );
		if ( $this->requestManager->getStatus() === RequestStatus::Complete ) {
			// Don't rerun a job that is already completed.
			return true;
		}

		$this->beat( force: true );

		try {
			if ( !$this->execute() ) {
				return true;
			}
		} catch ( Throwable $t ) {
			// We want to handle any potential errors gracefully.
			MWExceptionHandler::rollbackPrimaryChangesAndLog( $t );
			$this->notifyFailed( $this->getLogMessage( $t ) );
			return true;
		} finally {
			$this->heartbeat->clear( $this->requestID );
			$this->cleanup();
		}

		$this->queueNotification( RequestStatus::Complete, '' );
		return true;
	}

	/** Called when the import ends, however it ends. */
	protected function cleanup(): void {
	}

	protected function notifyFailed( string $error ): void {
		$this->queueNotification( RequestStatus::Failed, $error );
	}

	protected function queueNotification( RequestStatus $status, string $error ): void {
		$this->jobQueueGroupFactory->makeJobQueueGroup( $this->getLoggingWiki() )->push(
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

	protected function saveStage( RequestStage $stage ): void {
		// Save the stage now. Otherwise this job keeps the request locked until it ends,
		// and the notify jobs cannot update the request while the import runs.
		$ticket = $this->connectionProvider->getEmptyTransactionTicket( __METHOD__ );
		$this->requestManager->setStage( $stage );
		$this->connectionProvider->commitAndWaitForReplication( __METHOD__, $ticket );
	}

	protected function beat( bool $force ): void {
		$now = time();
		if ( !$force && $now - $this->lastBeat < self::HEARTBEAT_INTERVAL ) {
			return;
		}

		$this->lastBeat = $now;

		$hours = $this->config->get( ConfigNames::StalledImportHours );
		$ttl = max( 1, (int)( $hours ?: 24 ) ) * 3600;

		$this->heartbeat->beat( $this->requestID, $ttl );
	}

	private function getLogMessage( Throwable $t ): string {
		// This is the request ID from Telemetry.
		$requestId = $this->getRequestId();
		$type = get_class( $t );
		$message = $t->getMessage();

		return "[$requestId]   $type: $message";
	}

	private function getLoggingWiki(): string {
		$dbr = $this->connectionProvider->getReplicaDatabase( RequestManager::VIRTUAL_DOMAIN );
		return $dbr->getDomainID();
	}

	public function allowRetries(): false {
		return false;
	}
}
