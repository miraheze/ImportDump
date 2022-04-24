<?php

namespace Miraheze\ImportDump\Hooks\Handlers;

use MediaWiki\User\Hook\UserGetReservedNamesHook;

class Main implements UserGetReservedNamesHook {

	/**
	 * @param array &$reservedUsernames
	 */
	public function onUserGetReservedNames( &$reservedUsernames ) {
		$reservedUsernames[] = 'ImportDump Extension';
		$reservedUsernames[] = 'ImportDump Status Update';
	}
}
