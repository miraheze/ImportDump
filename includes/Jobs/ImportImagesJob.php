<?php

declare( strict_types = 1 );

namespace Miraheze\ImportDump\Jobs;

use FilesystemIterator;
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
use Miraheze\ImportDump\Hooks\HookRunner;
use Miraheze\ImportDump\Progress\ImportHeartbeat;
use Miraheze\ImportDump\Request\RequestManager;
use Miraheze\ImportDump\Request\RequestStage;
use Miraheze\ImportDump\Request\RequestStatus;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;
use Throwable;
use Wikimedia\Rdbms\IConnectionProvider;
use function array_slice;
use function count;
use function get_class;
use function intdiv;
use function is_dir;
use function max;
use function mb_substr;
use function min;
use function ob_end_clean;
use function ob_start;
use function preg_match_all;
use function rmdir;
use function time;
use function trim;
use function unlink;
use function wfMkdirParents;
use function wfTempDir;

/**
 * Imports the files of an images request with the importImages maintenance script, a chunk of them
 * per job. When files are left, the job queues another one for them. Files that are already on the wiki
 * are skipped, so it is safe to run this again after a failure.
 */
class ImportImagesJob extends Job {

	public const string JOB_NAME = 'ImportImagesJob';

	private readonly MessageLocalizer $messageLocalizer;

	private readonly int $requestID;
	private readonly int $offset;
	private readonly string $username;

	public function __construct(
		array $params,
		private readonly IConnectionProvider $connectionProvider,
		private readonly Config $config,
		private readonly ImportHeartbeat $heartbeat,
		private readonly HookRunner $hookRunner,
		private readonly RequestManager $requestManager,
		private readonly JobQueueGroupFactory $jobQueueGroupFactory,
	) {
		parent::__construct( self::JOB_NAME, $params );

		$this->requestID = $params['requestid'];
		$this->username = $params['username'];
		$this->offset = (int)( $params['offset'] ?? 0 );

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

		$directory = null;
		$remaining = false;
		$size = 0;

		try {
			$names = $this->requestManager->getImageObjectNames();
			$size = max( 1, (int)$this->config->get( ConfigNames::ImagesChunkSize ) );
			$chunk = array_slice( $names, $this->offset, $size );
			$remaining = $this->offset + $size < count( $names );

			if ( $this->offset === 0 ) {
				$this->queueNotification( RequestStatus::InProgress, '' );
				$this->enterStage();
			}

			// A request that fits in one job is downloaded whole, only larger ones ask for files by name.
			$wanted = $remaining || $this->offset > 0 ? $chunk : [];
			$directory = $chunk ? $this->downloadImages( $wanted ) : null;
			if ( !$directory ) {
				$this->queueNotification(
					RequestStatus::Failed,
					$this->messageLocalizer->msg( 'importdump-images-job-missing' )->inContentLanguage()->text()
				);

				return true;
			}

			$this->importImages( $directory, count( $names ) );
		} catch ( Throwable $t ) {
			MWExceptionHandler::rollbackPrimaryChangesAndLog( $t );
			$this->queueNotification( RequestStatus::Failed, $this->getLogMessage( $t ) );
			return true;
		} finally {
			if ( $directory !== null ) {
				$this->removeDirectory( $directory );
			}

			if ( !$remaining ) {
				$this->heartbeat->clear( $this->requestID );
			}
		}

		if ( $remaining ) {
			$this->queueNext( $this->offset + $size );
			return true;
		}

		$this->queueNotification( RequestStatus::Complete, '' );
		return true;
	}

	/**
	 * Asks the handlers to fill a local directory with the given stored files. Returns null if nothing came back.
	 *
	 * @param list<string> $names
	 */
	private function downloadImages( array $names ): ?string {
		$directory = wfTempDir() . '/' . $this->requestManager->getFileName();
		if ( !is_dir( $directory ) && !wfMkdirParents( $directory, null, __METHOD__ ) ) {
			throw new RuntimeException( "Could not create $directory" );
		}

		$this->hookRunner->onImportDumpJobGetImages( $directory, $this->requestManager, $names );
		$this->beat();

		if ( !$this->countFiles( $directory ) ) {
			$this->removeDirectory( $directory );
			return null;
		}

		return $directory;
	}

	private function countFiles( string $directory ): int {
		if ( !is_dir( $directory ) ) {
			return 0;
		}

		$count = 0;
		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $directory, FilesystemIterator::SKIP_DOTS )
		);

		foreach ( $iterator as $file ) {
			if ( $file instanceof SplFileInfo && $file->isFile() ) {
				$count++;
			}
		}

		return $count;
	}

	private function removeDirectory( string $directory ): void {
		if ( !is_dir( $directory ) ) {
			return;
		}

		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $directory, FilesystemIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::CHILD_FIRST
		);

		foreach ( $iterator as $file ) {
			if ( !$file instanceof SplFileInfo ) {
				continue;
			}

			if ( $file->isDir() && !$file->isLink() ) {
				rmdir( $file->getPathname() );
			} else {
				unlink( $file->getPathname() );
			}
		}

		rmdir( $directory );
	}

	private function importImages( string $directory, int $total ): void {
		$done = $this->offset;
		$lastUpdate = 0;
		$log = '';

		$this->reportProgress( $done, $total );

		// The script prints a line for each file it handles, which is how the progress is followed.
		$follow = function ( string $output ) use ( $total, &$done, &$lastUpdate, &$log ): string {
			$log .= $output;
			$done = min( $total, $done + (int)preg_match_all( '/Importing (?!Files\n)|skipping\n/', $output ) );

			$now = time();
			if ( $now !== $lastUpdate ) {
				$lastUpdate = $now;
				$this->reportProgress( $done, $total );
				$this->beat();
			}

			return '';
		};

		$user = User::newSystemUser( RequestManager::EXTENSION_USER, [ 'steal' => true ] );

		$maintenance = new FakeMaintenance;
		$importImages = $maintenance->createChild( ImportImages::class );
		$importImages->setArg( 0, $directory );
		$importImages->setOption( 'user', $user->getName() );
		$importImages->setOption( 'skip-dupes', true );
		$importImages->setOption( 'search-recursively', true );
		$importImages->setOption( 'comment', $this->requestManager->getImportComment() );

		$success = false;
		ob_start( $follow, 1 );
		try {
			$success = $importImages->execute();
		} finally {
			ob_end_clean();
		}

		$done = min( $total, $this->offset + $this->countFiles( $directory ) );
		$this->reportProgress( $done, $total );
		$this->beat();

		// The script returns false when a file failed or when it found nothing to import.
		if ( $success === false ) {
			throw new RuntimeException( 'importImages failed: ' . mb_substr( trim( $log ), -1000 ) );
		}
	}

	private function reportProgress( int $done, int $total ): void {
		$percent = intdiv( $done * 100, max( 1, $total ) );
		$this->requestManager->setImportPercent( $percent, done: $done, total: $total );
	}

	private function queueNext( int $offset ): void {
		$this->jobQueueGroupFactory->makeJobQueueGroup( $this->requestManager->getTarget() )->push(
			new JobSpecification(
				self::JOB_NAME,
				[
					'offset' => $offset,
					'requestid' => $this->requestID,
					'username' => $this->username,
				]
			)
		);
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
