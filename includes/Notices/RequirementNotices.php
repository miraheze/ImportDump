<?php

declare( strict_types = 1 );

namespace Miraheze\ImportDump\Notices;

use MediaWiki\Context\IContextSource;
use MediaWiki\Html\Html;
use MediaWiki\Message\Message;
use Miraheze\ImportDump\Request\RequestManager;
use Miraheze\ImportDump\Request\RequestStatus;
use Miraheze\ImportDump\Requirements\ExtensionNames;
use function count;
use function in_array;

final class RequirementNotices implements NoticeProvider {

	private const array OPEN_STATUSES = [
		RequestStatus::Pending,
		RequestStatus::Failed,
		RequestStatus::Partial,
	];

	public function __construct(
		private readonly ExtensionNames $extensionNames,
	) {
	}

	/** @inheritDoc */
	public function getAudience(): NoticeAudience {
		return NoticeAudience::Parties;
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
		RequestStatus $status
	): array {
		if ( !in_array( $status, self::OPEN_STATUSES, true ) ) {
			return [];
		}

		$groups = [];
		$kinds = [];
		foreach ( $requestManager->getRequirementProblems() as $problem ) {
			$extension = $this->extensionNames->getName( $context, $problem->name );

			$item = $problem->setting !== '' ?
				$problem->setting . $context->msg( 'word-separator' )->text() .
				$context->msg( 'parentheses', $extension )->text() :
				$extension;

			// Items can run in another direction than the interface, such as setting names, so isolate each one.
			$key = $problem->kind->value;
			$kinds[$key] = $problem->kind;
			$groups[$key] ??= [ 'items' => [], 'blocking' => false ];
			$groups[$key]['items'][] = Html::element( 'bdi', [], $item );
			$groups[$key]['blocking'] = $groups[$key]['blocking'] || $problem->blocking;
		}

		$notices = [];
		foreach ( $groups as $key => $group ) {
			$kind = $kinds[$key];

			// Messages that can be used here:
			// * requestimport-requirement-unknown
			// * requestimport-requirement-missing
			// * requestimport-requirement-setting-unknown
			// * requestimport-requirement-setting
			$notices[] = new RequestNotice(
				severity: $kind->isError() ? NoticeSeverity::Error : NoticeSeverity::Warning,
				html: $context->msg(
					$kind->getMessageKey(),
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
