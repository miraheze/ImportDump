<?php

declare( strict_types = 1 );

namespace Miraheze\ImportDump\Notices;

use MediaWiki\Config\Config;
use MediaWiki\Context\IContextSource;
use MediaWiki\Html\Html;
use Miraheze\ImportDump\ConfigNames;
use Miraheze\ImportDump\Request\RequestManager;
use Miraheze\ImportDump\Request\RequestStage;
use Miraheze\ImportDump\Request\RequestStatus;
use function implode;
use function mb_strtolower;

final class FileNotices implements NoticeProvider {

	public function __construct(
		private readonly Config $config,
	) {
	}

	public static function getExpiredInfo( RequestManager $requestManager, IContextSource $context ): string {
		$status = mb_strtolower(
			$context->msg( $requestManager->getStatus()->getLabelKey() )->text()
		);

		return $context->msg( 'requestimport-info-file-expired' )
			->numParams( $requestManager->getFileExpiryDays() )
			->params( $status )
			->escaped();
	}

	/** @inheritDoc */
	public function getAudience(): NoticeAudience {
		return NoticeAudience::Handlers;
	}

	/** @inheritDoc */
	public function isAlwaysAtTop(): false {
		return false;
	}

	/** @inheritDoc */
	public function requiresTargetAccess(): false {
		return false;
	}

	/** @inheritDoc */
	public function getNotices(
		RequestManager $requestManager,
		IContextSource $context,
		RequestStatus $status
	): array {
		if ( $status === RequestStatus::Verifying ) {
			return [ new RequestNotice(
				severity: NoticeSeverity::Notice,
				html: $context->msg( 'requestimport-info-verifying' )->escaped(),
				invalidates: true,
				declines: false,
				pinned: true,
			) ];
		}

		$stage = $requestManager->getStage();
		if ( $stage !== null && $requestManager->hasImported() ) {
			return [ new RequestNotice(
				severity: NoticeSeverity::Warning,
				html: $this->getImportedInfo( $requestManager, $context, $stage ),
				invalidates: false,
				declines: false,
				pinned: true,
			) ];
		}

		if ( $requestManager->fileExists() ) {
			return [ new RequestNotice(
				severity: NoticeSeverity::Notice,
				html: $this->getFileInfo( $requestManager, $context, $status ),
				invalidates: false,
				declines: false,
				pinned: true,
			) ];
		}

		if ( $requestManager->isPastFileExpiry() ) {
			return [ new RequestNotice(
				severity: NoticeSeverity::Notice,
				html: self::getExpiredInfo( $requestManager, $context ),
				invalidates: true,
				declines: true,
				pinned: true,
			) ];
		}

		return [ new RequestNotice(
			severity: NoticeSeverity::Error,
			html: $context->msg( 'requestimport-info-no-file-found', $requestManager->getFilePath() )->escaped(),
			invalidates: true,
			declines: true,
			pinned: true,
		) ];
	}

	private function getFileInfo(
		RequestManager $requestManager,
		IContextSource $context,
		RequestStatus $status
	): string {
		$fileInfo = $context->msg( 'requestimport-info-command' )->plaintextParams(
			$requestManager->getCommand()
		)->parse();

		$fileInfo .= Html::element( 'button', [
				'type' => 'button',
				'onclick' => 'navigator.clipboard.writeText(
      								$( \'.oo-ui-flaggedElement-notice\' ).text() );',
			],
			$context->msg( 'requestimport-button-copy' )->text()
		);

		if ( $this->config->get( ConfigNames::EnableAutomatedJob ) && $status !== RequestStatus::Failed ) {
			$fileInfo = '';
		}

		if ( $requestManager->getFileSize() > 0 ) {
			if ( $fileInfo ) {
				$fileInfo .= Html::element( 'br' );
			}

			$fileInfo .= $context->msg( 'requestimport-info-filesize' )->sizeParams(
				$requestManager->getFileSize()
			)->parse();
		}

		return $fileInfo;
	}

	private function getImportedInfo(
		RequestManager $requestManager,
		IContextSource $context,
		RequestStage $stage
	): string {
		$info = $context->msg( 'requestimport-info-already-imported', $context->msg( $stage->getLabelKey() )->text() )
			->parse();

		$commands = $requestManager->getRemainingCommands();
		if ( $commands ) {
			$info .= Html::element( 'br' ) . $context->msg( 'requestimport-info-remaining-commands' )
				->rawParams( Html::element( 'pre', [], implode( "\n", $commands ) ) )
				->parse();
		}

		return $info;
	}
}
