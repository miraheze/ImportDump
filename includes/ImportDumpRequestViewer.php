<?php

namespace Miraheze\ImportDump;

use Config;
use Html;
use HTMLForm;
use IContextSource;
use Linker;
use MediaWiki\Permissions\PermissionManager;
use Status;
use User;
use UserNotLoggedIn;
use WikiMap;

class ImportDumpRequestViewer {

	/** @var Config */
	private $config;

	/** @var IContextSource */
	private $context;

	/** @var ImportDumpRequestManager */
	private $importDumpRequestManager;

	/** @var PermissionManager */
	private $permissionManager;

	/**
	 * @param Config $config
	 * @param IContextSource $context
	 * @param ImportDumpRequestManager $importDumpRequestManager
	 * @param PermissionManager $permissionManager
	 */
	public function __construct(
		Config $config,
		IContextSource $context,
		ImportDumpRequestManager $importDumpRequestManager,
		PermissionManager $permissionManager
	) {
		$this->config = $config;
		$this->context = $context;
		$this->importDumpRequestManager = $importDumpRequestManager;
		$this->permissionManager = $permissionManager;
	}

	/**
	 * @return array
	 */
	public function getFormDescriptor(): array {
		$user = $this->context->getUser();

		if (
			$this->importDumpRequestManager->isPrivate() &&
			$user->getName() !== $this->importDumpRequestManager->getRequester()->getName() &&
			!$this->permissionManager->userHasRight( $user, 'view-private-import-dump-requests' )
		) {
			$this->context->getOutput()->addHTML(
				Html::errorBox( $this->context->msg( 'importdump-private' )->escaped() )
			);

			return [];
		}

		if ( $this->importDumpRequestManager->isLocked() ) {
			$this->context->getOutput()->addHTML(
				Html::errorBox( $this->context->msg( 'importdump-request-locked' )->escaped() )
			);
		}

		$formDescriptor = [
			'source' => [
				'label-message' => 'importdump-label-source',
				'type' => 'url',
				'readonly' => true,
				'section' => 'details',
				'default' => $this->importDumpRequestManager->getSource(),
			],
			'target' => [
				'label-message' => 'importdump-label-target',
				'type' => 'text',
				'readonly' => true,
				'section' => 'details',
				'default' => $this->importDumpRequestManager->getTarget(),
			],
			'requester' => [
				'label-message' => 'importdump-label-requester',
				'type' => 'info',
				'section' => 'details',
				'default' => htmlspecialchars( $this->importDumpRequestManager->getRequester()->getName() ) .
					Linker::userToolLinks(
						$this->importDumpRequestManager->getRequester()->getId(),
						$this->importDumpRequestManager->getRequester()->getName()
					),
				'raw' => true,
			],
			'requestedDate' => [
				'label-message' => 'importdump-label-requested-date',
				'type' => 'info',
				'section' => 'details',
				'default' => $this->context->getLanguage()->timeanddate(
					$this->importDumpRequestManager->getTimestamp(), true
				),
			],
			'status' => [
				'label-message' => 'importdump-label-status',
				'type' => 'text',
				'readonly' => true,
				'section' => 'details',
				'default' => $this->context->msg(
					'importdump-label-' . $this->importDumpRequestManager->getStatus()
				)->text(),
			],
			'reason' => [
				'type' => 'textarea',
				'rows' => 4,
				'readonly' => true,
				'label-message' => 'importdump-label-reason',
				'default' => $this->importDumpRequestManager->getReason(),
				'raw' => true,
				'cssclass' => 'importdump-infuse',
				'section' => 'details',
			],
		];

		foreach ( $this->importDumpRequestManager->getComments() as $comment ) {
			$formDescriptor['comment' . $comment['timestamp'] ] = [
				'type' => 'textarea',
				'readonly' => true,
				'section' => 'comments',
				'rows' => 4,
				'label-message' => [
					'importdump-header-comment-withtimestamp',
					$comment['user']->getName(),
					$this->context->getLanguage()->timeanddate( $comment['timestamp'], true ),
				],
				'default' => $comment['comment'],
			];
		}

		if (
			$this->permissionManager->userHasRight( $user, 'handle-import-dump-requests' ) ||
			$user->getActorId() === $this->importDumpRequestManager->getRequester()->getActorId()
		) {
			$formDescriptor += [
				'comment' => [
					'type' => 'textarea',
					'rows' => 4,
					'label-message' => 'importdump-label-comment',
					'section' => 'comments',
					'validation-callback' => [ $this, 'isValidComment' ],
					'disabled' => $this->importDumpRequestManager->isLocked(),
				],
				'submit-comment' => [
					'type' => 'submit',
					'default' => $this->context->msg( 'importdump-label-add-comment' )->text(),
					'section' => 'comments',
					'disabled' => $this->importDumpRequestManager->isLocked(),
				],
				'edit-source' => [
					'label-message' => 'importdump-label-source',
					'type' => 'url',
					'section' => 'editing',
					'required' => true,
					'default' => $this->importDumpRequestManager->getSource(),
					'disabled' => $this->importDumpRequestManager->isLocked(),
				],
				'edit-target' => [
					'label-message' => 'importdump-label-target',
					'type' => 'text',
					'section' => 'editing',
					'required' => true,
					'default' => $this->importDumpRequestManager->getTarget(),
					'validation-callback' => [ $this, 'isValidDatabase' ],
					'disabled' => $this->importDumpRequestManager->isLocked(),
				],
				'edit-reason' => [
					'type' => 'textarea',
					'rows' => 4,
					'label-message' => 'importdump-label-reason',
					'section' => 'editing',
					'required' => true,
					'default' => $this->importDumpRequestManager->getReason(),
					'validation-callback' => [ $this, 'isValidReason' ],
					'disabled' => $this->importDumpRequestManager->isLocked(),
					'raw' => true,
				],
				'submit-edit' => [
					'type' => 'submit',
					'default' => $this->context->msg( 'importdump-label-edit-request' )->text(),
					'section' => 'editing',
					'disabled' => $this->importDumpRequestManager->isLocked(),
				],
			];
		}

		if ( $this->permissionManager->userHasRight( $user, 'handle-import-dump-requests' ) ) {
			$validRequest = true;
			$status = $this->importDumpRequestManager->getStatus();

			if ( $this->importDumpRequestManager->fileExists() ) {
				$fileInfo = $this->context->msg( 'importdump-info-command' )->plaintextParams(
					$this->importDumpRequestManager->getCommand()
				)->parse();

				$fileInfo .= Html::element( 'button', [
						'type' => 'button',
						'onclick' => 'navigator.clipboard.writeText( $( \'.mw-message-box-notice code\' ).text() );',
					],
					$this->context->msg( 'importdump-button-copy' )->text()
				);

				if ( $this->importDumpRequestManager->getFileSize() > 0 ) {
					$fileInfo .= Html::element( 'br' );
					$fileInfo .= $this->context->msg( 'importdump-info-filesize' )->sizeParams(
						$this->importDumpRequestManager->getFileSize()
					)->parse();
				}

				$info = Html::noticeBox( $fileInfo, '' );
			} else {
				$info = Html::errorBox(
					$this->context->msg( 'importdump-info-no-file-found',
						$this->importDumpRequestManager->getFilePath()
					)->escaped()
				);

				$validRequest = false;
				if ( $status === 'pending' || $status === 'inprogress' ) {
					$status = 'declined';
				}
			}

			$info .= Html::noticeBox(
				$this->context->msg( 'importdump-info-groups',
					$this->importDumpRequestManager->getRequester()->getName(),
					$this->importDumpRequestManager->getTarget(),
					$this->context->getLanguage()->commaList(
						$this->importDumpRequestManager->getUserGroupsFromTarget()
					)
				)->escaped(),
				''
			);

			if ( $this->importDumpRequestManager->isPrivate() ) {
				$info .= Html::warningBox(
					$this->context->msg( 'importdump-info-request-private' )->escaped()
				);
			}

			if ( $this->importDumpRequestManager->getRequester()->getBlock() ) {
				$info .= Html::warningBox(
					$this->context->msg( 'importdump-info-requester-locally-blocked',
						$this->importDumpRequestManager->getRequester()->getName(),
						WikiMap::getCurrentWikiId()
					)->escaped()
				);
			}

			// @phan-suppress-next-line PhanDeprecatedFunction Only for MW 1.39 or lower.
			if ( $this->importDumpRequestManager->getRequester()->getGlobalBlock() ) {
				$info .= Html::errorBox(
					$this->context->msg( 'importdump-info-requester-globally-blocked',
						$this->importDumpRequestManager->getRequester()->getName()
					)->escaped()
				);

				$validRequest = false;
				if ( $status === 'pending' || $status === 'inprogress' ) {
					$status = 'declined';
				}
			}

			if ( $this->importDumpRequestManager->getRequester()->isLocked() ) {
				$info .= Html::errorBox(
					$this->context->msg( 'importdump-info-requester-locked',
						$this->importDumpRequestManager->getRequester()->getName()
					)->escaped()
				);

				$validRequest = false;
				if ( $status === 'pending' || $status === 'inprogress' ) {
					$status = 'declined';
				}
			}

			if ( !$this->importDumpRequestManager->getInterwikiPrefix() ) {
				$info .= Html::errorBox(
					$this->context->msg( 'importdump-info-no-interwiki-prefix',
						$this->importDumpRequestManager->getTarget(),
						parse_url( $this->importDumpRequestManager->getSource(), PHP_URL_HOST )
					)->escaped()
				);
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
					'default' => $this->importDumpRequestManager->isLocked(),
					'section' => 'handling',
				],
			];

			if ( $this->permissionManager->userHasRight( $user, 'view-private-import-dump-requests' ) ) {
				$formDescriptor += [
					'handle-private' => [
						'type' => 'check',
						'label-message' => 'importdump-label-private',
						'default' => $this->importDumpRequestManager->isPrivate(),
						'disabled' => $this->importDumpRequestManager->isPrivate( true ),
						'section' => 'handling',
					],
				];
			}

			if (
				!$this->importDumpRequestManager->getInterwikiPrefix() &&
				$this->permissionManager->userHasRight( $user, 'handle-import-dump-interwiki' )
			) {
				$source = $this->importDumpRequestManager->getSource();
				$target = $this->importDumpRequestManager->getTarget();

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
						'default' => $this->context->msg( 'htmlform-submit' )->text(),
						'section' => 'handling',
					],
				];
			}

			$formDescriptor += [
				'handle-status' => [
					'type' => 'select',
					'label-message' => 'importdump-label-update-status',
					'options-messages' => [
						'importdump-label-pending' => 'pending',
						'importdump-label-inprogress' => 'inprogress',
						'importdump-label-complete' => 'complete',
						'importdump-label-declined' => 'declined',
					],
					'default' => $status,
					'disabled' => !$validRequest,
					'cssclass' => 'importdump-infuse',
					'section' => 'handling',
				],
				'handle-comment' => [
					'type' => 'textarea',
					'rows' => 4,
					'label-message' => 'importdump-label-status-updated-comment',
					'section' => 'handling',
				],
				'submit-handle' => [
					'type' => 'submit',
					'default' => $this->context->msg( 'htmlform-submit' )->text(),
					'section' => 'handling',
				],
			];
		}

		return $formDescriptor;
	}

	/**
	 * @param ?string $comment
	 * @param array $alldata
	 * @return string|bool
	 */
	public function isValidComment( ?string $comment, array $alldata ) {
		if ( isset( $alldata['submit-comment'] ) && ( !$comment || ctype_space( $comment ) ) ) {
			return Status::newFatal( 'htmlform-required' )->getMessage();
		}

		return true;
	}

	/**
	 * @param ?string $target
	 * @return string|bool
	 */
	public function isValidDatabase( ?string $target ) {
		if ( !in_array( $target, $this->config->get( 'LocalDatabases' ) ) ) {
			return Status::newFatal( 'importdump-invalid-target' )->getMessage();
		}

		return true;
	}

	/**
	 * @param ?string $reason
	 * @return string|bool
	 */
	public function isValidReason( ?string $reason ) {
		if ( !$reason || ctype_space( $reason ) ) {
			return Status::newFatal( 'htmlform-required' )->getMessage();
		}

		return true;
	}

	/**
	 * @param ?string $prefix
	 * @param array $alldata
	 * @return string|bool
	 */
	public function isValidInterwikiPrefix( ?string $prefix, array $alldata ) {
		if ( isset( $alldata['submit-interwiki'] ) && ( !$prefix || ctype_space( $prefix ) ) ) {
			return Status::newFatal( 'htmlform-required' )->getMessage();
		}

		return true;
	}

	/**
	 * @param ?string $url
	 * @param array $alldata
	 * @return string|bool
	 */
	public function isValidInterwikiUrl( ?string $url, array $alldata ) {
		if ( !isset( $alldata['submit-interwiki'] ) ) {
			return true;
		}

		if ( !$url || ctype_space( $url ) ) {
			return Status::newFatal( 'htmlform-required' )->getMessage();
		}

		if (
			!parse_url( $url, PHP_URL_SCHEME ) ||
			!parse_url( $url, PHP_URL_HOST )
		) {
			return Status::newFatal( 'importdump-invalid-interwiki-url' )->getMessage();
		}

		return true;
	}

	/**
	 * @param int $requestID
	 * @return ?ImportDumpOOUIForm
	 */
	public function getForm( int $requestID ): ?ImportDumpOOUIForm {
		$this->importDumpRequestManager->fromID( $requestID );
		$out = $this->context->getOutput();

		if ( $requestID === 0 || !$this->importDumpRequestManager->exists() ) {
			$out->addHTML(
				Html::errorBox( $this->context->msg( 'importdump-unknown' )->escaped() )
			);

			return null;
		}

		$out->addModules( [ 'ext.importdump.oouiform' ] );
		$out->addModuleStyles( [ 'ext.importdump.oouiform.styles' ] );
		$out->addModuleStyles( [ 'oojs-ui-widgets.styles' ] );

		$formDescriptor = $this->getFormDescriptor();
		$htmlForm = new ImportDumpOOUIForm( $formDescriptor, $this->context, 'importdump-section' );

		$htmlForm->setId( 'importdump-request-viewer' );
		$htmlForm->suppressDefaultSubmit();
		$htmlForm->setSubmitCallback(
			function ( array $formData, HTMLForm $form ) {
				return $this->submitForm( $formData, $form );
			}
		);

		return $htmlForm;
	}

	/**
	 * @param array $formData
	 * @param HTMLForm $form
	 */
	protected function submitForm(
		array $formData,
		HTMLForm $form
	) {
		$user = $form->getUser();
		if ( !$user->isRegistered() ) {
			throw new UserNotLoggedIn( 'exception-nologin-text', 'exception-nologin' );
		}

		$out = $form->getContext()->getOutput();

		if ( isset( $formData['submit-comment'] ) ) {
			$this->importDumpRequestManager->addComment( $formData['comment'], $user );
			$out->addHTML( Html::successBox( $this->context->msg( 'importdump-comment-success' )->escaped() ) );

			return;
		}

		if ( isset( $formData['submit-edit'] ) ) {
			$this->importDumpRequestManager->startAtomic( __METHOD__ );

			$changes = [];
			if ( $this->importDumpRequestManager->getReason() !== $formData['edit-reason'] ) {
				$changes[] = $this->context->msg( 'importdump-request-edited-reason' )->plaintextParams(
					$this->importDumpRequestManager->getReason(),
					$formData['edit-reason']
				)->escaped();

				$this->importDumpRequestManager->setReason( $formData['edit-reason'] );
			}

			if ( $this->importDumpRequestManager->getSource() !== $formData['edit-source'] ) {
				$changes[] = $this->context->msg( 'importdump-request-edited-source' )->plaintextParams(
					$this->importDumpRequestManager->getSource(),
					$formData['edit-source']
				)->escaped();

				$this->importDumpRequestManager->setSource( $formData['edit-source'] );
			}

			if ( $this->importDumpRequestManager->getTarget() !== $formData['edit-target'] ) {
				$changes[] = $this->context->msg(
					'importdump-request-edited-target',
					$this->importDumpRequestManager->getTarget(),
					$formData['edit-target']
				)->escaped();

				$this->importDumpRequestManager->setTarget( $formData['edit-target'] );
			}

			if ( !$changes ) {
				$this->importDumpRequestManager->endAtomic( __METHOD__ );

				$out->addHTML( Html::errorBox( $this->context->msg( 'importdump-no-changes' )->escaped() ) );

				return;
			}

			if ( $this->importDumpRequestManager->getStatus() === 'declined' ) {
				$this->importDumpRequestManager->setStatus( 'pending' );

				$comment = $this->context->msg( 'importdump-request-reopened', $user->getName() )->rawParams(
					implode( "\n", $changes )
				)->inContentLanguage()->escaped();

				$this->importDumpRequestManager->logStatusUpdate( $comment, 'pending', $user );

				$this->importDumpRequestManager->addComment( $comment, User::newSystemUser( 'ImportDump Extension' ) );

				$this->importDumpRequestManager->sendNotification(
					$comment, 'importdump-request-status-update', $user
				);
			} else {
				$comment = $this->context->msg( 'importdump-request-edited', $user->getName() )->rawParams(
					implode( "\n", $changes )
				)->inContentLanguage()->escaped();

				$this->importDumpRequestManager->addComment( $comment, User::newSystemUser( 'ImportDump Extension' ) );
			}

			$this->importDumpRequestManager->endAtomic( __METHOD__ );

			$out->addHTML( Html::successBox( $this->context->msg( 'importdump-edit-success' )->escaped() ) );

			return;
		}

		if ( isset( $formData['submit-interwiki'] ) ) {
			if ( $this->importDumpRequestManager->insertInterwikiPrefix(
				$formData['handle-interwiki-prefix'],
				$formData['handle-interwiki-url'],
				$user
			) ) {
				$out->addHTML( Html::successBox(
					$this->context->msg( 'importdump-interwiki-success',
						$this->importDumpRequestManager->getTarget()
					)->escaped() )
				);

				return;
			}

			$out->addHTML( Html::errorBox(
				$this->context->msg( 'importdump-interwiki-failed',
					$this->importDumpRequestManager->getTarget()
				)->escaped() )
			);

			return;
		}

		if ( isset( $formData['submit-handle'] ) ) {
			$this->importDumpRequestManager->startAtomic( __METHOD__ );
			$changes = [];

			if ( $this->importDumpRequestManager->isLocked() !== (bool)$formData['handle-lock'] ) {
				$changes[] = $this->importDumpRequestManager->isLocked() ?
					'unlocked' : 'locked';

				$this->importDumpRequestManager->setLocked( (int)$formData['handle-lock'] );
			}

			if (
				isset( $formData['handle-private'] ) &&
				$this->importDumpRequestManager->isPrivate() !== (bool)$formData['handle-private']
			) {
				$changes[] = $this->importDumpRequestManager->isPrivate() ?
					'public' : 'private';

				$this->importDumpRequestManager->setPrivate( (int)$formData['handle-private'] );
			}

			if ( $this->importDumpRequestManager->getStatus() === $formData['handle-status'] ) {
				$this->importDumpRequestManager->endAtomic( __METHOD__ );

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

			$this->importDumpRequestManager->setStatus( $formData['handle-status'] );

			$statusMessage = $this->context->msg( 'importdump-label-' . $formData['handle-status'] )
				->inContentLanguage()
				->text();

			$comment = $this->context->msg( 'importdump-status-updated', strtolower( $statusMessage ) )
				->inContentLanguage()
				->escaped();

			if ( $formData['handle-comment'] ) {
				$commentUser = User::newSystemUser( 'ImportDump Status Update' );

				$comment .= "\n" . $this->context->msg( 'importdump-comment-given', $user->getName() )
					->inContentLanguage()
					->escaped();

				$comment .= ' ' . $formData['handle-comment'];
			}

			$this->importDumpRequestManager->addComment( $comment, $commentUser ?? $user );
			$this->importDumpRequestManager->logStatusUpdate(
				$formData['handle-comment'], $formData['handle-status'], $user
			);

			$this->importDumpRequestManager->sendNotification( $comment, 'importdump-request-status-update', $user );

			$this->importDumpRequestManager->endAtomic( __METHOD__ );

			$out->addHTML( Html::successBox( $this->context->msg( 'importdump-status-updated-success' )->escaped() ) );
		}
	}
}
