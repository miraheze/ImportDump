<?php

declare( strict_types = 1 );

namespace Miraheze\ImportDump\Maintenance;

use MediaWiki\Logging\LogEntryBase;
use MediaWiki\Maintenance\LoggedUpdateMaintenance;
use MediaWiki\RecentChanges\RecentChange;
use MediaWiki\WikiMap\WikiMap;
use Miraheze\ImportDump\Request\RequestManager;
use Miraheze\ImportDump\Request\RequestStatus;
use stdClass;
use Wikimedia\Rdbms\IDatabase;
use function array_any;
use function array_keys;
use function is_array;
use function is_object;
use function is_string;
use function max;
use function mb_strtolower;
use function preg_match;
use function str_ends_with;
use function wfMessage;

class MigrateLogParams extends LoggedUpdateMaintenance {

	private const array LOG_TYPES = [ 'importdump', 'importdumpprivate' ];

	public function __construct() {
		parent::__construct();

		$this->addDescription(
			'Rewrites old import request log entries so they store the request ID and status ' .
			'instead of rendered HTML and translated text.'
		);

		$this->setBatchSize( 200 );
		$this->requireExtension( 'ImportDump' );
	}

	/** @inheritDoc */
	protected function getUpdateKey(): string {
		return 'ImportDump-MigrateLogParams';
	}

	/** @inheritDoc */
	protected function doDBUpdates(): true {
		$dbw = $this->getPrimaryDB( RequestManager::VIRTUAL_DOMAIN );
		if ( !WikiMap::isCurrentWikiDbDomain( $dbw->getDomainID() ) ) {
			$this->output( "Import request logs are not stored on this wiki. Skipping.\n" );
			return true;
		}

		$statuses = $this->getStatusMap();
		$batchSize = max( 1, $this->getBatchSize() );

		$lastID = 0;
		$migrated = 0;
		$skipped = 0;
		$unmapped = 0;

		do {
			$res = $dbw->newSelectQueryBuilder()
				->select( [ 'log_id', 'log_type', 'log_title', 'log_params' ] )
				->from( 'logging' )
				->where( [ 'log_type' => self::LOG_TYPES ] )
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

				// @phan-suppress-next-line SecurityCheck-PHPSerializeInjection Classes are not allowed
				$params = LogEntryBase::extractParams( (string)$row->log_params );
				if ( !is_array( $params ) || !$this->needsMigration( $params ) ) {
					continue;
				}

				$requestID = $this->getRequestID( (string)$row->log_title, $params );
				if ( $requestID === null ) {
					$this->output( "Could not find the request ID for log entry {$row->log_id}. Skipping.\n" );
					$skipped++;
					continue;
				}

				$unmapped += $this->convert( $params, $requestID, $statuses, $row->log_id, $dbw );
				$migrated++;
			}

			$this->commitTransactionRound( __METHOD__ );
		} while ( $count >= $batchSize );

		$this->output(
			"Migrated $migrated log entries. Skipped $skipped. " .
			"Left $unmapped unknown statuses as they were.\n"
		);

		return true;
	}

	private function convert(
		array $params,
		int $requestID,
		array $statuses,
		int|string $logID,
		IDatabase $dbw
	): int {
		$unmapped = 0;
		$converted = [];

		foreach ( $params as $key => $value ) {
			$key = (string)$key;
			if ( str_ends_with( $key, '::requestLink' ) ) {
				$index = (int)$key;
				$converted["$index:request-link:requestID"] = $requestID;
				continue;
			}

			if ( str_ends_with( $key, '::requestStatus' ) ) {
				$index = (int)$key;
				$status = $statuses[(string)$value] ?? null;
				if ( $status === null ) {
					$unmapped++;
					$status = (string)$value;
				}

				$converted["$index:request-status:requestStatus"] = $status;
				continue;
			}

			$converted[$key] = $value;
		}

		$blob = LogEntryBase::makeParamBlob( $converted );

		$dbw->newUpdateQueryBuilder()
			->update( 'logging' )
			->set( [ 'log_params' => $blob ] )
			->where( [ 'log_id' => $logID ] )
			->caller( __METHOD__ )
			->execute();

		$dbw->newUpdateQueryBuilder()
			->update( 'recentchanges' )
			->set( [ 'rc_params' => $blob ] )
			->where( [
				'rc_source' => RecentChange::SRC_LOG,
				'rc_logid' => $logID,
			] )
			->caller( __METHOD__ )
			->execute();

		return $unmapped;
	}

	/** @param array<string|int, mixed> $params */
	private function needsMigration( array $params ): bool {
		return array_any(
			array_keys( $params ),
			static fn ( int|string $key ): bool => str_ends_with( (string)$key, '::requestLink' )
		);
	}

	/**
	 * @param string $title
	 * @param array<string|int, mixed> $params
	 */
	private function getRequestID( string $title, array $params ): ?int {
		if ( preg_match( '!/(\d+)$!', $title, $matches ) ) {
			return (int)$matches[1];
		}

		foreach ( $params as $key => $value ) {
			if ( !str_ends_with( (string)$key, '::requestLink' ) ) {
				continue;
			}

			if ( preg_match( '/#(\d+)\s*<\/a>/', $this->getText( $value ), $matches ) ) {
				return (int)$matches[1];
			}
		}

		return null;
	}

	/**
	 * Old entries hold a ScalarParam, which unserializes to an incomplete class.
	 * Its value is a protected property, so the array key has a prefix.
	 */
	private function getText( mixed $value ): string {
		if ( is_array( $value ) ) {
			return (string)( $value['raw'] ?? '' );
		}

		if ( !is_object( $value ) ) {
			return (string)$value;
		}

		foreach ( (array)$value as $key => $property ) {
			if ( is_string( $property ) && str_ends_with( (string)$key, 'value' ) ) {
				return $property;
			}
		}

		return '';
	}

	/**
	 * Old entries stored the status as lowercased text in the content language.
	 *
	 * @return non-empty-array<string, string>
	 */
	private function getStatusMap(): array {
		$map = [];
		foreach ( RequestStatus::cases() as $case ) {
			$status = $case->value;
			$label = wfMessage( "requestimport-label-$status" )->inContentLanguage()->text();
			$map[mb_strtolower( $label )] = $status;
			$map[$status] = $status;
		}

		return $map;
	}
}

// @codeCoverageIgnoreStart
return MigrateLogParams::class;
// @codeCoverageIgnoreEnd
