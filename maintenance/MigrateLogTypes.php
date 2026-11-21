<?php

declare( strict_types = 1 );

namespace Miraheze\ImportDump\Maintenance;

use MediaWiki\Maintenance\LoggedUpdateMaintenance;
use MediaWiki\RecentChanges\RecentChange;
use MediaWiki\WikiMap\WikiMap;
use Miraheze\ImportDump\Request\RequestManager;

class MigrateLogTypes extends LoggedUpdateMaintenance {

	/** The old log type and the one that replaces it. */
	private const array LOG_TYPES = [
		'importdump' => 'requestimport',
		'importdumpprivate' => 'requestimportprivate',
	];

	public function __construct() {
		parent::__construct();

		$this->addDescription( 'Moves the old import request log entries to the log types that replaced them.' );
		$this->requireExtension( 'ImportDump' );
	}

	/** @inheritDoc */
	protected function getUpdateKey(): string {
		return 'ImportDump-MigrateLogTypes';
	}

	/** @inheritDoc */
	protected function doDBUpdates(): true {
		$dbw = $this->getPrimaryDB( RequestManager::VIRTUAL_DOMAIN );
		if ( !WikiMap::isCurrentWikiDbDomain( $dbw->getDomainID() ) ) {
			$this->output( "Import request logs are not stored on this wiki. Skipping.\n" );
			return true;
		}

		$migrated = 0;
		foreach ( self::LOG_TYPES as $oldType => $newType ) {
			$dbw->newUpdateQueryBuilder()
				->update( 'logging' )
				->set( [ 'log_type' => $newType ] )
				->where( [ 'log_type' => $oldType ] )
				->caller( __METHOD__ )
				->execute();

			$migrated += $dbw->affectedRows();

			$dbw->newUpdateQueryBuilder()
				->update( 'recentchanges' )
				->set( [ 'rc_log_type' => $newType ] )
				->where( [
					'rc_source' => RecentChange::SRC_LOG,
					'rc_log_type' => $oldType,
				] )
				->caller( __METHOD__ )
				->execute();
		}

		$this->output( "Moved $migrated log entries to the new log types.\n" );

		return true;
	}
}

// @codeCoverageIgnoreStart
return MigrateLogTypes::class;
// @codeCoverageIgnoreEnd
