<?php

declare( strict_types = 1 );

namespace Miraheze\ImportDump\Hooks\Handlers;

use MediaWiki\Block\Hook\GetAllBlockActionsHook;
use MediaWiki\Specials\Hook\LoginFormValidErrorMessagesHook;
use MediaWiki\User\Hook\UserGetReservedNamesHook;
use MediaWiki\WikiMap\WikiMap;
use Miraheze\ImportDump\Request\RequestManager;
use Wikimedia\Rdbms\IConnectionProvider;

class Main implements
	GetAllBlockActionsHook,
	LoginFormValidErrorMessagesHook,
	UserGetReservedNamesHook
{

	public function __construct(
		private readonly IConnectionProvider $connectionProvider,
	) {
	}

	/** @inheritDoc */
	public function onGetAllBlockActions( &$actions ) {
		$dbr = $this->connectionProvider->getReplicaDatabase( RequestManager::VIRTUAL_DOMAIN );
		if ( !WikiMap::isCurrentWikiDbDomain( $dbr->getDomainID() ) ) {
			return;
		}

		$actions['request-import'] = 200;
	}

	/** @inheritDoc */
	public function onLoginFormValidErrorMessages( array &$messages ) {
		$messages[] = 'requestimport-notloggedin';
	}

	/** @inheritDoc */
	public function onUserGetReservedNames( &$reservedUsernames ) {
		$reservedUsernames[] = RequestManager::EXTENSION_USER;
		$reservedUsernames[] = RequestManager::STATUS_UPDATE_USER;
	}
}
