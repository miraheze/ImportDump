<?php

namespace Miraheze\ImportDump\Notices;

use MediaWiki\Context\IContextSource;
use MediaWiki\Html\Html;
use MediaWiki\Message\Message;
use Miraheze\ImportDump\ExtensionNames;
use Miraheze\ImportDump\RequestManager;
use Miraheze\ImportDump\RequestStatus;

final class RequirementNotices implements NoticeProvider {

	private const array OPEN_STATUSES = [
		RequestStatus::STATUS_PENDING,
		RequestStatus::STATUS_FAILED,
		RequestStatus::STATUS_PARTIAL,
	];

	public function __construct(
		private readonly ExtensionNames $extensionNames,
	) {
	}

	/** @inheritDoc */
	public function getAudience(): string {
		return self::AUDIENCE_PARTIES;
	}

	/** @inheritDoc */
	public function isAlwaysAtTop(): false {
		return false;
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
			$extension = $this->extensionNames->getName( $context, $problem['name'] );

			$item = $problem['setting'] !== '' ?
				$problem['setting'] . $context->msg( 'word-separator' )->text() .
				$context->msg( 'parentheses', $extension )->text() :
				$extension;

			// Items can run in another direction than the interface, such as setting names, so isolate each one.
			$groups[$type] ??= [ 'items' => [], 'blocking' => false ];
			$groups[$type]['items'][] = Html::element( 'bdi', [], $item );
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
				html: $context->msg(
					'importdump-requirement-' . $type,
					Message::rawParam( $context->getLanguage()->listToText( $group['items'] ) )
				)->numParams( count( $group['items'] ) )
					->escaped(),
				invalidates: $group['blocking'],
				declines: false,
				pinned: false,
			);
		}

		return $notices;
	}
}
