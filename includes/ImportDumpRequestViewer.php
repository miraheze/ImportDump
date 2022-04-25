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

class ImportDumpRequestViewer {

	/** @var Config */
	private $config;

	/** @var ImportDumpRequestManager */
	private $importDumpRequestManager;

	/** @var PermissionManager */
	private $permissionManager;

	/**
	 * @param Config $config
	 * @param ImportDumpRequestManager $importDumpRequestManager
	 * @param PermissionManager $permissionManager
	 */
	public function __construct(
		Config $config,
		ImportDumpRequestManager $importDumpRequestManager,
		PermissionManager $permissionManager
	) {
		$this->config = $config;
		$this->importDumpRequestManager = $importDumpRequestManager;
		$this->permissionManager = $permissionManager;
	}

	/**
	 * @param IContextSource $context
	 * @return array
	 */
	public function getFormDescriptor( IContextSource $context ): array {
		$user = $context->getUser();

		if (
			$this->importDumpRequestManager->isPrivate() &&
			!$this->permissionManager->userHasRight( $user, 'view-private-import-requests' )
		) {
			$context->getOutput()->addHTML(
				Html::errorBox( wfMessage( 'importdump-unknown' )->escaped() )
			);

			return [];
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
				'default' => $this->importDumpRequestManager->getRequester()->getName() .
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
				'default' => $context->getLanguage()->timeanddate(
					$this->importDumpRequestManager->getTimestamp(), true
				),
			],
			'status' => [
				'label-message' => 'importdump-label-status',
				'type' => 'text',
				'readonly' => true,
				'section' => 'details',
				'default' => wfMessage(
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
				'label' => wfMessage( 'importdump-header-comment-withtimestamp' )
						->rawParams( $comment['user']->getName() )
						->params( $context->getLanguage()->timeanddate( $comment['timestamp'], true ) )
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
				],
				'submit-comment' => [
					'type' => 'submit',
					'default' => wfMessage( 'importdump-label-add-comment' )->text(),
					'section' => 'comments',
				],
				'edit-source' => [
					'label-message' => 'importdump-label-source',
					'type' => 'url',
					'section' => 'editing',
					'required' => true,
					'default' => $this->importDumpRequestManager->getSource(),
				],
				'edit-target' => [
					'label-message' => 'importdump-label-target',
					'type' => 'text',
					'section' => 'editing',
					'required' => true,
					'default' => $this->importDumpRequestManager->getTarget(),
					'validation-callback' => [ $this, 'isValidDatabase' ],
				],
				'edit-reason' => [
					'type' => 'textarea',
					'rows' => 4,
					'label-message' => 'importdump-label-reason',
					'section' => 'editing',
					'required' => true,
					'default' => $this->importDumpRequestManager->getReason(),
					'validation-callback' => [ $this, 'isValidReason' ],
					'raw' => true,
				],
				'submit-edit' => [
					'type' => 'submit',
					'default' => wfMessage( 'importdump-label-edit-request' )->text(),
					'section' => 'editing',
				],
			];
		}

		if ( $this->permissionManager->userHasRight( $user, 'handle-import-requests' ) ) {
			$validRequest = true;
			$status = $this->importDumpRequestManager->getStatus();

			$info = Html::warningBox(
				wfMessage( 'importdump-info-command' )->plaintextParams(
					$this->importDumpRequestManager->getCommand()
				)->escaped()
			);

			$info .= Html::warningBox(
				wfMessage( 'importdump-info-groups',
					$this->importDumpRequestManager->getRequester()->getName(),
					$this->importDumpRequestManager->getTarget(),
					$context->getLanguage()->commaList(
						$this->importDumpRequestManager->getUserGroupsFromTarget()
					)
				)->escaped()
			);

			if ( !$this->importDumpRequestManager->getInterwikiPrefix() ) {
				$info .= Html::errorBox(
					wfMessage( 'importdump-info-no-interwiki-prefix',
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
				'handle-status' => [
					'type' => 'select',
					'label-message' => 'importdump-label-update-status',
					'options-messages' => [
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
					'default' => wfMessage( 'htmlform-submit' )->text(),
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
			return wfMessage( 'htmlform-required', 'parseinline' )->escaped();
		}

		return true;
	}

	/**
	 * @param ?string $target
	 * @return string|bool
	 */
	public function isValidDatabase( ?string $target ) {
		if ( !in_array( $target, $this->config->get( 'LocalDatabases' ) ) ) {
			return wfMessage( 'importdump-invalid-target' )->escaped();
		}

		return true;
	}

	/**
	 * @param ?string $reason
	 * @return string|bool
	 */
	public function isValidReason( ?string $reason ) {
		if ( !$reason || ctype_space( $reason ) ) {
			return wfMessage( 'htmlform-required', 'parseinline' )->escaped();
		}

		return true;
	}

	/**
	 * @param int $requestID
	 * @param IContextSource $context
	 * @return ?ImportDumpOOUIForm
	 */
	public function getForm(
		int $requestID,
		IContextSource $context
	): ?ImportDumpOOUIForm {
		$this->importDumpRequestManager->fromID( $requestID );
		$out = $context->getOutput();

		if ( $requestID === 0 || !$this->importDumpRequestManager->exists() ) {
			$out->addHTML(
				Html::errorBox( wfMessage( 'importdump-unknown' )->escaped() )
			);

			return null;
		}

		$out->addModules( [ 'ext.importdump.oouiform' ] );
		$out->addModuleStyles( [ 'ext.importdump.oouiform.styles' ] );
		$out->addModuleStyles( [ 'oojs-ui-widgets.styles' ] );

		$formDescriptor = $this->getFormDescriptor( $context );
		$htmlForm = new ImportDumpOOUIForm( $formDescriptor, $context, 'importdump-section' );

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
			$out->addHTML( Html::successBox( wfMessage( 'importdump-comment-success' )->escaped() ) );

			return;
		}

		if ( isset( $formData['submit-edit'] ) ) {
			$this->importDumpRequestManager->startAtomic( __METHOD__ );

			$this->importDumpRequestManager->setReason( $formData['edit-reason'] );
			$this->importDumpRequestManager->setSource( $formData['edit-source'] );
			$this->importDumpRequestManager->setTarget( $formData['edit-target'] );

			if ( $this->importDumpRequestManager->getStatus() === 'declined' ) {
				$this->importDumpRequestManager->setStatus( 'pending' );
			}

			$this->importDumpRequestManager->endAtomic( __METHOD__ );

			$out->addHTML( Html::successBox( wfMessage( 'importdump-edit-success' )->escaped() ) );

			return;
		}

		if ( isset( $formData['submit-handle'] ) ) {
			$this->importDumpRequestManager->startAtomic( __METHOD__ );
			$this->importDumpRequestManager->setStatus( $formData['handle-status'] );

			$statusMessage = wfMessage( 'importdump-label-' . $formData['handle-status'] )
				->inContentLanguage()
				->text();

			$comment = wfMessage( 'importdump-status-updated', strtolower( $statusMessage ) )
				->inContentLanguage()
				->escaped();

			if ( $formData['handle-comment'] ) {
				$comment .= "\n" . wfMessage( 'importdump-comment-given', $user->getName() )
					->inContentLanguage()
					->escaped();

				$comment .= ' ' . $formData['handle-comment'];
			}

			$this->importDumpRequestManager->addComment( $comment, User::newSystemUser( 'ImportDump Status Update' ) );
			$this->importDumpRequestManager->endAtomic( __METHOD__ );

			$out->addHTML( Html::successBox( wfMessage( 'importdump-status-updated-success' )->escaped() ) );
		}
	}
}
