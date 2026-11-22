<?php

declare( strict_types = 1 );

namespace Miraheze\ImportDump\Maintenance;

use MediaWiki\Maintenance\LoggedUpdateMaintenance;
use MediaWiki\WikiMap\WikiMap;
use Miraheze\ImportDump\Request\RequestManager;
use Wikimedia\Rdbms\IConnectionProvider;

class MigrateEchoTypes extends LoggedUpdateMaintenance {

	/** The old notification type and the one that replaces it. */
	private const array TYPES = [
		'importdump-import-failed' => 'requestimport-import-failed',
		'importdump-new-request' => 'requestimport-new-request',
		'importdump-request-comment' => 'requestimport-request-comment',
		'importdump-request-status-update' => 'requestimport-request-status-update',
	];

	/** The delivery methods that have a preference for each type. */
	private const array METHODS = [ 'web', 'email' ];

	public function __construct() {
		parent::__construct();

		$this->addDescription(
			'Moves the notification preferences and existing notifications to the new notification types.'
		);

		$this->requireExtension( 'ImportDump' );
	}

	/** @inheritDoc */
	protected function getUpdateKey(): string {
		return 'ImportDump-MigrateEchoTypes';
	}

	/** @inheritDoc */
	protected function doDBUpdates(): true {
		$migrated = $this->migratePreferences() + $this->migrateGlobalPreferences();

		$notifications = $this->migrateNotifications();

		$this->output( "Moved $migrated preferences and $notifications notifications to the new types.\n" );

		return true;
	}

	private function migratePreferences(): int {
		$dbw = $this->getPrimaryDB();

		$migrated = 0;
		foreach ( self::TYPES as $oldType => $newType ) {
			foreach ( self::METHODS as $method ) {
				$dbw->newUpdateQueryBuilder()
					->ignore()
					->update( 'user_properties' )
					->set( [ 'up_property' => "echo-subscriptions-$method-$newType" ] )
					->where( [ 'up_property' => "echo-subscriptions-$method-$oldType" ] )
					->caller( __METHOD__ )
					->execute();

				$migrated += $dbw->affectedRows();
			}
		}

		return $migrated;
	}

	private function migrateGlobalPreferences(): int {
		if ( !$this->getServiceContainer()->getExtensionRegistry()->isLoaded( 'GlobalPreferences' ) ) {
			return 0;
		}

		$dbw = $this->getPrimaryDB( RequestManager::VIRTUAL_DOMAIN );
		if ( !WikiMap::isCurrentWikiDbDomain( $dbw->getDomainID() ) ) {
			return 0;
		}

		$provider = $this->getServiceContainer()->get(
			'GlobalPreferences.GlobalPreferencesConnectionProvider'
		);
		'@phan-var IConnectionProvider $provider';

		$globalDbw = $provider->getPrimaryDatabase();

		$migrated = 0;
		foreach ( self::TYPES as $oldType => $newType ) {
			foreach ( self::METHODS as $method ) {
				$globalDbw->newUpdateQueryBuilder()
					->ignore()
					->update( 'global_preferences' )
					->set( [ 'gp_property' => "echo-subscriptions-$method-$newType" ] )
					->where( [ 'gp_property' => "echo-subscriptions-$method-$oldType" ] )
					->caller( __METHOD__ )
					->execute();

				$migrated += $globalDbw->affectedRows();
			}
		}

		return $migrated;
	}

	private function migrateNotifications(): int {
		if ( !$this->getServiceContainer()->getExtensionRegistry()->isLoaded( 'Echo' ) ) {
			return 0;
		}

		$dbw = $this->getPrimaryDB( RequestManager::VIRTUAL_DOMAIN );
		if ( !WikiMap::isCurrentWikiDbDomain( $dbw->getDomainID() ) ) {
			return 0;
		}

		$echoDbw = $this->getPrimaryDB();

		$migrated = 0;
		foreach ( self::TYPES as $oldType => $newType ) {
			$echoDbw->newUpdateQueryBuilder()
				->update( 'echo_event' )
				->set( [ 'event_type' => $newType ] )
				->where( [ 'event_type' => $oldType ] )
				->caller( __METHOD__ )
				->execute();

			$migrated += $echoDbw->affectedRows();
		}

		return $migrated;
	}
}

// @codeCoverageIgnoreStart
return MigrateEchoTypes::class;
// @codeCoverageIgnoreEnd
