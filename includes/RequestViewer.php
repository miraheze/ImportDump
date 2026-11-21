<?php

namespace Miraheze\ImportDump;

use MediaWiki\Config\Config;
use MediaWiki\Context\IContextSource;
use MediaWiki\Exception\UserNotLoggedIn;
use MediaWiki\Html\Html;
use MediaWiki\HTMLForm\HTMLForm;
use MediaWiki\Linker\Linker;
use MediaWiki\MainConfigNames;
use MediaWiki\Message\Message;
use MediaWiki\User\User;
use Miraheze\ImportDump\Notices\FileNotices;
use Miraheze\ImportDump\Notices\HistoryNotices;
use Miraheze\ImportDump\Notices\NoticeCollector;
use Miraheze\ImportDump\Notices\RequesterNotices;
use Miraheze\ImportDump\Notices\RequestNotice;
use Miraheze\ImportDump\Notices\RequirementNotices;
use Miraheze\ImportDump\Notices\TargetNotices;
use OOUI\HtmlSnippet;
use OOUI\MessageWidget;
use OOUI\ProgressBarWidget;

class RequestViewer implements RequestStatus {

	public function __construct(
		private readonly Config $config,
		private readonly IContextSource $context,
		private readonly RequestManager $requestManager,
	) {
	}

