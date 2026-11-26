<?php

declare( strict_types = 1 );

namespace Miraheze\ImportDump\Viewer;

use MediaWiki\Config\Config;
use MediaWiki\Context\IContextSource;
use MediaWiki\Exception\UserNotLoggedIn;
use MediaWiki\HTMLForm\HTMLForm;
use MediaWiki\Message\Message;
use MediaWiki\User\User;
use Miraheze\ImportDump\ConfigNames;
use Miraheze\ImportDump\Notices\ActionOutcomeNotices;
use Miraheze\ImportDump\Notices\NoticeSeverity;
use Miraheze\ImportDump\Request\RequestManager;
use Miraheze\ImportDump\Request\RequestStatus;
use Miraheze\ImportDump\Request\RequestType;
use function array_column;
use function array_unique;
use function implode;
use function in_array;
use function mb_strtolower;

/** Carries out what was submitted on the form of a request, and reports how it went. */
class RequestSubmitHandler {

	public function __construct(
		private readonly Config $config,
		private readonly IContextSource $context,
		private readonly RequestManager $requestManager,
		private readonly ActionOutcomeNotices $outcomes,
	) {
	}

	/** @throws UserNotLoggedIn */
	public function submit(
		array $formData,
		HTMLForm $form
	): void {
		$user = $form->getUser();
		if ( !$user->isRegistered() ) {
			throw new UserNotLoggedIn( 'exception-nologin-text', 'exception-nologin' );
		}

		$session = $form->getRequest()->getSession();

		if ( isset( $formData['submit-comment'] ) ) {
			if ( $session->get( 'previous_posted_comment' ) !== $formData['comment'] ) {
				$session->set( 'previous_posted_comment', $formData['comment'] );
				$this->requestManager->addComment( $formData['comment'], $user );
				$this->addSuccess( $this->context->msg( 'requestimport-comment-success' ) );
				return;
			}

			$this->addError( $this->context->msg( 'requestimport-duplicate-comment' ) );
			return;
		}

		$session->remove( 'previous_posted_comment' );

		if ( isset( $formData['submit-edit'] ) ) {
			$this->requestManager->startAtomic( __METHOD__ );

			$changes = [];
			if ( $this->requestManager->getReason() !== $formData['edit-reason'] ) {
				$changes[] = $this->context->msg( 'requestimport-request-edited-reason' )->plaintextParams(
					$this->requestManager->getReason(),
					$formData['edit-reason']
				)->escaped();

				$this->requestManager->setReason( $formData['edit-reason'] );
			}

			if ( $this->requestManager->getSource() !== $formData['edit-source'] ) {
				$changes[] = $this->context->msg( 'requestimport-request-edited-source' )->plaintextParams(
					$this->requestManager->getSource(),
					$formData['edit-source']
				)->escaped();

				$this->requestManager->setSource( $formData['edit-source'] );
			}

			if ( $this->requestManager->getTarget() !== $formData['edit-target'] ) {
				$changes[] = $this->context->msg(
					'requestimport-request-edited-target',
					$this->requestManager->getTarget(),
					$formData['edit-target']
				)->escaped();

				$this->requestManager->setTarget( $formData['edit-target'] );
			}

			$status = $this->requestManager->getStatus();
			if (
				isset( $formData['edit-skip-dupes'] ) &&
				$this->requestManager->getType() === RequestType::Images &&
				$status !== RequestStatus::Complete &&
				!$status->isRunning() &&
				$this->requestManager->shouldSkipDupes() !== (bool)$formData['edit-skip-dupes']
			) {
				$changes[] = $this->context->msg( 'requestimport-request-edited-skip-dupes' )->params(
					$this->context->msg( $this->requestManager->shouldSkipDupes() ? 'htmlform-yes' : 'htmlform-no' )
						->inContentLanguage()->text(),
					$this->context->msg( $formData['edit-skip-dupes'] ? 'htmlform-yes' : 'htmlform-no' )
						->inContentLanguage()->text()
				)->escaped();

				$this->requestManager->setSkipDupes( (bool)$formData['edit-skip-dupes'] );
			}

			if ( !$changes ) {
				$this->requestManager->endAtomic( __METHOD__ );

				$this->addError( $this->context->msg( 'requestimport-no-changes' ) );

				return;
			}

			if ( $this->requestManager->getStatus() === RequestStatus::Declined ) {
				$this->requestManager->setStatus( RequestStatus::Pending );

				$comment = $this->context->msg( 'requestimport-request-reopened', $user->getName() )->rawParams(
					implode( "\n\n", $changes )
				)->inContentLanguage()->escaped();

				$this->requestManager->logStatusUpdate( $comment, RequestStatus::Pending, $user );

				$this->requestManager->addComment( $comment, User::newSystemUser( RequestManager::EXTENSION_USER ) );

				$this->requestManager->sendNotification(
					$comment, 'requestimport-request-status-update', $user
				);
			} else {
				$comment = $this->context->msg( 'requestimport-request-edited', $user->getName() )->rawParams(
					implode( "\n\n", $changes )
				)->inContentLanguage()->escaped();

				$this->requestManager->addComment( $comment, User::newSystemUser( RequestManager::EXTENSION_USER ) );
			}

			$this->requestManager->endAtomic( __METHOD__ );

			$this->addSuccess( $this->context->msg( 'requestimport-edit-success' ) );

			return;
		}

		if ( isset( $formData['submit-interwiki'] ) ) {
			if ( $this->requestManager->insertInterwikiPrefix(
				$formData['handle-interwiki-prefix'],
				$formData['handle-interwiki-url'],
				$user
			) ) {
				$this->addSuccess( $this->context->msg(
					'requestimport-interwiki-success',
					$this->requestManager->getTarget()
				) );

				return;
			}

			$this->addError( $this->context->msg(
				'requestimport-interwiki-failed',
				$this->requestManager->getTarget()
			) );

			return;
		}

		if ( isset( $formData['submit-verify'] ) ) {
			if ( $this->requestManager->getStatus() !== RequestStatus::Verifying ) {
				$this->addError( $this->context->msg( 'requestimport-verification-not-needed' ) );

				return;
			}

			$this->requestManager->retryVerification();
			$this->addSuccess( $this->context->msg( 'requestimport-verification-retried' ) );
			return;
		}

		if ( isset( $formData['submit-recheck'] ) ) {
			if (
				$this->requestManager->getType() !== RequestType::Images ||
				$this->requestManager->getStatus() !== RequestStatus::Pending ||
				$this->requestManager->getHeldImageCount() === 0
			) {
				$this->addError( $this->context->msg( 'requestimport-recheck-not-needed' ) );
				return;
			}

			$this->requestManager->recheckHeldImages();
			$this->addSuccess( $this->context->msg( 'requestimport-recheck-queued' ) );
			return;
		}

		if ( isset( $formData['submit-handle'] ) ) {
			$newStatus = RequestStatus::tryFrom( $formData['handle-status'] ?? '' );

			$this->requestManager->startAtomic( __METHOD__ );
			if (
				$this->requestManager->getStatus() === RequestStatus::Verifying &&
				$newStatus !== null &&
				!in_array( $newStatus, [ RequestStatus::Declined, RequestStatus::Verifying ], true )
			) {
				$this->requestManager->endAtomic( __METHOD__ );
				$this->addError( $this->context->msg( 'requestimport-verifying-conflict' ) );
				return;
			}

			$changes = [];

			if ( $this->requestManager->isLocked() !== (bool)$formData['handle-lock'] ) {
				$changes[] = $this->requestManager->isLocked() ?
					'unlocked' : 'locked';

				$this->requestManager->setLocked( (bool)$formData['handle-lock'] );
			}

			if (
				isset( $formData['handle-private'] ) &&
				$this->requestManager->isPrivate() !== (bool)$formData['handle-private']
			) {
				$changes[] = $this->requestManager->isPrivate() ?
					'public' : 'private';

				$this->requestManager->setPrivate( (bool)$formData['handle-private'] );
			}

			if (
				isset( $formData['handle-overwrite'] ) &&
				$this->requestManager->getType() === RequestType::Images &&
				$this->requestManager->getStatus() !== RequestStatus::Complete &&
				!$this->requestManager->getStatus()->isRunning() &&
				$this->requestManager->shouldOverwrite() !== (bool)$formData['handle-overwrite']
			) {
				$changes[] = $this->requestManager->shouldOverwrite() ?
					'skip' : 'overwrite';

				$this->requestManager->setOverwrite( (bool)$formData['handle-overwrite'] );
			}

			if ( $newStatus === null || $this->requestManager->getStatus() === $newStatus ) {
				$this->requestManager->endAtomic( __METHOD__ );

				if ( !$changes ) {
					$this->addError( $this->context->msg( 'requestimport-no-changes' ) );
					return;
				}

				if ( in_array( 'private', $changes, true ) ) {
					$this->addSuccess( $this->context->msg( 'requestimport-success-private' ) );
				}

				if ( in_array( 'public', $changes, true ) ) {
					$this->addSuccess( $this->context->msg( 'requestimport-success-public' ) );
				}

				if ( in_array( 'locked', $changes, true ) ) {
					$this->addSuccess( $this->context->msg( 'requestimport-success-locked' ) );
				}

				if ( in_array( 'unlocked', $changes, true ) ) {
					$this->addSuccess( $this->context->msg( 'requestimport-success-unlocked' ) );
				}

				if ( in_array( 'overwrite', $changes, true ) ) {
					$this->addSuccess( $this->context->msg( 'requestimport-success-overwrite' ) );
				}

				if ( in_array( 'skip', $changes, true ) ) {
					$this->addSuccess( $this->context->msg( 'requestimport-success-skip' ) );
				}

				return;
			}

			if ( $this->config->get( ConfigNames::EnableAutomatedJob ) ) {
				$formData['handle-comment'] = '';
			}

			$this->handleStatusUpdate( $formData, $newStatus, $user );
			$this->requestManager->endAtomic( __METHOD__ );
			return;
		}

		$status = $this->requestManager->getStatus();
		if ( $status === RequestStatus::Complete || $status->isRunning() ) {
			$this->addError( $this->context->msg( 'requestimport-status-conflict' ) );

			return;
		}

		if ( isset( $formData['submit-decline'] ) ) {
			$this->requestManager->startAtomic( __METHOD__ );
			$this->handleStatusUpdate( $formData, RequestStatus::Declined, $user );
			$this->requestManager->endAtomic( __METHOD__ );
			return;
		}

		if ( isset( $formData['submit-start'] ) ) {
			if ( $status === RequestStatus::Verifying ) {
				$this->addError( $this->context->msg( 'requestimport-verifying-conflict' ) );
				return;
			}

			if ( $status === RequestStatus::Complete ) {
				// Don't rerun a job that is already completed.
				return;
			}

			$blocking = $this->requestManager->getBlockingRequirementProblems();
			if ( $blocking ) {
				$this->addError(
					$this->context->msg( 'requestimport-requirement-blocked' )
						->params( $this->context->getLanguage()->listToText( array_unique(
							array_column( $blocking, 'name' )
						) ) )
				);

				return;
			}

			$this->requestManager->addHandler( $user );
			$this->requestManager->setStatus( RequestStatus::Starting );
			$this->requestManager->executeJob( $user->getName() );
			$this->addSuccess( $this->context->msg( 'requestimport-import-started' ) );
		}
	}

