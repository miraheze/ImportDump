<?php

namespace Miraheze\ImportDump\Tests;

use MediaWiki\User\ActorStoreFactory;
use MediaWiki\User\UserGroupManager;
use MediaWiki\User\UserGroupManagerFactory;
use MediaWiki\User\UserIdentityLookup;
use MediaWiki\User\UserIdentityValue;

/**
 * Answers the lookup of the requester on the target wiki, which the made up target wikis
 * that the tests use cannot, as their databases do not exist.
 */
trait MocksRemoteGroupsTrait {

	/** @param ?string[] $groups The groups of the requester on the target, or null for no account there. */
	private function setRemoteGroups( ?array $groups ): void {
		$lookup = $this->createMock( UserIdentityLookup::class );
		$lookup->method( 'getUserIdentityByName' )->willReturn(
			$groups === null ? null : new UserIdentityValue( 1, 'Test user' )
		);

		$actorStoreFactory = $this->createMock( ActorStoreFactory::class );
		$actorStoreFactory->method( 'getUserIdentityLookup' )->willReturn( $lookup );

		$groupManager = $this->createMock( UserGroupManager::class );
		$groupManager->method( 'getUserGroups' )->willReturn( $groups ?? [] );

		$groupManagerFactory = $this->createMock( UserGroupManagerFactory::class );
		$groupManagerFactory->method( 'getUserGroupManager' )->willReturn( $groupManager );

		$this->setService( 'ActorStoreFactory', $actorStoreFactory );
		$this->setService( 'UserGroupManagerFactory', $groupManagerFactory );
	}
}