	public function getFormDescriptor(): array {
		$user = $this->context->getUser();
		$authority = $this->context->getAuthority();

		if ( !$this->requestManager->isViewableBy( $authority ) ) {
			$this->context->getOutput()->addHTML(
				Html::errorBox( $this->context->msg( 'importdump-private' )->escaped() )
			);

			return [];
		}

		if ( $this->requestManager->isLocked() ) {
			$this->context->getOutput()->addHTML(
				Html::errorBox( $this->context->msg( 'importdump-request-locked' )->escaped() )
			);
		}

		if ( !$authority->isAllowed( 'handle-import-requests' ) && $this->requestManager->hasFileExpired() ) {
			$this->context->getOutput()->addHTML( Html::noticeBox( FileNotices::getExpiredInfo( $this->requestManager, $this->context ), '' ) );
		}

		$this->context->getOutput()->enableOOUI();

		$requestStatus = $this->requestManager->getStatus();
		if ( $requestStatus === self::STATUS_VERIFYING ) {
			$this->addVerificationProgress();
		} elseif ( $requestStatus === self::STATUS_STARTING || $requestStatus === self::STATUS_INPROGRESS ) {
			$this->addImportProgress();
		}

		$formDescriptor = [
			'source' => [
				'label-message' => 'importdump-label-source',
				'type' => 'url',
				'readonly' => true,
				'section' => 'details',
				'default' => $this->requestManager->getSource(),
			],
			'target' => [
				'label-message' => 'importdump-label-target',
				'type' => 'text',
				'readonly' => true,
				'section' => 'details',
				'default' => $this->requestManager->getTarget(),
			],
			'requester' => [
				'label-message' => 'importdump-label-requester',
				'type' => 'info',
				'section' => 'details',
				'default' => htmlspecialchars( $this->requestManager->getRequester()->getName() ) .
					Linker::userToolLinks(
						$this->requestManager->getRequester()->getId(),
						$this->requestManager->getRequester()->getName()
					),
				'raw' => true,
			],
			'requestedDate' => [
				'label-message' => 'importdump-label-requested-date',
				'type' => 'info',
				'section' => 'details',
				'default' => $this->context->getLanguage()->timeanddate(
					$this->requestManager->getTimestamp(), true
				),
			],
			'status' => [
				'label-message' => 'importdump-label-status',
				'type' => 'text',
				'readonly' => true,
				'section' => 'details',
				'default' => $this->context->msg(
					'importdump-label-' . $this->requestManager->getStatus()
				)->text(),
			],
		];

		$formDescriptor += $this->getStageDescriptor();

		if ( !$authority->isAllowed( 'handle-import-requests' ) ) {
			$result = $this->getNoticeCollector()->collect(
				$this->requestManager,
				$this->context,
				$requestStatus,
				forHandler: false
			);

			if ( $result->notices ) {
				$formDescriptor['request-notices'] = [
					'type' => 'info',
					'default' => $this->renderNotices( $result->notices ),
					'raw' => true,
					'section' => 'details',
				];
			}
		}

		$formDescriptor += [
			'reason' => [
				'type' => 'textarea',
				'rows' => 6,
				'readonly' => true,
				'label-message' => 'importdump-label-reason',
				'default' => $this->requestManager->getReason(),
				'raw' => true,
				'cssclass' => 'ext-importdump-infuse',
				'section' => 'details',
			],
		];

		foreach ( $this->requestManager->getComments() as $comment ) {
			$formDescriptor['comment' . $comment['timestamp'] ] = [
				'type' => 'textarea',
				'readonly' => true,
				'section' => 'comments',
				'rows' => 6,
				'label-message' => [
					'importdump-header-comment-withtimestamp',
					$comment['user']->getName(),
					$this->context->getLanguage()->timeanddate( $comment['timestamp'], true ),
				],
				'default' => $comment['comment'],
			];
		}

		if (
			$authority->isAllowed( 'handle-import-requests' ) ||
			$user->getActorId() === $this->requestManager->getRequester()->getActorId()
		) {
			$formDescriptor += [
				'comment' => [
					'type' => 'textarea',
					'rows' => 6,
					'label-message' => 'importdump-label-comment',
					'section' => 'comments',
					'validation-callback' => [ $this, 'isValidComment' ],
					'disabled' => $this->requestManager->isLocked(),
				],
				'submit-comment' => [
					'type' => 'submit',
					'buttonlabel-message' => 'importdump-label-add-comment',
					'disabled' => $this->requestManager->isLocked(),
					'section' => 'comments',
				],
				'edit-source' => [
					'label-message' => 'importdump-label-source',
					'type' => 'url',
					'section' => 'editing',
					'required' => true,
					'default' => $this->requestManager->getSource(),
					'disabled' => $this->requestManager->isLocked(),
				],
				'edit-target' => [
					'label-message' => 'importdump-label-target',
					'type' => 'text',
					'section' => 'editing',
					'required' => true,
					'default' => $this->requestManager->getTarget(),
					'validation-callback' => [ $this, 'isValidDatabase' ],
					'disabled' => $this->requestManager->isLocked(),
				],
				'edit-reason' => [
					'type' => 'textarea',
					'rows' => 6,
					'label-message' => 'importdump-label-reason',
					'section' => 'editing',
					'required' => true,
					'default' => $this->requestManager->getReason(),
					'validation-callback' => [ $this, 'isValidReason' ],
					'disabled' => $this->requestManager->isLocked(),
					'raw' => true,
				],
				'submit-edit' => [
					'type' => 'submit',
					'buttonlabel-message' => 'importdump-label-edit-request',
					'disabled' => $this->requestManager->isLocked(),
					'section' => 'editing',
				],
			];
		}

		if ( $authority->isAllowed( 'handle-import-requests' ) ) {
			$result = $this->getNoticeCollector()->collect(
				$this->requestManager,
				$this->context,
				$this->requestManager->getStatus(),
				forHandler: true
			);

			$validRequest = $result->valid;
			$status = $result->status;
			$targetExists = $this->requestManager->targetExists();
			$info = $this->renderNotices( $result->notices );

			$formDescriptor += [
				'handle-info' => [
					'type' => 'info',
					'default' => $info,
					'raw' => true,
					'section' => 'handling',
				],
				'handle-lock' => [
					'type' => 'check',
					'label-message' => 'importdump-label-lock',
					'default' => $this->requestManager->isLocked(),
					'section' => 'handling',
				],
			];

			if ( $authority->isAllowed( 'view-private-import-requests' ) ) {
				$formDescriptor += [
					'handle-private' => [
						'type' => 'check',
						'label-message' => 'importdump-label-private',
						'default' => $this->requestManager->isPrivate( forced: false ),
						'disabled' => $this->requestManager->isPrivate( forced: true ),
						'section' => 'handling',
					],
				];
			}

			if ( $this->config->get( ConfigNames::EnableAutomatedJob ) ) {
				$formDescriptor += [
					'handle-status' => [
						'type' => 'select',
						'label-message' => 'importdump-label-update-status',
						'options-messages' => array_unique( [
							'importdump-label-' . $status => $status,
							'importdump-label-pending' => self::STATUS_PENDING,
							'importdump-label-inprogress' => self::STATUS_INPROGRESS,
							'importdump-label-complete' => self::STATUS_COMPLETE,
						] ),
						'default' => $status,
						'disabled' => !$validRequest,
						'cssclass' => 'ext-importdump-infuse',
						'section' => 'handling',
					],
					'submit-handle' => [
						'type' => 'submit',
						'buttonlabel-message' => 'htmlform-submit',
						'section' => 'handling',
					],
				];
			}

			if (
				$targetExists &&
				!$this->requestManager->getInterwikiPrefix() &&
				$authority->isAllowed( 'handle-import-request-interwiki' )
			) {
				$source = $this->requestManager->getSource();
				$target = $this->requestManager->getTarget();

				$formDescriptor += [
					'handle-interwiki-info' => [
						'type' => 'info',
						'default' => $this->context->msg( 'importdump-info-interwiki', $target )->text(),
						'section' => 'handling',
					],
					'handle-interwiki-prefix' => [
						'type' => 'text',
						'label-message' => 'importdump-label-interwiki-prefix',
						'default' => '',
						'validation-callback' => [ $this, 'isValidInterwikiPrefix' ],
						'section' => 'handling',
					],
					'handle-interwiki-url' => [
						'type' => 'url',
						'label-message' => [
							'importdump-label-interwiki-url',
							( parse_url( $source, PHP_URL_SCHEME ) ?: 'https' ) . '://' .
							( parse_url( $source, PHP_URL_HOST ) ?: 'www.example.com' ) .
							'/wiki/$1',
						],
						'default' => '',
						'validation-callback' => [ $this, 'isValidInterwikiUrl' ],
						'section' => 'handling',
					],
					'submit-interwiki' => [
						'type' => 'submit',
						'buttonlabel-message' => 'htmlform-submit',
						'section' => 'handling',
					],
				];
			}

			if ( $this->config->get( ConfigNames::EnableAutomatedJob ) ) {
				$validStatus = true;
				if (
					$status === self::STATUS_COMPLETE ||
					$status === self::STATUS_INPROGRESS ||
					$status === self::STATUS_STARTING
				) {
					$validStatus = false;
				}

				$formDescriptor += [
					'handle-comment' => [
						'type' => 'textarea',
						'rows' => 6,
						'label-message' => 'importdump-label-status-updated-comment',
						'section' => 'handling',
					],
					'submit-start' => [
						'type' => 'submit',
						'buttonlabel-message' => $this->requestManager->hasImported() ?
							'importdump-label-resume-import' :
							'importdump-label-start-import',
						'disabled' => !$validRequest || !$validStatus,
						'section' => 'handling',
					],
					'submit-decline' => [
						'type' => 'submit',
						'flags' => [ 'destructive', 'primary' ],
						'buttonlabel-message' => 'importdump-label-decline-import',
						'disabled' => !$validStatus || $status === self::STATUS_DECLINED,
						'section' => 'handling',
					],
				];
			} else {
				$formDescriptor += [
					'handle-status' => [
						'type' => 'select',
						'label-message' => 'importdump-label-update-status',
						'options-messages' => [
							'importdump-label-pending' => self::STATUS_PENDING,
							'importdump-label-inprogress' => self::STATUS_INPROGRESS,
							'importdump-label-complete' => self::STATUS_COMPLETE,
							'importdump-label-declined' => self::STATUS_DECLINED,
						],
						'default' => $status,
						'disabled' => !$validRequest,
						'cssclass' => 'ext-importdump-infuse',
						'section' => 'handling',
					],
					'handle-comment' => [
						'type' => 'textarea',
						'rows' => 6,
						'label-message' => 'importdump-label-status-updated-comment',
						'section' => 'handling',
					],
					'submit-handle' => [
						'type' => 'submit',
						'buttonlabel-message' => 'htmlform-submit',
						'section' => 'handling',
					],
				];
			}

			if ( $status === self::STATUS_VERIFYING ) {
				$formDescriptor += [
					'submit-verify' => [
						'type' => 'submit',
						'buttonlabel-message' => 'importdump-label-retry-verification',
						'section' => 'handling',
					],
				];
			}
		}

		return $formDescriptor;
	}

