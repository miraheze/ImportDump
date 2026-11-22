<?php

declare( strict_types = 1 );

namespace Miraheze\ImportDump\Hooks\Handlers;

use MediaWiki\Extension\Notifications\Hooks\BeforeCreateEchoEventHook;
use MediaWiki\WikiMap\WikiMap;
use Miraheze\ImportDump\Notifications\EchoImportFailedPresentationModel;
use Miraheze\ImportDump\Notifications\EchoNewRequestPresentationModel;
use Miraheze\ImportDump\Notifications\EchoRequestAttentionPresentationModel;
use Miraheze\ImportDump\Notifications\EchoRequestCommentPresentationModel;
use Miraheze\ImportDump\Notifications\EchoRequestStatusUpdatePresentationModel;
use Miraheze\ImportDump\Request\RequestManager;
use Wikimedia\Rdbms\IConnectionProvider;

class Notifications implements BeforeCreateEchoEventHook {

	public function __construct(
		private readonly IConnectionProvider $connectionProvider,
	) {
	}

	/** @inheritDoc */
	public function onBeforeCreateEchoEvent(
		array &$notifications,
		array &$notificationCategories,
		array &$icons
	): void {
		$dbr = $this->connectionProvider->getReplicaDatabase( RequestManager::VIRTUAL_DOMAIN );
		if ( !WikiMap::isCurrentWikiDbDomain( $dbr->getDomainID() ) ) {
			return;
		}

		$notificationCategories['requestimport-attention'] = [
			'priority' => 3,
			'no-dismiss' => [ 'email' ],
			'tooltip' => 'echo-pref-tooltip-requestimport-attention',
		];

		$notificationCategories['requestimport-import-failed'] = [
			'priority' => 1,
			'no-dismiss' => [ 'all' ],
		];

		$notificationCategories['requestimport-new-request'] = [
			'priority' => 2,
			'no-dismiss' => [ 'all' ],
		];

		$notificationCategories['requestimport-request-comment'] = [
			'priority' => 3,
			'no-dismiss' => [ 'email' ],
			'tooltip' => 'echo-pref-tooltip-requestimport-request-comment',
		];

		$notificationCategories['requestimport-request-status-update'] = [
			'priority' => 3,
			'no-dismiss' => [ 'email' ],
			'tooltip' => 'echo-pref-tooltip-requestimport-request-status-update',
		];

		$notifications['requestimport-attention'] = [
			'category' => 'requestimport-attention',
			'group' => 'neutral',
			'section' => 'alert',
			'presentation-model' => EchoRequestAttentionPresentationModel::class,
			'immediate' => true,
		];

		$notifications['requestimport-import-failed'] = [
			'category' => 'requestimport-import-failed',
			'group' => 'positive',
			'section' => 'alert',
			'presentation-model' => EchoImportFailedPresentationModel::class,
			'immediate' => true,
		];

		$notifications['requestimport-new-request'] = [
			'category' => 'requestimport-new-request',
			'group' => 'positive',
			'section' => 'alert',
			'presentation-model' => EchoNewRequestPresentationModel::class,
			'immediate' => true,
		];

		$notifications['requestimport-request-comment'] = [
			'category' => 'requestimport-request-comment',
			'group' => 'positive',
			'section' => 'alert',
			'presentation-model' => EchoRequestCommentPresentationModel::class,
			'immediate' => true,
		];

		$notifications['requestimport-request-status-update'] = [
			'category' => 'requestimport-request-status-update',
			'group' => 'positive',
			'section' => 'alert',
			'presentation-model' => EchoRequestStatusUpdatePresentationModel::class,
			'immediate' => true,
		];
	}
}
