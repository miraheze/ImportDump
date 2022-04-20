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

	/** @var ImportDumpRequest */
	private $importDumpRequest;

	/** @var PermissionManager */
	private $permissionManager;

	/**
	 * @param Config $config
	 * @param ImportDumpRequest $importDumpRequest
	 * @param PermissionManager $permissionManager
	 */
	public function __construct(
		Config $config,
		ImportDumpRequest $importDumpRequest,
		PermissionManager $permissionManager
	) {
		$this->config = $config;
		$this->importDumpRequest = $importDumpRequest;
		$this->permissionManager = $permissionManager;
	}

	/**
	 * @param IContextSource $context
	 * @return array
	 */
	public function getFormDescriptor( IContextSource $context ): array {
		$user = $context->getUser();

		if (
			$this->importDumpRequest->isPrivate() &&
			!$this->permissionManager->userHasRight( $user, 'view-private-import-requests' )
		) {
			$context->getOutput()->addHTML(
				Html::errorBox( wfMessage( 'importdump-unknown' )->escaped() )
			);

			return [];
		}

		$unformattedStatus = $this->importDumpRequest->getStatus();
		$status = ( $unformattedStatus === 'inprogress' ) ? 'In progress' : ucfirst( $unformattedStatus );

		$formDescriptor = [
			'source' => [
				'label-message' => 'importdump-label-source',
				'type' => 'text',
				'readonly' => true,
				'section' => 'request',
				'default' => (string)$this->importDumpRequest->getSource(),
			],
			'target' => [
				'label-message' => 'importdump-label-target',
				'type' => 'text',
				'readonly' => true,
				'section' => 'request',
				'default' => $this->importDumpRequest->getTarget(),
			],
			'requester' => [
				// @phan-suppress-next-line SecurityCheck-XSS
				'label-message' => 'importdump-label-requester',
				'type' => 'info',
				'section' => 'request',
				'default' => $this->importDumpRequest->getRequester()->getName() .
					Linker::userToolLinks(
						$this->importDumpRequest->getRequester()->getId(),
						$this->importDumpRequest->getRequester()->getName()
					),
				'raw' => true,
			],
			'requestedDate' => [
				'label-message' => 'importdump-label-requested-date',
				'type' => 'info',
				'section' => 'request',
				'default' => $context->getLanguage()->timeanddate( $this->importDumpRequest->getTimestamp(), true ),
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
				'default' => $this->importRequest->getReason(),
				'raw' => true,
			],
		];

		foreach ( $this->importDumpRequest->getComments() as $comment ) {
			$formDescriptor['comment' . $comment['timestamp'] ] = [
				'type' => 'textarea',
				'readonly' => true,
				'section' => 'comments',
				'rows' => 4,
				// @phan-suppress-next-line SecurityCheck-XSS
				'label' => wfMessage( 'importdump-header-comment-withtimestamp' )
						->rawParams( $comment['user']->getName() )
						->params( $context->getLanguage()->timeanddate( $comment['timestamp'], true ) )
						->text(),
				'default' => $comment['comment'],
			];
		}

		if (
			$permissionManager->userHasRight( $user, 'handle-import-requests' ) ||
			$user->getActorId() === $this->importDumpRequest->getRequester()->getActorId()
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
					'type' => 'text',
					'section' => 'edit',
					'default' => $this->importDumpRequest->getSource(),
				],
				'edit-target' => [
					'label-message' => 'importdump-label-target',
					'type' => 'text',
					'section' => 'edit',
					'required' => true,
					'default' => $this->importDumpRequest->getTarget(),
				],
				'edit-reason' => [
					'type' => 'textarea',
					'rows' => 4,
					'label-message' => 'importdump-label-reason',
					'section' => 'edit',
					'required' => true,
					'default' => $this->importRequest->getReason(),
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
					'default' => $this->importDumpRequest->getStatus(),
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
	 * @param string $requestID
	 * @param IContextSource $context
	 * @return ImportDumpOOUIForm
	 */
	public function getForm(
		string $requestID,
		IContextSource $context
	) {
		$this->importDumpRequest->fromID( $requestID );

		$context->getOutput()->addModules( [ 'ext.importdump.oouiform' ] );

		if ( !$this->importDumpRequest->exists() ) {
			$context->getOutput()->addHTML(
				Html::errorBox( wfMessage( 'importdump-unknown' )->escaped() )
			);

			return;
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
