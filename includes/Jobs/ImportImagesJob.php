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
use MediaWiki\FileRepo\RepoGroup;
use MediaWiki\Maintenance\FakeMaintenance;
use MediaWiki\User\User;
use Miraheze\ImportDump\ConfigNames;
use Miraheze\ImportDump\Progress\ImportHeartbeat;
use Miraheze\ImportDump\Request\RequestManager;
use Miraheze\ImportDump\Request\RequestStage;
use Miraheze\ImportDump\Request\RequestStatus;
use RuntimeException;
use Throwable;
use Wikimedia\Rdbms\IConnectionProvider;
use function basename;
use function bin2hex;
use function copy;
use function get_class;
use function glob;
use function max;
use function random_bytes;
use function rmdir;
use function unlink;

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
		private readonly RepoGroup $repoGroup,
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
			$directory = $this->downloadImages();
			$this->importImages( $directory );
		} catch ( Throwable $t ) {
			MWExceptionHandler::rollbackPrimaryChangesAndLog( $t );
			$this->queueNotification( RequestStatus::Failed, $this->getLogMessage( $t ) );
			return true;
		} finally {
			if ( isset( $directory ) ) {
				$this->removeDirectory( $directory );
			}

			$this->heartbeat->clear( $this->requestID );
		}

		$this->queueNotification( RequestStatus::Complete, '' );
		return true;
	}

	/** Copies the stored files to a local directory, which is the only thing importImages can read. */
	private function downloadImages(): string {
		$directory = wfTempDir() . '/importdump-' . $this->requestID . '-' . bin2hex( random_bytes( 4 ) );
		if ( !wfMkdirParents( $directory, null, __METHOD__ ) ) {
			throw new RuntimeException( "Could not create $directory" );
		}

		$backend = $this->repoGroup->getLocalRepo()->getBackend();
		foreach ( $this->requestManager->listImageFiles() as $path ) {
			$file = $backend->getLocalReference( [ 'src' => $path ] );
			if ( !$file || !copy( $file->getPath(), $directory . '/' . basename( $path ) ) ) {
				$this->removeDirectory( $directory );
				throw new RuntimeException( "Could not read $path from storage" );
			}
		}

		$this->beat();
		return $directory;
	}

	private function removeDirectory( string $directory ): void {
		foreach ( glob( $directory . '/*' ) ?: [] as $file ) {
			unlink( $file );
		}

		rmdir( $directory );
	}

	private function importImages( string $directory ): void {
		$user = User::newSystemUser( RequestManager::EXTENSION_USER, [ 'steal' => true ] );

		$maintenance = new FakeMaintenance;
		$importImages = $maintenance->createChild( ImportImages::class );
		$importImages->setArg( 0, $directory );
		$importImages->setOption( 'user', $user->getName() );
		$importImages->setOption( 'skip-dupes', true );
		$importImages->setOption( 'comment', $this->requestManager->getImportComment() );

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
