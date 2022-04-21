<?php

namespace Miraheze\ImportDump;

use Config;
use Html;
use HTMLForm;
use IContextSource;
use Linker;
use MediaWiki\Permissions\PermissionManager;
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

		$unformattedStatus = $this->importDumpRequestManager->getStatus();
		$status = ( $unformattedStatus === 'inprogress' ) ? 'In progress' : ucfirst( $unformattedStatus );

		$formDescriptor = [
			'source' => [
				'label-message' => 'importdump-label-source',
				'type' => 'url',
				'readonly' => true,
				'section' => 'request',
				'default' => $this->importDumpRequestManager->getSource(),
			],
			'target' => [
				'label-message' => 'importdump-label-target',
				'type' => 'text',
				'readonly' => true,
				'section' => 'request',
				'default' => $this->importDumpRequestManager->getTarget(),
			],
			'requester' => [
				// @phan-suppress-next-line SecurityCheck-XSS
				'label-message' => 'importdump-label-requester',
				'type' => 'info',
				'section' => 'request',
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
				'section' => 'request',
				'default' => $context->getLanguage()->timeanddate(
					$this->importDumpRequestManager->getTimestamp(), true
				),
			],
			'status' => [
				'label-message' => 'importdump-label-status',
				'type' => 'text',
				'readonly' => true,
				'section' => 'request',
				'default' => $status,
			],
			'reason' => [
				'type' => 'textarea',
				'rows' => 4,
				'readonly' => true,
				'label-message' => 'importdump-label-reason',
				'default' => $this->importDumpRequestManager->getReason(),
				'raw' => true,
				'cssclass' => 'importdump-infuse',
				'section' => 'request',
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
					'section' => 'edit',
					'default' => $this->importDumpRequestManager->getSource(),
				],
				'edit-target' => [
					'label-message' => 'importdump-label-target',
					'type' => 'text',
					'section' => 'edit',
					'required' => true,
					'default' => $this->importDumpRequestManager->getTarget(),
					'validation-callback' => [ $this, 'isValidDatabase' ],
				],
				'edit-reason' => [
					'type' => 'textarea',
					'rows' => 4,
					'label-message' => 'importdump-label-reason',
					'section' => 'edit',
					'required' => true,
					'default' => $this->importDumpRequestManager->getReason(),
					'validation-callback' => [ $this, 'isValidReason' ],
					'raw' => true,
				],
				'submit-edit' => [
					'type' => 'submit',
					'default' => wfMessage( 'importdump-label-edit-request' )->text(),
					'section' => 'edit',
				],
			];
		}

		if ( $this->permissionManager->userHasRight( $user, 'handle-import-requests' ) ) {
			$formDescriptor += [
				'handle-info' => [
					'type' => 'info',
					'default' => wfMessage( 'importdump-handle-info' )->text(),
					'section' => 'handle',
				],
				'handle-status' => [
					'type' => 'select',
					'label-message' => 'importdump-label-update-status',
					'options' => [
						wfMessage( 'importdump-status-inprogress' )->text() => 'inprogress',
						wfMessage( 'importdump-status-complete' )->text() => 'complete',
						wfMessage( 'importdump-status-declined' )->text() => 'declined',
					],
					'default' => $this->importDumpRequestManager->getStatus(),
					'cssclass' => 'importdump-infuse',
					'section' => 'handle',
				],
				'handle-comment' => [
					'type' => 'textarea',
					'rows' => 4,
					'label-message' => 'importdump-label-status-updated-comment',
					'section' => 'handle',
				],
				'submit-handle' => [
					'type' => 'submit',
					'default' => wfMessage( 'htmlform-submit' )->text(),
					'section' => 'handle',
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
		$out->addModuleStyles( [ 'oojs-ui-widgets.styles' ] );

		$formDescriptor = $this->getFormDescriptor( $context );
		$htmlForm = new ImportDumpOOUIForm( $formDescriptor, $context, 'importdump' );

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

			$statusMessage = wfMessage( 'importdump-status-' . $formData['handle-status'] )
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

			$this->importDumpRequestManager->addComment( $comment, $user );
			$this->importDumpRequestManager->endAtomic( __METHOD__ );

			$out->addHTML( Html::successBox( wfMessage( 'importdump-status-updated-success' )->escaped() ) );
		}
	}
}
