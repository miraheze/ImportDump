<?php

namespace Miraheze\ImportDump\Hooks\Handlers;

use Config;
use ConfigFactory;
use EchoAttributeManager;
use MediaWiki\Block\Hook\GetAllBlockActionsHook;
use MediaWiki\Hook\LoginFormValidErrorMessagesHook;
use MediaWiki\User\Hook\UserGetReservedNamesHook;
use Miraheze\ImportDump\Notifications\EchoNewRequestPresentationModel;
use Miraheze\ImportDump\Notifications\EchoRequestCommentPresentationModel;
use Miraheze\ImportDump\Notifications\EchoRequestStatusUpdatePresentationModel;
use WikiMap;

class Main implements
	GetAllBlockActionsHook,
	LoginFormValidErrorMessagesHook,
	UserGetReservedNamesHook
{

	/** @var Config */
	private $config;

	/**
	 * @param ConfigFactory $configFactory
	 */
	public function __construct( ConfigFactory $configFactory ) {
		$this->config = $configFactory->makeConfig( 'ImportDump' );
	}

	/**
	 * @param array &$reservedUsernames
	 */
	public function onUserGetReservedNames( &$reservedUsernames ) {
		$reservedUsernames[] = 'ImportDump Extension';
		$reservedUsernames[] = 'ImportDump Status Update';
	}

	/**
	 * @param array &$actions
	 */
	public function onGetAllBlockActions( &$actions ) {
		if (
			$this->config->get( 'ImportDumpCentralWiki' ) &&
			!WikiMap::isCurrentWikiId( $this->config->get( 'ImportDumpCentralWiki' ) )
		) {
			return;
		}

		$actions[ 'request-import-dump' ] = 200;
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
		if (
			$this->config->get( 'ImportDumpCentralWiki' ) &&
			!WikiMap::isCurrentWikiId( $this->config->get( 'ImportDumpCentralWiki' ) )
		) {
			return;
		}

		$notificationCategories['importdump-new-request'] = [
			'priority' => 3,
			'tooltip' => 'echo-pref-tooltip-importdump-new-request',
		];

		$notificationCategories['importdump-request-comment'] = [
			'priority' => 3,
			'tooltip' => 'echo-pref-tooltip-importdump-request-comment',
		];

		$notificationCategories['importdump-request-status-update'] = [
			'priority' => 3,
			'tooltip' => 'echo-pref-tooltip-importdump-request-status-update',
		];

		$notifications['importdump-new-request'] = [
			EchoAttributeManager::ATTR_LOCATORS => [
				'EchoUserLocator::locateEventAgent'
			],
			'category' => 'importdump-new-request',
			'group' => 'positive',
			'section' => 'alert',
			'canNotifyAgent' => true,
			'presentation-model' => EchoNewRequestPresentationModel::class,
			'immediate' => true,
		];

		$notifications['importdump-request-comment'] = [
			EchoAttributeManager::ATTR_LOCATORS => [
				'EchoUserLocator::locateEventAgent'
			],
			'category' => 'importdump-request-comment',
			'group' => 'positive',
			'section' => 'alert',
			'canNotifyAgent' => true,
			'presentation-model' => EchoRequestCommentPresentationModel::class,
			'immediate' => true,
		];

		$notifications['importdump-request-status-update'] = [
			EchoAttributeManager::ATTR_LOCATORS => [
				'EchoUserLocator::locateEventAgent'
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
