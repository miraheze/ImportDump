<?php

namespace Miraheze\ImportDump\Hooks\Handlers;

use MediaWiki\User\Hook\UserGetReservedNamesHook;

class Default implements UserGetReservedNamesHook {

	/**
	 * @param array &$reservedUsernames
	 */
	public function onUserGetReservedNames( &$reservedUsernames ) {
		$reservedUsernames[] = 'ImportDump extension';
		$reservedUsernames[] = 'ImportDump status update';
	}
