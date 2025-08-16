<?php

namespace Miraheze\ImportDump;

use MediaWiki\Config\Config;
use MediaWiki\Context\IContextSource;
use MediaWiki\Html\Html;
use MediaWiki\HTMLForm\HTMLForm;
use MediaWiki\Linker\Linker;
use MediaWiki\MainConfigNames;
use MediaWiki\Message\Message;
use MediaWiki\User\User;
use MediaWiki\WikiMap\WikiMap;
use OOUI\HtmlSnippet;
use OOUI\MessageWidget;
use UserNotLoggedIn;

class RequestViewer implements ImportDumpStatus {

	public function __construct(
		private readonly Config $config,
		private readonly IContextSource $context,
		private readonly RequestManager $requestManager
	) {
	}

	public function getFormDescriptor(): array {
		$user = $this->context->getUser();
		$authority = $this->context->getAuthority();

		if (
			$this->requestManager->isPrivate( forced: false ) &&
			$user->getName() !== $this->requestManager->getRequester()->getName() &&
			!$authority->isAllowed( 'view-private-import-requests' )
		) {
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

		$this->context->getOutput()->enableOOUI();

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
			$validRequest = true;
			$status = $this->requestManager->getStatus();

			if ( $this->requestManager->fileExists() ) {
				$fileInfo = $this->context->msg( 'importdump-info-command' )->plaintextParams(
					$this->requestManager->getCommand()
				)->parse();

				$fileInfo .= Html::element( 'button', [
						'type' => 'button',
						'onclick' => 'navigator.clipboard.writeText(
      								$( \'.oo-ui-flaggedElement-notice\' ).text() );',
					],
					$this->context->msg( 'importdump-button-copy' )->text()
				);

				if ( $this->config->get( ConfigNames::EnableAutomatedJob ) && $status !== self::STATUS_FAILED ) {
					$fileInfo = '';
				}

				if ( $this->requestManager->getFileSize() > 0 ) {
					if ( $fileInfo ) {
						$fileInfo .= Html::element( 'br' );
					}

					$fileInfo .= $this->context->msg( 'importdump-info-filesize' )->sizeParams(
						$this->requestManager->getFileSize()
					)->parse();
				}

				$info = new MessageWidget( [
					'label' => new HtmlSnippet( $fileInfo ),
					'type' => 'notice',
				] );
			} else {
				$info = new MessageWidget( [
					'label' => new HtmlSnippet(
								$this->context->msg( 'importdump-info-no-file-found',
								$this->requestManager->getFilePath()
							)->escaped()
						),
					'type' => 'error',
				] );

				$validRequest = false;
				if ( $status === self::STATUS_PENDING || $status === self::STATUS_INPROGRESS ) {
					$status = self::STATUS_DECLINED;
				}
			}

			$info .= new MessageWidget( [
				'label' => new HtmlSnippet(
						$this->context->msg( 'importdump-info-groups',
							$this->requestManager->getRequester()->getName(),
							$this->requestManager->getTarget(),
							$this->context->getLanguage()->commaList(
								$this->requestManager->getUserGroupsFromTarget()
							)
						)->escaped(),
					),
				'type' => 'notice',
			] );

			if ( $this->requestManager->isPrivate( forced: false ) ) {
				$info .= new MessageWidget( [
					'label' => new HtmlSnippet( $this->context->msg( 'importdump-info-request-private' )->escaped() ),
					'type' => 'warning',
				] );
			}

			if ( $this->requestManager->getRequester()->getBlock() ) {
				$info .= new MessageWidget( [
					'label' => new HtmlSnippet(
							$this->context->msg( 'importdump-info-requester-blocked',
								$this->requestManager->getRequester()->getName(),
								WikiMap::getCurrentWikiId()
							)->escaped()
						),
					'type' => 'warning',
				] );
			}

			if ( $this->requestManager->getRequester()->isLocked() ) {
				$info .= new MessageWidget( [
					'label' => new HtmlSnippet(
							$this->context->msg( 'importdump-info-requester-locked',
								$this->requestManager->getRequester()->getName()
							)->escaped()
						),
					'type' => 'error',
				] );

				$validRequest = false;
				if ( $status === self::STATUS_PENDING || $status === self::STATUS_INPROGRESS ) {
					$status = self::STATUS_DECLINED;
				}
			}

			if ( !$this->requestManager->getInterwikiPrefix() ) {
				$info .= new MessageWidget( [
					'label' => new HtmlSnippet(
							$this->context->msg( 'importdump-info-no-interwiki-prefix',
								$this->requestManager->getTarget(),
								parse_url( $this->requestManager->getSource(), PHP_URL_HOST )
							)->escaped()
						),
					'type' => 'error',
				] );
			}

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
						'buttonlabel-message' => 'importdump-label-start-import',
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
		}

		return $formDescriptor;
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
					$comment, 'importdump-request-status-update', $user
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

		if ( isset( $formData['submit-handle'] ) ) {
			$this->requestManager->startAtomic( __METHOD__ );
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
			if ( $this->requestManager->getStatus() === self::STATUS_COMPLETE ) {
				// Don't rerun a job that is already completed.
				return;
			}

			$this->requestManager->setStatus( self::STATUS_STARTING );
			$this->requestManager->executeJob( $user->getName() );
			$out->addHTML( Html::successBox(
				$this->context->msg( 'importdump-import-started' )->escaped()
			) );
		}
	}

	private function handleStatusUpdate( array $formData, User $user ): void {
		$this->requestManager->setStatus( $formData['handle-status'] );
		$statusMessage = $this->context->msg( 'importdump-label-' . $formData['handle-status'] )
			->inContentLanguage()
			->text();

		$comment = $this->context->msg( 'importdump-status-updated', mb_strtolower( $statusMessage ) )
			->inContentLanguage()
			->escaped();

		if ( $formData['handle-comment'] ) {
			$commentUser = User::newSystemUser( 'ImportDump Status Update' );

			$comment .= "\n" . $this->context->msg( 'importdump-comment-given', $user->getName() )
				->inContentLanguage()
				->escaped();

			$comment .= ' ' . $formData['handle-comment'];
		}

		$this->requestManager->addComment( $comment, $commentUser ?? $user );
		$this->requestManager->logStatusUpdate(
			$formData['handle-comment'], $formData['handle-status'], $user
		);

		$this->requestManager->sendNotification(
			$comment, 'importdump-request-status-update', $user
		);

		$this->context->getOutput()->addHTML( Html::successBox(
			$this->context->msg( 'importdump-status-updated-success' )->escaped()
		) );
	}
}
