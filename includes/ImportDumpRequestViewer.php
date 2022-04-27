<?php

namespace Miraheze\ImportDump;

use Config;
use Html;
use HTMLForm;
use IContextSource;
use Linker;
use MediaWiki\Permissions\PermissionManager;
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
			!$this->permissionManager->userHasRight( $user, 'view-private-import-requests' )
		) {
			$this->context->getOutput()->addHTML(
				Html::errorBox( $this->context->msg( 'importdump-unknown' )->escaped() )
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
				'label' => $this->context->msg( 'importdump-header-comment-withtimestamp' )
						->rawParams( $comment['user']->getName() )
						->params( $this->context->getLanguage()->timeanddate( $comment['timestamp'], true ) )
						->text(),
				'default' => $comment['comment'],
			];
		}

		if (
			$this->permissionManager->userHasRight( $user, 'handle-import-requests' ) ||
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

		if ( $this->permissionManager->userHasRight( $user, 'handle-import-requests' ) ) {
			$validRequest = true;
			$status = $this->importDumpRequestManager->getStatus();

			$info = Html::warningBox(
				$this->context->msg( 'importdump-info-command' )->plaintextParams(
					$this->importDumpRequestManager->getCommand()
				)->escaped()
			);

			$info .= Html::warningBox(
				$this->context->msg( 'importdump-info-groups',
					$this->importDumpRequestManager->getRequester()->getName(),
					$this->importDumpRequestManager->getTarget(),
					$this->context->getLanguage()->commaList(
						$this->importDumpRequestManager->getUserGroupsFromTarget()
					)
				)->escaped()
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
						$this->importDumpRequestManager->getSource()
					)->escaped()
				);

				$validRequest = false;
				if ( $status === 'pending' || $status === 'inprogress' ) {
					$status = 'declined';
				}
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

			if ( $this->permissionManager->userHasRight( $user, 'view-private-import-requests' ) ) {
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
			return $this->context->msg( 'htmlform-required', 'parseinline' )->escaped();
		}

		return true;
	}

	/**
	 * @param ?string $target
	 * @return string|bool
	 */
	public function isValidDatabase( ?string $target ) {
		if ( !in_array( $target, $this->config->get( 'LocalDatabases' ) ) ) {
			return $this->context->msg( 'importdump-invalid-target' )->escaped();
		}

		return true;
	}

	/**
	 * @param ?string $reason
	 * @return string|bool
	 */
	public function isValidReason( ?string $reason ) {
		if ( !$reason || ctype_space( $reason ) ) {
			return $this->context->msg( 'htmlform-required', 'parseinline' )->escaped();
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

			if ( $this->importDumpRequestManager->getStatus() === 'declined' ) {
				$this->importDumpRequestManager->setStatus( 'pending' );

				$comment = $this->context->msg( 'importdump-request-reopened', $user->getName() )->rawParams(
					implode( "\n", $changes )
				) )->inContentLanguage()->escaped();

				$this->importDumpRequestManager->logStatusUpdate( $comment, 'pending', $user );

				$this->importDumpRequestManager->addComment( $comment, User::newSystemUser( 'ImportDump Extension' ) );
				$this->importDumpRequestManager->sendNotification( nl2br( $comment ), 'importdump-request-status-update', $user );
			} else {
				$comment = $this->context->msg( 'importdump-request-edited', $user->getName() )->rawParams(
					implode( "\n", $changes )
				) )->inContentLanguage()->escaped();

				$this->importDumpRequestManager->addComment( $comment, User::newSystemUser( 'ImportDump Extension' ) );
			}

			$this->importDumpRequestManager->endAtomic( __METHOD__ );

			$out->addHTML( Html::successBox( $this->context->msg( 'importdump-edit-success' )->escaped() ) );

			return;
		}

		if ( isset( $formData['submit-handle'] ) ) {
			$this->importDumpRequestManager->startAtomic( __METHOD__ );

			if ( $this->importDumpRequestManager->isLocked() !== (bool)$formData['handle-lock'] ) {
				$this->importDumpRequestManager->setLocked( (int)$formData['handle-lock'] );
			}

			if (
				isset( $formData['handle-private'] ) &&
				$this->importDumpRequestManager->isPrivate() !== (bool)$formData['handle-private']
			) {
				$this->importDumpRequestManager->setPrivate( (int)$formData['handle-private'] );
			}

			if ( $this->importDumpRequestManager->getStatus() === $formData['handle-status'] ) {
				$this->importDumpRequestManager->endAtomic( __METHOD__ );

				return;
			}

			$statusMessage = $this->context->msg( 'importdump-label-' . $formData['handle-status'] )
				->inContentLanguage()
				->text();

			$comment = $this->context->msg( 'importdump-status-updated', strtolower( $statusMessage ) )
				->inContentLanguage()
				->escaped();

			if ( $formData['handle-comment'] ) {
				$comment .= "\n" . $this->context->msg( 'importdump-comment-given', $user->getName() )
					->inContentLanguage()
					->escaped();

				$comment .= ' ' . $formData['handle-comment'];
			}

			$this->importDumpRequestManager->addComment( $comment, User::newSystemUser( 'ImportDump Status Update' ) );
			$this->importDumpRequestManager->logStatusUpdate(
				$formData['handle-comment'], $formData['handle-status'], $user
			);

			$this->importDumpRequestManager->sendNotification( $comment, 'importdump-request-status-update', $user );

			$this->importDumpRequestManager->endAtomic( __METHOD__ );

			$out->addHTML( Html::successBox( $this->context->msg( 'importdump-status-updated-success' )->escaped() ) );
		}
	}
}
