<?php

namespace Miraheze\ImportDump;

use Config;
use Html;
use HTMLForm;
use IContextSource;
use Linker;
use MediaWiki\Permissions\PermissionManager;

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
				'section' => 'request',
				'default' => $this->importDumpRequestManager->getReason(),
				'raw' => true,
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
				],
				'submit-comment' => [
					'type' => 'submit',
					'default' => wfMessage( 'htmlform-submit' )->text(),
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
				'info-submission' => [
					'type' => 'info',
					'default' => wfMessage( 'importdump-info-submission' )->text(),
					'section' => 'handle',
				],
				'submission-action' => [
					'type' => 'select',
					'label-message' => 'importdump-label-action',
					'options' => [
						wfMessage( 'importdump-inprogress' )->text() => 'inprogress',
						wfMessage( 'importdump-complete' )->text() => 'complete',
						wfMessage( 'importdump-decline' )->text() => 'decline',
					],
					'default' => $this->importDumpRequestManager->getStatus(),
					'cssclass' => 'importdump-infuse',
					'section' => 'handle',
				],
				'reason' => [
					'label-message' => 'importdump-label-reason',
					'cssclass' => 'importdump-infuse',
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
	 * @param string $target
	 * @return string|bool
	 */
	public function isValidDatabase( string $target ) {
		if ( !in_array( $target, $this->config->get( 'LocalDatabases' ) ) ) {
			return wfMessage( 'importdump-invalid-target' )->escaped();
		}

		return true;
	}

	/**
	 * @param string $reason
	 * @return string|bool
	 */
	public function isValidReason( string $reason ) {
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

		$context->getOutput()->addModules( [ 'ext.importdump.oouiform' ] );

		if ( $requestID === 0 || !$this->importDumpRequestManager->exists() ) {
			$context->getOutput()->addHTML(
				Html::errorBox( wfMessage( 'importdump-unknown' )->escaped() )
			);

			return null;
		}

		$formDescriptor = $this->getFormDescriptor( $context );
		$htmlForm = new ImportDumpOOUIForm( $formDescriptor, $context, 'importdumprequestqueue' );

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
	 * @return bool
	 */
	protected function submitForm(
		array $formData,
		HTMLForm $form
	) {
		$out = $form->getContext()->getOutput();
		$user = $form->getUser();

		if ( !$user->isRegistered() ) {
			$out->addHTML( Html::errorBox( wfMessage( 'exception-nologin-text' )->parse() ) );

			return false;
		}

		$out->addHTML( Html::successBox( wfMessage( 'importdump-edit-success' )->escaped() ) );

		return true;
	}
}
