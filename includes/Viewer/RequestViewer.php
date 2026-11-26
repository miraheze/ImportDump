<?php

declare( strict_types = 1 );

namespace Miraheze\ImportDump\Viewer;

use MediaWiki\Config\Config;
use MediaWiki\Context\IContextSource;
use MediaWiki\Html\Html;
use MediaWiki\HTMLForm\HTMLForm;
use MediaWiki\Permissions\Authority;
use MediaWiki\User\User;
use Miraheze\ImportDump\Notices\ActionOutcomeNotices;
use Miraheze\ImportDump\Notices\FileNotices;
use Miraheze\ImportDump\Notices\HistoryNotices;
use Miraheze\ImportDump\Notices\LinkedNotices;
use Miraheze\ImportDump\Notices\LockedNotices;
use Miraheze\ImportDump\Notices\NoticeCollector;
use Miraheze\ImportDump\Notices\NoticeResult;
use Miraheze\ImportDump\Notices\NoticeRole;
use Miraheze\ImportDump\Notices\PartialHistoryNotices;
use Miraheze\ImportDump\Notices\RequesterNotices;
use Miraheze\ImportDump\Notices\RequestNotice;
use Miraheze\ImportDump\Notices\RequiredRightsNotices;
use Miraheze\ImportDump\Notices\RequirementNotices;
use Miraheze\ImportDump\Notices\SkippedImageNotices;
use Miraheze\ImportDump\Notices\TargetNotices;
use Miraheze\ImportDump\Request\RequestManager;
use Miraheze\ImportDump\Request\RequestStatus;
use Miraheze\ImportDump\Requirements\ExtensionNames;
use OOUI\HtmlSnippet;
use OOUI\MessageWidget;
use OOUI\ProgressBarWidget;
use function array_search;
use function count;

class RequestViewer {

	private readonly ActionOutcomeNotices $outcomes;
	private readonly RequestFormBuilder $formBuilder;
	private readonly RequestSubmitHandler $submitHandler;

	public function __construct(
		private readonly Config $config,
		private readonly IContextSource $context,
		private readonly ExtensionNames $extensionNames,
		private readonly RequestManager $requestManager,
	) {
		$this->outcomes = new ActionOutcomeNotices();
		$this->formBuilder = new RequestFormBuilder( $config, $context, $requestManager );
		$this->submitHandler = new RequestSubmitHandler( $config, $context, $requestManager, $this->outcomes );
	}

	public function getFormDescriptor(): array {
		$authority = $this->context->getAuthority();

		if ( !$this->requestManager->isViewableBy( $authority ) ) {
			$this->context->getOutput()->addHTML(
				Html::errorBox( $this->context->msg( 'requestimport-private' )->escaped() )
			);

			return [];
		}

		if ( !$authority->isAllowed( 'handle-import-requests' ) && $this->requestManager->hasFileExpired() ) {
			$this->context->getOutput()->addHTML( Html::noticeBox(
				FileNotices::getExpiredInfo( $this->requestManager, $this->context ),
				''
			) );
		}

		$this->context->getOutput()->enableOOUI();

		$noticeResult = $this->collectNotices();

		$requestStatus = $this->requestManager->getStatus();
		if ( $requestStatus === RequestStatus::Verifying ) {
			$this->addVerificationProgress();
		} elseif ( $requestStatus->isRunning() ) {
			$this->addImportProgress();
		}

		return $this->formBuilder->build( $noticeResult, $this->renderNotices( $noticeResult->notices ) );
	}

	/** Shows how far the check of the uploaded file has come. The script keeps the bar up to date. */
	private function addVerificationProgress(): void {
		$out = $this->context->getOutput();
		$out->enableOOUI();
		$out->addModules( [ 'ext.importdump.progress' ] );

		$progress = $this->requestManager->getVerificationProgress();
		$percent = $progress['percent'] ?? null;

		// Messages that can be used here:
		// * requestimport-verification-checking
		// * requestimport-verification-extracting
		// * requestimport-verification-joining
		// * requestimport-verification-storing
		// * requestimport-verification-waiting
		$label = $this->context->msg(
			'requestimport-verification-' . ( $progress['stage'] ?? 'waiting' )
		)->text();

		if ( $percent !== null ) {
			$label = $this->context->msg( 'requestimport-verification-label' )
				->plaintextParams( $label )
				->numParams( $percent )
				->text();
		}

		$bar = new ProgressBarWidget( [
			'progress' => $percent ?? false,
			'id' => 'ext-importdump-verification-bar',
		] );
		$bar->setInfusable( true );

		$out->addHTML( Html::rawElement(
			'div',
			[
				'class' => 'ext-importdump-verification',
				'data-request-id' => $this->requestManager->getID(),
			],
			Html::element( 'p', [ 'class' => 'ext-importdump-verification-label' ], $label ) .
			$bar->toString()
		) );
	}

