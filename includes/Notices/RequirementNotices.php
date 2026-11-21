<?php

namespace Miraheze\ImportDump\Notices;

use MediaWiki\Context\IContextSource;
use Miraheze\ImportDump\RequestManager;
use Miraheze\ImportDump\RequestStatus;

final class RequirementNotices implements NoticeProvider {

	private const array OPEN_STATUSES = [
		RequestStatus::STATUS_PENDING,
		RequestStatus::STATUS_FAILED,
		RequestStatus::STATUS_PARTIAL,
	];

	/** @inheritDoc */
	public function getAudience(): string {
		return self::AUDIENCE_PARTIES;
	}

	/** @inheritDoc */
	public function requiresTargetAccess(): true {
		return true;
	}

	/** @inheritDoc */
	public function getNotices(
		RequestManager $requestManager,
		IContextSource $context,
		string $status
	): array {
		if ( !in_array( $status, self::OPEN_STATUSES, true ) ) {
			return [];
		}

		$groups = [];
		foreach ( $requestManager->getRequirementProblems() as $problem ) {
			$type = $problem['problem'];
			$groups[$type] ??= [ 'items' => [], 'blocking' => false ];
			$groups[$type]['items'][] = $problem['setting'] !== '' ?
				$problem['setting'] . ' (' . $problem['name'] . ')' :
				$problem['name'];

			$groups[$type]['blocking'] = $groups[$type]['blocking'] || $problem['blocking'];
		}

		$notices = [];
		foreach ( $groups as $type => $group ) {
			// Messages that can be used here:
			// * importdump-requirement-unknown
			// * importdump-requirement-missing
			// * importdump-requirement-setting-unknown
			// * importdump-requirement-setting
			$notices[] = new RequestNotice(
				severity: in_array( $type, [ 'missing', 'setting' ], true ) ?
					RequestNotice::SEVERITY_ERROR :
					RequestNotice::SEVERITY_WARNING,
				html: $context->msg( 'importdump-requirement-' . $type )
					->params( $context->getLanguage()->listToText( $group['items'] ) )
					->numParams( count( $group['items'] ) )
					->escaped(),
				invalidates: $group['blocking'],
				declines: false,
				pinned: false,
			);
		}

		return $notices;
	}
}
