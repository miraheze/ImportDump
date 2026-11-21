<?php

declare( strict_types = 1 );

namespace Miraheze\ImportDump\Viewer;

use MediaWiki\Config\Config;
use MediaWiki\Context\IContextSource;
use MediaWiki\Linker\Linker;
use MediaWiki\MainConfigNames;
use MediaWiki\Message\Message;
use Miraheze\ImportDump\ConfigNames;
use Miraheze\ImportDump\Notices\NoticeResult;
use Miraheze\ImportDump\Request\RequestManager;
use Miraheze\ImportDump\Request\RequestStatus;

use function array_unique;
use function ctype_space;
use function htmlspecialchars;
use function in_array;
use function parse_url;
use const PHP_URL_HOST;
use const PHP_URL_SCHEME;

/** Describes the form of a request, and checks what is filled in. */
class RequestFormBuilder {

	public function __construct(
		private readonly Config $config,
		private readonly IContextSource $context,
		private readonly RequestManager $requestManager,
	) {
	}

	/**
	 * @param NoticeResult $noticeResult What the notices say about the request.
	 * @param string $handlingNotices The notices for the handling tab, already rendered.
	 */
	public function build( NoticeResult $noticeResult, string $handlingNotices ): array {
		$user = $this->context->getUser();
		$authority = $this->context->getAuthority();

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
				'default' => $this->context->msg( $this->requestManager->getStatus()->getLabelKey() )->text(),
			],
		];

		$formDescriptor += $this->getStageDescriptor();

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
			$validRequest = $noticeResult->valid;
			$status = $noticeResult->status;
			$targetExists = $this->requestManager->targetExists();
			$info = $handlingNotices;

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
						'default' => $this->requestManager->isPrivate(),
						'disabled' => $this->requestManager->isTargetPrivate(),
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
							$status->getLabelKey() => $status->value,
							'importdump-label-pending' => RequestStatus::Pending->value,
							'importdump-label-inprogress' => RequestStatus::InProgress->value,
							'importdump-label-complete' => RequestStatus::Complete->value,
						] ),
						'default' => $status->value,
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
				$validStatus = !in_array(
					$status,
					[ RequestStatus::Complete, RequestStatus::InProgress, RequestStatus::Starting ],
					true
				);

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
						'disabled' => !$validStatus || $status === RequestStatus::Declined,
						'section' => 'handling',
					],
				];
			} else {
				$formDescriptor += [
					'handle-status' => [
						'type' => 'select',
						'label-message' => 'importdump-label-update-status',
						'options-messages' => [
							'importdump-label-pending' => RequestStatus::Pending->value,
							'importdump-label-inprogress' => RequestStatus::InProgress->value,
							'importdump-label-complete' => RequestStatus::Complete->value,
							'importdump-label-declined' => RequestStatus::Declined->value,
						],
						'default' => $status->value,
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

			if ( $status === RequestStatus::Verifying ) {
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

	private function getStageDescriptor(): array {
		$stage = $this->requestManager->getStage();
		$statuses = [ RequestStatus::Failed, RequestStatus::InProgress, RequestStatus::Partial ];
		if ( !$stage || !in_array( $this->requestManager->getStatus(), $statuses, true ) ) {
			return [];
		}

		return [
			'stage' => [
				'label-message' => 'importdump-label-stage',
				'type' => 'text',
				'readonly' => true,
				'section' => 'details',
				'default' => $this->context->msg( $stage->getLabelKey() )->text(),
			],
		];
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
}
