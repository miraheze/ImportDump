<?php

namespace Miraheze\ImportDump\Hooks\Handlers;

use MediaWiki\Block\Hook\GetAllBlockActionsHook;
use MediaWiki\Hook\LoginFormValidErrorMessagesHook;
use MediaWiki\User\Hook\UserGetReservedNamesHook;
use MediaWiki\WikiMap\WikiMap;
use Wikimedia\Rdbms\IConnectionProvider;

class Main implements
	GetAllBlockActionsHook,
	LoginFormValidErrorMessagesHook,
	UserGetReservedNamesHook
{

	public function __construct(
		private readonly IConnectionProvider $connectionProvider
	) {
	}

	/** @inheritDoc */
	public function onGetAllBlockActions( &$actions ) {
		$dbr = $this->connectionProvider->getReplicaDatabase( 'virtual-importdump' );
		if ( !WikiMap::isCurrentWikiDbDomain( $dbr->getDomainID() ) ) {
			return;
		}

		$actions[ 'request-import' ] = 200;
	}

	/** @inheritDoc */
	public function onLoginFormValidErrorMessages( array &$messages ) {
		$messages[] = 'importdump-notloggedin';
	}

	/** @inheritDoc */
	public function onUserGetReservedNames( &$reservedUsernames ) {
		$reservedUsernames[] = 'ImportDump Extension';
		$reservedUsernames[] = 'ImportDump Status Update';
		$reservedUsernames[] = 'RequestImport Extension';
		$reservedUsernames[] = 'RequestImport Status Update';
	}
}
