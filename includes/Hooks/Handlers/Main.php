<?php

namespace Miraheze\ImportDump\Hooks\Handlers;

use EchoAttributeManager;
use MediaWiki\User\Hook\UserGetReservedNamesHook;
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
		$notificationCategories['importdump-comment'] = [
			'priority' => 3,
			'tooltip' => 'echo-pref-tooltip-importdump-comment',
		];

		$notificationCategories['importdump-status-update'] = [
			'priority' => 3,
			'tooltip' => 'echo-pref-tooltip-importdump-status-update',
		];

		$notifications['importdump-comment'] = [
			EchoAttributeManager::ATTR_LOCATORS => [
				'EchoUserLocator::locateEventAgent'
			],
			'category' => 'importdump-comment',
			'group' => 'positive',
			'section' => 'alert',
			'canNotifyAgent' => true,
			'presentation-model' => EchoRequestCommentPresentationModel::class,
			'immediate' => true,
		];

		$notifications['importdump-status-update'] = [
			EchoAttributeManager::ATTR_LOCATORS => [
				'EchoUserLocator::locateEventAgent'
			],
			'category' => 'importdump-status-update',
			'group' => 'positive',
			'section' => 'alert',
			'canNotifyAgent' => true,
			'presentation-model' => EchoRequestStatusUpdatePresentationModel::class,
			'immediate' => true,
		];
	}
}