	/** Shows which stage the import is on and how far it has come. The script keeps the bar up to date. */
	private function addImportProgress(): void {
		$out = $this->context->getOutput();
		$out->enableOOUI();
		$out->addModules( [ 'ext.importdump.importprogress' ] );

		$stage = $this->requestManager->getStage();
		$percent = $this->requestManager->getImportPercent();
		$pages = $this->requestManager->getImportPages();
		$stages = $this->requestManager->getStages();

		if ( $stage === null ) {
			$label = $this->context->msg( 'requestimport-import-waiting' )->text();
		} else {
			// Messages that can be used here:
			// * requestimport-import-step
			// * requestimport-import-step-pages
			// * requestimport-import-step-percent
			$message = $this->context->msg( match ( true ) {
				$percent === null => 'requestimport-import-step',
				$pages !== null => 'requestimport-import-step-pages',
				default => 'requestimport-import-step-percent',
			} )->numParams( (int)array_search( $stage, $stages, true ) + 1, count( $stages ) )
				->params( $this->context->msg( $stage->getLabelKey() )->text() );

			if ( $percent !== null && $pages !== null ) {
				$message->numParams( $pages['done'], $pages['total'], $percent );
			} elseif ( $percent !== null ) {
				$message->numParams( $percent );
			}

			$label = $message->text();
		}

		$bar = new ProgressBarWidget( [
			'progress' => $stage === null ? false : ( $percent ?? false ),
			'id' => 'ext-importdump-import-bar',
		] );
		$bar->setInfusable( true );

		$out->addHTML( Html::rawElement(
			'div',
			[
				'class' => 'ext-importdump-import',
				'data-request-id' => $this->requestManager->getID(),
			],
			Html::element( 'p', [ 'class' => 'ext-importdump-import-label' ], $label ) .
			$bar->toString()
		) );
	}

	private function collectNotices(): NoticeResult {
		$role = $this->getNoticeRole( $this->context->getAuthority(), $this->context->getUser() );
		return $this->getNoticeCollector()->collect(
			$this->requestManager,
			$this->context,
			$this->requestManager->getStatus(),
			role: $role,
			hasTargetAccess: $role === NoticeRole::Requester &&
				$this->requestManager->requesterHasTargetAccess()
		);
	}

	/** Collected again every time, so that it shows the state after a submitted action. */
	private function renderTopNotices(): string {
		$notices = $this->collectNotices()->topNotices;
		if ( !$notices ) {
			return '';
		}

		return Html::rawElement(
			'div',
			[ 'class' => 'ext-importdump-notices' ],
			$this->renderNotices( $notices )
		);
	}

	private function getNoticeRole( Authority $authority, User $user ): NoticeRole {
		if ( $authority->isAllowed( 'handle-import-requests' ) ) {
			return NoticeRole::Handler;
		}

		return $user->getActorId() === $this->requestManager->getRequester()->getActorId() ?
			NoticeRole::Requester :
			NoticeRole::Viewer;
	}

	private function getNoticeCollector(): NoticeCollector {
		return new NoticeCollector( [
			new FileNotices( $this->config ),
			new TargetNotices(),
			new HistoryNotices(),
			new PartialHistoryNotices(),
			new SkippedImageNotices(),
			new RequiredRightsNotices(),
			new LockedNotices(),
			new LinkedNotices(),
			$this->outcomes,
			new RequirementNotices( $this->extensionNames ),
			new RequesterNotices(),
		] );
	}

	/** @param RequestNotice[] $notices */
	private function renderNotices( array $notices ): string {
		$html = '';
		foreach ( $notices as $notice ) {
			$html .= new MessageWidget( [
				'label' => new HtmlSnippet( $notice->html ),
				'type' => $notice->severity->value,
				'classes' => [ 'ext-importdump-notice' ],
			] );
		}

		return $html;
	}

	public function getForm( int $requestID ): ?OOUIHTMLFormTabs {
		$this->requestManager->loadFromID( $requestID );
		$out = $this->context->getOutput();
		if ( $requestID === 0 || !$this->requestManager->exists() ) {
			$out->addHTML(
				Html::errorBox( $this->context->msg( 'requestimport-unknown' )->escaped() )
			);

			return null;
		}

		$out->addModules( [ 'ext.importdump.oouiform' ] );
		$out->addModuleStyles( [ 'ext.importdump.oouiform.styles' ] );
		$out->addModuleStyles( [ 'oojs-ui-widgets.styles' ] );

		$formDescriptor = $this->getFormDescriptor();
		$htmlForm = new OOUIHTMLFormTabs( $formDescriptor, $this->context, 'importdump-section' );

		$htmlForm->setNoticesRenderer( fn (): string => $this->renderTopNotices() );
		$htmlForm->setId( 'importdump-request-viewer' );
		$htmlForm->suppressDefaultSubmit();
		$htmlForm->setSubmitCallback(
			function ( array $formData, HTMLForm $form ) {
				return $this->submitHandler->submit( $formData, $form );
			}
		);

		return $htmlForm;
	}
}
