<?php

declare( strict_types = 1 );

namespace Miraheze\ImportDump\Jobs;

use ImportImages;
use MediaWiki\Config\Config;
use MediaWiki\JobQueue\JobQueueGroupFactory;
use MediaWiki\Maintenance\FakeMaintenance;
use MediaWiki\User\User;
use Miraheze\ImportDump\Progress\ImportHeartbeat;
use Miraheze\ImportDump\Request\RequestManager;
use Miraheze\ImportDump\Request\RequestStage;
use Miraheze\ImportDump\Request\RequestStatus;
use Wikimedia\Rdbms\IConnectionProvider;

/**
 * Imports the files of an images request with the importImages maintenance script.
 * Files that are already on the wiki are skipped, so it is safe to run this again after a failure.
 */
class ImportImagesJob extends AbstractImportJob {

	public const string JOB_NAME = 'ImportImagesJob';

	public function __construct(
		array $params,
		IConnectionProvider $connectionProvider,
		Config $config,
		ImportHeartbeat $heartbeat,
		RequestManager $requestManager,
		JobQueueGroupFactory $jobQueueGroupFactory,
	) {
		parent::__construct(
			self::JOB_NAME,
			$params,
			$connectionProvider,
			$config,
			$heartbeat,
			$requestManager,
			$jobQueueGroupFactory
		);
	}

	/** @inheritDoc */
	protected function execute(): bool {
		if ( !$this->requestManager->fileExists() ) {
			$this->queueNotification(
				RequestStatus::Failed,
				$this->messageLocalizer->msg( 'importdump-images-job-missing' )->inContentLanguage()->text()
			);

			return false;
		}

		$this->queueNotification( RequestStatus::InProgress, '' );
		$this->saveStage( RequestStage::Images );
		$this->importImages();

		return true;
	}

	private function importImages(): void {
		$user = User::newSystemUser( RequestManager::EXTENSION_USER, [ 'steal' => true ] );

		$maintenance = new FakeMaintenance;
		$importImages = $maintenance->createChild( ImportImages::class );
		$importImages->setArg( 0, $this->requestManager->getSplitFilePath() );
		$importImages->setOption( 'user', $user->getName() );
		$importImages->setOption( 'skip-dupes', true );
		$importImages->setOption( 'comment', $this->requestManager->getImportComment() );

		$importImages->execute();
		$this->beat( force: true );
	}
}
