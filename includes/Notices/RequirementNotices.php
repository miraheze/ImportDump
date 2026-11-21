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

		$notices = [];
		foreach ( $requestManager->getRequirementProblems() as $problem ) {
			// Messages that can be used here:
			// * importdump-requirement-unknown
			// * importdump-requirement-missing
			// * importdump-requirement-setting-unknown
			// * importdump-requirement-setting
			$notices[] = new RequestNotice(
				severity: in_array( $problem['problem'], [ 'missing', 'setting' ], true ) ?
					RequestNotice::SEVERITY_ERROR :
					RequestNotice::SEVERITY_WARNING,
				html: $context->msg( 'importdump-requirement-' . $problem['problem'] )
					->params( $problem['name'], $problem['setting'] )
					->escaped(),
				invalidates: $problem['blocking'],
				declines: false,
			);
		}

		return $notices;
	}
}
