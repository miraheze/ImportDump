<?php

namespace Miraheze\ImportDump\Hooks\Handlers;

use MediaWiki\Block\Hook\GetAllBlockActionsHook;
use MediaWiki\Extension\Notifications\AttributeManager;
use MediaWiki\Extension\Notifications\UserLocator;
use MediaWiki\Hook\LoginFormValidErrorMessagesHook;
use MediaWiki\User\Hook\UserGetReservedNamesHook;
use MediaWiki\WikiMap\WikiMap;
use Miraheze\ImportDump\Notifications\EchoImportFailedPresentationModel;
use Miraheze\ImportDump\Notifications\EchoNewRequestPresentationModel;
use Miraheze\ImportDump\Notifications\EchoRequestCommentPresentationModel;
use Miraheze\ImportDump\Notifications\EchoRequestStatusUpdatePresentationModel;
use Wikimedia\Rdbms\IConnectionProvider;

class Main implements
	GetAllBlockActionsHook,
	LoginFormValidErrorMessagesHook,
	UserGetReservedNamesHook
{

	/** @var IConnectionProvider */
	private $connectionProvider;

	/**
	 * @param IConnectionProvider $connectionProvider
	 */
	public function __construct( IConnectionProvider $connectionProvider ) {
		$this->connectionProvider = $connectionProvider;
	}

	/**
	 * @param array &$reservedUsernames
	 */
	public function onUserGetReservedNames( &$reservedUsernames ) {
		$reservedUsernames[] = 'ImportDump Extension';
		$reservedUsernames[] = 'ImportDump Status Update';
		$reservedUsernames[] = 'RequestImport Extension';
		$reservedUsernames[] = 'RequestImport Status Update';
	}

	/**
	 * @param array &$actions
	 */
	public function onGetAllBlockActions( &$actions ) {
		$dbr = $this->connectionProvider->getReplicaDatabase( 'virtual-importdump' );
		if ( !WikiMap::isCurrentWikiId( $dbr->getDBname() ?? '' ) ) {
			return;
		}

		$actions[ 'request-import' ] = 200;
	}

	/**
	 * @param array &$messages
	 */
	public function onLoginFormValidErrorMessages( array &$messages ) {
		$messages[] = 'importdump-notloggedin';
	}

	/**
	 * @param array &$notifications
	 * @param array &$notificationCategories
	 * @param array &$icons
	 */
	public function onBeforeCreateEchoEvent( &$notifications, &$notificationCategories, &$icons ) {
		$dbr = $this->connectionProvider->getReplicaDatabase( 'virtual-importdump' );
		if ( !WikiMap::isCurrentWikiId( $dbr->getDBname() ?? '' ) ) {
			return;
		}

		$notificationCategories['importdump-import-failed'] = [
			'priority' => 1,
			'no-dismiss' => [ 'all' ],
		];

		$notificationCategories['importdump-new-request'] = [
			'priority' => 2,
			'no-dismiss' => [ 'all' ],
		];

		$notificationCategories['importdump-request-comment'] = [
			'priority' => 3,
			'no-dismiss' => [ 'email' ],
			'tooltip' => 'echo-pref-tooltip-importdump-request-comment',
		];

		$notificationCategories['importdump-request-status-update'] = [
			'priority' => 3,
			'no-dismiss' => [ 'email' ],
			'tooltip' => 'echo-pref-tooltip-importdump-request-status-update',
		];

		$notifications['importdump-import-failed'] = [
			AttributeManager::ATTR_LOCATORS => [
				[ [ UserLocator::class, 'locateEventAgent' ] ],
			],
			'category' => 'importdump-import-failed',
			'group' => 'positive',
			'section' => 'alert',
			'canNotifyAgent' => true,
			'presentation-model' => EchoImportFailedPresentationModel::class,
			'immediate' => true,
		];

		$notifications['importdump-new-request'] = [
			AttributeManager::ATTR_LOCATORS => [
				[ [ UserLocator::class, 'locateEventAgent' ] ],
			],
			'category' => 'importdump-new-request',
			'group' => 'positive',
			'section' => 'alert',
			'canNotifyAgent' => true,
			'presentation-model' => EchoNewRequestPresentationModel::class,
			'immediate' => true,
		];

		$notifications['importdump-request-comment'] = [
			AttributeManager::ATTR_LOCATORS => [
				[ [ UserLocator::class, 'locateEventAgent' ] ],
			],
			'category' => 'importdump-request-comment',
			'group' => 'positive',
			'section' => 'alert',
			'canNotifyAgent' => true,
			'presentation-model' => EchoRequestCommentPresentationModel::class,
			'immediate' => true,
		];

		$notifications['importdump-request-status-update'] = [
			AttributeManager::ATTR_LOCATORS => [
				[ [ UserLocator::class, 'locateEventAgent' ] ],
			],
			'category' => 'importdump-request-status-update',
			'group' => 'positive',
			'section' => 'alert',
			'canNotifyAgent' => true,
			'presentation-model' => EchoRequestStatusUpdatePresentationModel::class,
			'immediate' => true,
		];
	}
}