	/** Shows how far the check of the uploaded file has come. The script keeps the bar up to date. */
	private function addVerificationProgress(): void {
		$out = $this->context->getOutput();
		$out->enableOOUI();
		$out->addModules( [ 'ext.importdump.progress' ] );

		$progress = $this->requestManager->getVerificationProgress();
		$percent = $progress['percent'] ?? null;

		// Messages that can be used here:
		// * importdump-verification-checking
		// * importdump-verification-extracting
		// * importdump-verification-waiting
		$label = $this->context->msg(
			'importdump-verification-' . ( $progress['stage'] ?? 'waiting' )
		)->text();

		if ( $percent !== null ) {
			$label = $this->context->msg( 'importdump-verification-label' )
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
		$position = array_search( $stage, RequestStage::STAGES, true );

		if ( $position === false ) {
			$label = $this->context->msg( 'importdump-import-waiting' )->text();
		} else {
			// Messages that can be used here:
			// * importdump-import-step
			// * importdump-import-step-pages
			// * importdump-import-step-percent
			$message = $this->context->msg( match ( true ) {
				$percent === null => 'importdump-import-step',
				$pages !== null => 'importdump-import-step-pages',
				default => 'importdump-import-step-percent',
			} )->numParams( $position + 1, count( RequestStage::STAGES ) )
				->params( $this->context->msg( 'importdump-stage-' . $stage )->text() );

			if ( $percent !== null && $pages !== null ) {
				$message->numParams( $pages['done'], $pages['total'], $percent );
			} elseif ( $percent !== null ) {
				$message->numParams( $percent );
			}

			$label = $message->text();
		}

		$bar = new ProgressBarWidget( [
			'progress' => $position === false ? false : ( $percent ?? false ),
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

	private function getStageDescriptor(): array {
		$stage = $this->requestManager->getStage();
		$statuses = [ self::STATUS_FAILED, self::STATUS_INPROGRESS, self::STATUS_PARTIAL ];
		if ( !$stage || !in_array( $this->requestManager->getStatus(), $statuses, true ) ) {
			return [];
		}

		return [
			'stage' => [
				'label-message' => 'importdump-label-stage',
				'type' => 'text',
				'readonly' => true,
				'section' => 'details',
				'default' => $this->context->msg( "importdump-stage-$stage" )->text(),
			],
		];
	}

	private function getNoticeCollector(): NoticeCollector {
		return new NoticeCollector( [
			new FileNotices( $this->config ),
			new TargetNotices(),
			new HistoryNotices(),
			new RequirementNotices(),
			new RequesterNotices(),
		] );
	}

	/** @param RequestNotice[] $notices */
	private function renderNotices( array $notices ): string {
		$html = '';
		foreach ( $notices as $notice ) {
			$html .= new MessageWidget( [
				'label' => new HtmlSnippet( $notice->html ),
				'type' => $notice->severity,
				'classes' => [ 'ext-importdump-notice' ],
			] );
		}

		return $html;
	}

	public function isValidComment( ?string $comment, array $alldata ): Message|true {
		if ( isset( $alldata['submit-comment'] ) && ( !$comment || ctype_space( $comment ) ) ) {
			return $this->context->msg( 'htmlform-required' );
		}

		return true;
	}

	public function isValidDatabase( ?string $target ): Message|true {
		if ( !in_array( $target, $this->config->get( MainConfigNames::LocalDatabases ), true ) ) {
			return $this->context->msg( 'importdump-invalid-target' );
		}

		return true;
	}

	public function isValidReason( ?string $reason ): Message|true {
		if ( !$reason || ctype_space( $reason ) ) {
			return $this->context->msg( 'htmlform-required' );
		}

		return true;
	}

	public function isValidInterwikiPrefix( ?string $prefix, array $alldata ): Message|true {
		if ( isset( $alldata['submit-interwiki'] ) && ( !$prefix || ctype_space( $prefix ) ) ) {
			return $this->context->msg( 'htmlform-required' );
		}

		return true;
	}

	public function isValidInterwikiUrl( ?string $url, array $alldata ): Message|true {
		if ( !isset( $alldata['submit-interwiki'] ) ) {
			return true;
		}

		if ( !$url || ctype_space( $url ) ) {
			return $this->context->msg( 'htmlform-required' );
		}

		if (
			!parse_url( $url, PHP_URL_SCHEME ) ||
			!parse_url( $url, PHP_URL_HOST )
		) {
			return $this->context->msg( 'importdump-invalid-interwiki-url' );
		}

		return true;
	}

	public function getForm( int $requestID ): ?OOUIHTMLFormTabs {
		$this->requestManager->loadFromID( $requestID );
		$out = $this->context->getOutput();
		if ( $requestID === 0 || !$this->requestManager->exists() ) {
			$out->addHTML(
				Html::errorBox( $this->context->msg( 'importdump-unknown' )->escaped() )
			);

			return null;
		}

		$out->addModules( [ 'ext.importdump.oouiform' ] );
		$out->addModuleStyles( [ 'ext.importdump.oouiform.styles' ] );
		$out->addModuleStyles( [ 'oojs-ui-widgets.styles' ] );

		$formDescriptor = $this->getFormDescriptor();
		$htmlForm = new OOUIHTMLFormTabs( $formDescriptor, $this->context, 'importdump-section' );

		$htmlForm->setId( 'importdump-request-viewer' );
		$htmlForm->suppressDefaultSubmit();
		$htmlForm->setSubmitCallback(
			function ( array $formData, HTMLForm $form ) {
				return $this->submitForm( $formData, $form );
			}
		);

		return $htmlForm;
	}

	/** @throws UserNotLoggedIn */
	protected function submitForm(
		array $formData,
		HTMLForm $form
	): void {
		$user = $form->getUser();
		if ( !$user->isRegistered() ) {
			throw new UserNotLoggedIn( 'exception-nologin-text', 'exception-nologin' );
		}

		$out = $form->getContext()->getOutput();
		$session = $form->getRequest()->getSession();

		if ( isset( $formData['submit-comment'] ) ) {
			if ( $session->get( 'previous_posted_comment' ) !== $formData['comment'] ) {
				$session->set( 'previous_posted_comment', $formData['comment'] );
				$this->requestManager->addComment( $formData['comment'], $user );
				$out->addHTML( Html::successBox( $this->context->msg( 'importdump-comment-success' )->escaped() ) );
				return;
			}

			$out->addHTML( Html::errorBox( $this->context->msg( 'importdump-duplicate-comment' )->escaped() ) );
			return;
		}

		$session->remove( 'previous_posted_comment' );

		if ( isset( $formData['submit-edit'] ) ) {
			$this->requestManager->startAtomic( __METHOD__ );

			$changes = [];
			if ( $this->requestManager->getReason() !== $formData['edit-reason'] ) {
				$changes[] = $this->context->msg( 'importdump-request-edited-reason' )->plaintextParams(
					$this->requestManager->getReason(),
					$formData['edit-reason']
				)->escaped();

				$this->requestManager->setReason( $formData['edit-reason'] );
			}

			if ( $this->requestManager->getSource() !== $formData['edit-source'] ) {
				$changes[] = $this->context->msg( 'importdump-request-edited-source' )->plaintextParams(
					$this->requestManager->getSource(),
					$formData['edit-source']
				)->escaped();

				$this->requestManager->setSource( $formData['edit-source'] );
			}

			if ( $this->requestManager->getTarget() !== $formData['edit-target'] ) {
				$changes[] = $this->context->msg(
					'importdump-request-edited-target',
					$this->requestManager->getTarget(),
					$formData['edit-target']
				)->escaped();

				$this->requestManager->setTarget( $formData['edit-target'] );
			}

			if ( !$changes ) {
				$this->requestManager->endAtomic( __METHOD__ );

				$out->addHTML( Html::errorBox( $this->context->msg( 'importdump-no-changes' )->escaped() ) );

				return;
			}

			if ( $this->requestManager->getStatus() === self::STATUS_DECLINED ) {
				$this->requestManager->setStatus( self::STATUS_PENDING );

				$comment = $this->context->msg( 'importdump-request-reopened', $user->getName() )->rawParams(
					implode( "\n\n", $changes )
				)->inContentLanguage()->escaped();

				$this->requestManager->logStatusUpdate( $comment, self::STATUS_PENDING, $user );

				$this->requestManager->addComment( $comment, User::newSystemUser( 'ImportDump Extension' ) );

				$this->requestManager->sendNotification(
					$comment, 'requestimport-request-status-update', $user
				);
			} else {
				$comment = $this->context->msg( 'importdump-request-edited', $user->getName() )->rawParams(
					implode( "\n\n", $changes )
				)->inContentLanguage()->escaped();

				$this->requestManager->addComment( $comment, User::newSystemUser( 'ImportDump Extension' ) );
			}

			$this->requestManager->endAtomic( __METHOD__ );

			$out->addHTML( Html::successBox( $this->context->msg( 'importdump-edit-success' )->escaped() ) );

			return;
		}

		if ( isset( $formData['submit-interwiki'] ) ) {
			if ( $this->requestManager->insertInterwikiPrefix(
				$formData['handle-interwiki-prefix'],
				$formData['handle-interwiki-url'],
				$user
			) ) {
				$out->addHTML( Html::successBox(
					$this->context->msg( 'importdump-interwiki-success',
						$this->requestManager->getTarget()
					)->escaped() )
				);

				return;
			}

			$out->addHTML( Html::errorBox(
				$this->context->msg( 'importdump-interwiki-failed',
					$this->requestManager->getTarget()
				)->escaped() )
			);

			return;
		}

		if ( isset( $formData['submit-verify'] ) ) {
			if ( $this->requestManager->getStatus() !== self::STATUS_VERIFYING ) {
				$out->addHTML(
					Html::errorBox( $this->context->msg( 'importdump-verification-not-needed' )->escaped() )
				);

				return;
			}

			$this->requestManager->retryVerification();
			$out->addHTML( Html::successBox( $this->context->msg( 'importdump-verification-retried' )->escaped() ) );
			return;
		}

		if ( isset( $formData['submit-handle'] ) ) {
			$this->requestManager->startAtomic( __METHOD__ );
			if (
				$this->requestManager->getStatus() === self::STATUS_VERIFYING &&
				isset( $formData['handle-status'] ) &&
				!in_array( $formData['handle-status'], [ self::STATUS_DECLINED, self::STATUS_VERIFYING ], true )
			) {
				$this->requestManager->endAtomic( __METHOD__ );
				$out->addHTML( Html::errorBox( $this->context->msg( 'importdump-verifying-conflict' )->escaped() ) );
				return;
			}

			$changes = [];

			if ( $this->requestManager->isLocked() !== (bool)$formData['handle-lock'] ) {
				$changes[] = $this->requestManager->isLocked() ?
					'unlocked' : 'locked';

				$this->requestManager->setLocked( (int)$formData['handle-lock'] );
			}

			if (
				isset( $formData['handle-private'] ) &&
				$this->requestManager->isPrivate( forced: false ) !== (bool)$formData['handle-private']
			) {
				$changes[] = $this->requestManager->isPrivate( forced: false ) ?
					'public' : 'private';

				$this->requestManager->setPrivate( (int)$formData['handle-private'] );
			}

			if (
				!isset( $formData['handle-status'] ) ||
				$this->requestManager->getStatus() === $formData['handle-status']
			) {
				$this->requestManager->endAtomic( __METHOD__ );

				if ( !$changes ) {
					$out->addHTML( Html::errorBox( $this->context->msg( 'importdump-no-changes' )->escaped() ) );
					return;
				}

				if ( in_array( 'private', $changes ) ) {
					$out->addHTML(
						Html::successBox( $this->context->msg( 'importdump-success-private' )->escaped() )
					);
				}

				if ( in_array( 'public', $changes ) ) {
					$out->addHTML(
						Html::successBox( $this->context->msg( 'importdump-success-public' )->escaped() )
					);
				}

				if ( in_array( 'locked', $changes ) ) {
					$out->addHTML(
						Html::successBox( $this->context->msg( 'importdump-success-locked' )->escaped() )
					);
				}

				if ( in_array( 'unlocked', $changes ) ) {
					$out->addHTML(
						Html::successBox( $this->context->msg( 'importdump-success-unlocked' )->escaped() )
					);
				}

				return;
			}

			if ( isset( $formData['handle-status'] ) ) {
				if ( $this->config->get( ConfigNames::EnableAutomatedJob ) ) {
					$formData['handle-comment'] = '';
				}

				$this->handleStatusUpdate( $formData, $user );
				$this->requestManager->endAtomic( __METHOD__ );
				return;
			}
		}

		if (
			$this->requestManager->getStatus() === self::STATUS_COMPLETE ||
			$this->requestManager->getStatus() === self::STATUS_INPROGRESS ||
			$this->requestManager->getStatus() === self::STATUS_STARTING
		) {
			$out->addHTML( Html::errorBox(
				$this->context->msg( 'importdump-status-conflict' )->escaped()
			) );

			return;
		}

		if ( isset( $formData['submit-decline'] ) ) {
			$formData['handle-status'] = self::STATUS_DECLINED;
			$this->requestManager->startAtomic( __METHOD__ );
			$this->handleStatusUpdate( $formData, $user );
			$this->requestManager->endAtomic( __METHOD__ );
			return;
		}

		if ( isset( $formData['submit-start'] ) ) {
			if ( $this->requestManager->getStatus() === self::STATUS_VERIFYING ) {
				$out->addHTML( Html::errorBox( $this->context->msg( 'importdump-verifying-conflict' )->escaped() ) );
				return;
			}

			if ( $this->requestManager->getStatus() === self::STATUS_COMPLETE ) {
				// Don't rerun a job that is already completed.
				return;
			}

			$blocking = $this->requestManager->getBlockingRequirementProblems();
			if ( $blocking ) {
				$out->addHTML( Html::errorBox(
					$this->context->msg( 'importdump-requirement-blocked' )
						->params( $this->context->getLanguage()->listToText( array_unique(
							array_column( $blocking, 'name' )
						) ) )
						->escaped()
				) );

				return;
			}

			$this->requestManager->addHandler( $user );
			$this->requestManager->setStatus( self::STATUS_STARTING );
			$this->requestManager->executeJob( $user->getName() );
			$out->addHTML( Html::successBox(
				$this->context->msg( 'importdump-import-started' )->escaped()
			) );
		}
	}

	private function handleStatusUpdate( array $formData, User $user ): void {
		$this->requestManager->addHandler( $user );
		$this->requestManager->setStatus( $formData['handle-status'] );
		$statusMessage = $this->context->msg( 'importdump-label-' . $formData['handle-status'] )
			->inContentLanguage()
			->text();

		$comment = $this->context->msg(
			'importdump-status-updated-by',
			mb_strtolower( $statusMessage ),
			$user->getName()
		)->inContentLanguage()->escaped();

		if ( $formData['handle-comment'] ) {
			$comment .= "\n" . $this->context->msg( 'importdump-status-comment-given' )
				->inContentLanguage()
				->escaped();

			$comment .= ' ' . $formData['handle-comment'];
		}

		$this->requestManager->addComment( $comment, User::newSystemUser( 'ImportDump Status Update' ) );
		$this->requestManager->logStatusUpdate(
			$formData['handle-comment'], $formData['handle-status'], $user
		);

		$this->requestManager->sendNotification(
			$comment, 'requestimport-request-status-update', $user
		);

		$this->context->getOutput()->addHTML( Html::successBox(
			$this->context->msg( 'importdump-status-updated-success' )->escaped()
		) );
	}
}
