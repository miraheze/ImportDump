<?php

namespace Miraheze\ImportDump\Hooks\Handlers;

use EchoAttributeManager;
use MediaWiki\User\Hook\UserGetReservedNamesHook;
use Miraheze\ImportDump\Notifications\EchoNewRequestPresentationModel;
use Miraheze\ImportDump\Notifications\EchoRequestCommentPresentationModel;
use Miraheze\ImportDump\Notifications\EchoRequestStatusUpdatePresentationModel;

class Main implements UserGetReservedNamesHook {

	/**
	 * @param array &$reservedUsernames
	 */
	public function onUserGetReservedNames( &$reservedUsernames ) {
		$reservedUsernames[] = 'ImportDump Extension';
		$reservedUsernames[] = 'ImportDump Status Update';
	}

	/**
	 * @param array &$notifications
	 * @param array &$notificationCategories
	 * @param array &$icons
	 */
	public function onBeforeCreateEchoEvent(
		&$notifications, &$notificationCategories, &$icons
	) {
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