	private function addSuccess( Message $message ): void {
		$this->outcomes->add( NoticeSeverity::Success, $message->escaped() );
	}

	private function addError( Message $message ): void {
		$this->outcomes->add( NoticeSeverity::Error, $message->escaped() );
	}

	private function handleStatusUpdate( array $formData, RequestStatus $status, User $user ): void {
		$this->requestManager->addHandler( $user );
		$this->requestManager->setStatus( $status );
		$statusMessage = $this->context->msg( $status->getLabelKey() )
			->inContentLanguage()
			->text();

		$comment = $this->context->msg(
			'requestimport-status-updated-by',
			mb_strtolower( $statusMessage ),
			$user->getName()
		)->inContentLanguage()->escaped();

		if ( $formData['handle-comment'] ) {
			$comment .= "\n" . $this->context->msg( 'requestimport-status-comment-given' )
				->inContentLanguage()
				->escaped();

			$comment .= ' ' . $formData['handle-comment'];
		}

		$this->requestManager->addComment( $comment, User::newSystemUser( RequestManager::STATUS_UPDATE_USER ) );
		$this->requestManager->logStatusUpdate(
			$formData['handle-comment'], $status, $user
		);

		$this->requestManager->sendNotification(
			$comment, 'requestimport-request-status-update', $user
		);

		$this->addSuccess( $this->context->msg( 'requestimport-status-updated-success' ) );
	}
}
