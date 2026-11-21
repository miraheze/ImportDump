<?php

namespace Miraheze\ImportDump\Notices;

use MediaWiki\Config\Config;
use MediaWiki\Context\IContextSource;
use MediaWiki\Html\Html;
use Miraheze\ImportDump\ConfigNames;
use Miraheze\ImportDump\RequestManager;
use Miraheze\ImportDump\RequestStatus;

final class FileNotices implements NoticeProvider {

	public function __construct(
		private readonly Config $config,
	) {
	}

	public static function getExpiredInfo( RequestManager $requestManager, IContextSource $context ): string {
		$status = mb_strtolower(
			$context->msg( 'importdump-label-' . $requestManager->getStatus() )->text()
		);

		return $context->msg( 'importdump-info-file-expired' )
			->numParams( $requestManager->getFileExpiryDays() )
			->params( $status )
			->escaped();
	}

	/** @inheritDoc */
	public function getAudience(): string {
		return self::AUDIENCE_HANDLERS;
	}

	/** @inheritDoc */
	public function requiresTargetAccess(): bool {
		return false;
	}

	/** @inheritDoc */
	public function getNotices(
		RequestManager $requestManager,
		IContextSource $context,
		string $status
	): array {
		if ( $status === RequestStatus::STATUS_VERIFYING ) {
			return [ new RequestNotice(
				severity: RequestNotice::SEVERITY_NOTICE,
				html: $context->msg( 'importdump-info-verifying' )->escaped(),
				invalidates: true,
				declines: false,
			) ];
		}

		if ( $requestManager->hasImported() ) {
			return [ new RequestNotice(
				severity: RequestNotice::SEVERITY_WARNING,
				html: $this->getImportedInfo( $requestManager, $context ),
				invalidates: false,
				declines: false,
			) ];
		}

		if ( $requestManager->fileExists() ) {
			return [ new RequestNotice(
				severity: RequestNotice::SEVERITY_NOTICE,
				html: $this->getFileInfo( $requestManager, $context, $status ),
				invalidates: false,
				declines: false,
			) ];
		}

		if ( $requestManager->isPastFileExpiry() ) {
			return [ new RequestNotice(
				severity: RequestNotice::SEVERITY_NOTICE,
				html: self::getExpiredInfo( $requestManager, $context ),
				invalidates: true,
				declines: true,
			) ];
		}

		return [ new RequestNotice(
			severity: RequestNotice::SEVERITY_ERROR,
			html: $context->msg( 'importdump-info-no-file-found', $requestManager->getFilePath() )->escaped(),
			invalidates: true,
			declines: true,
		) ];
	}

	private function getFileInfo(
		RequestManager $requestManager,
		IContextSource $context,
		string $status
	): string {
		$fileInfo = $context->msg( 'importdump-info-command' )->plaintextParams(
			$requestManager->getCommand()
		)->parse();

		$fileInfo .= Html::element( 'button', [
				'type' => 'button',
				'onclick' => 'navigator.clipboard.writeText(
      								$( \'.oo-ui-flaggedElement-notice\' ).text() );',
			],
			$context->msg( 'importdump-button-copy' )->text()
		);

		if ( $this->config->get( ConfigNames::EnableAutomatedJob ) && $status !== RequestStatus::STATUS_FAILED ) {
			$fileInfo = '';
		}

		if ( $requestManager->getFileSize() > 0 ) {
			if ( $fileInfo ) {
				$fileInfo .= Html::element( 'br' );
			}

			$fileInfo .= $context->msg( 'importdump-info-filesize' )->sizeParams(
				$requestManager->getFileSize()
			)->parse();
		}

		return $fileInfo;
	}

	private function getImportedInfo( RequestManager $requestManager, IContextSource $context ): string {
		$stage = $context->msg( 'importdump-stage-' . $requestManager->getStage() )->text();
		$info = $context->msg( 'importdump-info-already-imported', $stage )->parse();

		$commands = $requestManager->getRemainingCommands();
		if ( $commands ) {
			$info .= Html::element( 'br' ) . $context->msg( 'importdump-info-remaining-commands' )
				->rawParams( Html::element( 'pre', [], implode( "\n", $commands ) ) )
				->parse();
		}

		return $info;
	}
}
