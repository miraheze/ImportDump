<?php

namespace Miraheze\ImportDump\Hooks\Handlers;

use MediaWiki\Extension\Notifications\AttributeManager;
use MediaWiki\Extension\Notifications\Hooks\BeforeCreateEchoEventHook;
use MediaWiki\Extension\Notifications\UserLocator;
use MediaWiki\WikiMap\WikiMap;
use Miraheze\ImportDump\Notifications\EchoImportFailedPresentationModel;
use Miraheze\ImportDump\Notifications\EchoNewRequestPresentationModel;
use Miraheze\ImportDump\Notifications\EchoRequestCommentPresentationModel;
use Miraheze\ImportDump\Notifications\EchoRequestStatusUpdatePresentationModel;
use Wikimedia\Rdbms\IConnectionProvider;

class Notifications implements BeforeCreateEchoEventHook {

	public function __construct(
		private readonly IConnectionProvider $connectionProvider
	) {
	}

	/** @inheritDoc */
	public function onBeforeCreateEchoEvent(
		array &$notifications,
		array &$notificationCategories,
		array &$icons
	): void {
		$dbr = $this->connectionProvider->getReplicaDatabase( 'virtual-importdump' );
		if ( !WikiMap::isCurrentWikiDbDomain( $dbr->getDomainID() ) ) {
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
