<?php

declare( strict_types = 1 );

namespace Miraheze\ImportDump\Requirements;

use MediaWiki\User\ActorStoreFactory;
use MediaWiki\User\UserGroupManagerFactory;
use Miraheze\ImportDump\Hooks\HookRunner;

/** Asks the target wiki for its state, once for each target and requester. */
class TargetStateProvider {

	/** @var array<string, TargetState> */
	private array $states = [];

	public function __construct(
		private readonly ActorStoreFactory $actorStoreFactory,
		private readonly HookRunner $hookRunner,
		private readonly UserGroupManagerFactory $userGroupManagerFactory,
	) {
	}

	/** @return ?string[] The groups of the user on the wiki, or null without an account there. */
	public function getGroups( string $target, string $userName ): ?array {
		$remoteUser = $this->actorStoreFactory
			->getUserIdentityLookup( $target )
			->getUserIdentityByName( $userName );

		if ( !$remoteUser ) {
			return null;
		}

		return $this->userGroupManagerFactory
			->getUserGroupManager( $target )
			->getUserGroups( $remoteUser );
	}

	/** The target wiki must exist. */
	public function getState( string $target, string $userName ): TargetState {
		$key = "$target\n$userName";
		if ( isset( $this->states[$key] ) ) {
			return $this->states[$key];
		}

		$extensions = null;
		$settings = [];
		$rights = [];

		$this->hookRunner->onImportDumpGetTargetState(
			$target,
			$this->getGroups( $target, $userName ) ?? [],
			$extensions,
			$settings,
			$rights
		);

		$this->states[$key] = new TargetState( $extensions, $settings, $rights );
		return $this->states[$key];
	}

	/** Forgets what it was told, for after something changed on the target wiki. */
	public function clear(): void {
		$this->states = [];
	}
}
