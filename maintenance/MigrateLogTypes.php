<?php

namespace Miraheze\ImportDump\Maintenance;

use MediaWiki\Maintenance\LoggedUpdateMaintenance;
use MediaWiki\RecentChanges\RecentChange;
use MediaWiki\WikiMap\WikiMap;
use stdClass;

class MigrateLogTypes extends LoggedUpdateMaintenance {

	/** The old log type and the one that replaces it. */
	private const array LOG_TYPES = [
		'importdump' => 'requestimport',
		'importdumpprivate' => 'requestimportprivate',
	];

	public function __construct() {
		parent::__construct();

		$this->addDescription( 'Moves the old import request log entries to the log types that replaced them.' );

		$this->setBatchSize( 200 );
		$this->requireExtension( 'ImportDump' );
	}

	/** @inheritDoc */
	protected function getUpdateKey(): string {
		return 'ImportDump-MigrateLogTypes';
	}

	/** @inheritDoc */
	protected function doDBUpdates(): true {
		$dbw = $this->getPrimaryDB( 'virtual-importdump' );
		if ( !WikiMap::isCurrentWikiDbDomain( $dbw->getDomainID() ) ) {
			$this->output( "Import request logs are not stored on this wiki. Skipping.\n" );
			return true;
		}

		$batchSize = max( 1, $this->getBatchSize() );

		$lastID = 0;
		$migrated = 0;

		do {
			$res = $dbw->newSelectQueryBuilder()
				->select( [ 'log_id', 'log_type' ] )
				->from( 'logging' )
				->where( [ 'log_type' => array_keys( self::LOG_TYPES ) ] )
				->andWhere( $dbw->expr( 'log_id', '>', $lastID ) )
				->orderBy( 'log_id' )
				->limit( $batchSize )
				->caller( __METHOD__ )
				->fetchResultSet();

			$count = 0;
			$this->beginTransactionRound( __METHOD__ );

			foreach ( $res as $row ) {
				'@phan-var stdClass $row';
				$count++;
				$lastID = (int)$row->log_id;
				$type = self::LOG_TYPES[$row->log_type];

				$dbw->newUpdateQueryBuilder()
					->update( 'logging' )
					->set( [ 'log_type' => $type ] )
					->where( [ 'log_id' => $row->log_id ] )
					->caller( __METHOD__ )
					->execute();

				$dbw->newUpdateQueryBuilder()
					->update( 'recentchanges' )
					->set( [ 'rc_log_type' => $type ] )
					->where( [
						'rc_source' => RecentChange::SRC_LOG,
						'rc_logid' => $row->log_id,
					] )
					->caller( __METHOD__ )
					->execute();

				$migrated++;
			}

			$this->commitTransactionRound( __METHOD__ );
		} while ( $count >= $batchSize );

		$this->output( "Moved $migrated log entries to the new log types.\n" );

		return true;
	}
}

// @codeCoverageIgnoreStart
return MigrateLogTypes::class;
// @codeCoverageIgnoreEnd
