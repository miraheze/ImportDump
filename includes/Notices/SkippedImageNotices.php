<?php

declare( strict_types = 1 );

namespace Miraheze\ImportDump\Notices;

use MediaWiki\Context\IContextSource;
use MediaWiki\Html\Html;
use Miraheze\ImportDump\Request\RequestManager;
use Miraheze\ImportDump\Request\RequestStatus;
use Miraheze\ImportDump\Upload\SkippedFile;
use function array_map;
use function array_slice;
use function count;

/**
 * An archive of images is still imported when a few of its files cannot be, so that one bad file
 * does not hold back the rest. The files that are left out need to be known, so they can be requested again.
 */
final class SkippedImageNotices implements NoticeProvider {

	/** The most file names that are in the text of the notice. */
	private const int NAMED = 3;

	/** @inheritDoc */
	public function getAudience(): NoticeAudience {
		return NoticeAudience::Parties;
	}

	/** @inheritDoc */
	public function isAlwaysAtTop(): true {
		return true;
	}

	/** @inheritDoc */
	public function requiresTargetAccess(): false {
		return false;
	}

	/**
	 * @inheritDoc
	 * @param RequestStatus $status @phan-unused-param
	 */
	public function getNotices(
		RequestManager $requestManager,
		IContextSource $context,
		RequestStatus $status
	): array {
		$skipped = $requestManager->getSkippedImages();
		if ( $skipped['total'] === 0 ) {
			return [];
		}

		return [ new RequestNotice(
			severity: NoticeSeverity::Warning,
			html: $this->getSummary( $skipped['total'], $skipped['files'], $context ) .
				$this->getList( $skipped['total'], $skipped['files'], $context ),
			invalidates: false,
			declines: false,
			pinned: false,
		) ];
	}

	/**
	 * @param int $total
	 * @param list<SkippedFile> $files
	 * @param IContextSource $context
	 */
	private function getSummary( int $total, array $files, IContextSource $context ): string {
		$names = array_map(
			static fn ( SkippedFile $file ): string => $file->name,
			array_slice( $files, 0, self::NAMED )
		);

		$more = $total - count( $names );
		if ( $more > 0 ) {
			$names[] = $context->msg( 'requestimport-info-skipped-more' )->numParams( $more )->text();
		}

		return $context->msg( 'requestimport-info-skipped-files' )
			->numParams( $total )
			->params( $context->getLanguage()->listToText( $names ) )
			->escaped();
	}

	/**
	 * @param int $total
	 * @param list<SkippedFile> $files
	 * @param IContextSource $context
	 */
	private function getList( int $total, array $files, IContextSource $context ): string {
		$items = '';
		foreach ( $files as $file ) {
			$items .= Html::element(
				'li',
				[],
				$file->name . ': ' . $context->msg( $file->reason, ...$file->params )->text()
			);
		}

		$html = Html::element( 'summary', [], $context->msg( 'requestimport-info-skipped-show' )->text() ) .
			Html::rawElement( 'ul', [], $items );

		$hidden = $total - count( $files );
		if ( $hidden > 0 ) {
			$html .= Html::element(
				'p',
				[],
				$context->msg( 'requestimport-info-skipped-truncated' )->numParams( $hidden )->text()
			);
		}

		return Html::rawElement( 'details', [ 'class' => 'ext-importdump-skipped' ], $html );
	}
}
