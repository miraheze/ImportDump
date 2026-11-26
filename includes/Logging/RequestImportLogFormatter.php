<?php

declare( strict_types = 1 );

namespace Miraheze\ImportDump\Logging;

use MediaWiki\Logging\LogFormatter;
use MediaWiki\Message\Message;
use MediaWiki\SpecialPage\SpecialPage;
use Wikimedia\Message\ScalarParam;

class RequestImportLogFormatter extends LogFormatter {

	public const string TYPE_REQUEST_LINK = 'request-link';

	public const string TYPE_REQUEST_STATUS = 'request-status';

	/** @inheritDoc */
	protected function formatParameterValue( mixed $type, mixed $value ): mixed {
		return match ( $type ) {
			self::TYPE_REQUEST_LINK => $this->formatRequestLink( (int)$value ),
			self::TYPE_REQUEST_STATUS => $this->formatRequestStatus( (string)$value ),
			default => parent::formatParameterValue( $type, $value ),
		};
	}

	private function formatRequestLink( int $requestID ): ScalarParam|string {
		$title = SpecialPage::getTitleFor( 'RequestImportQueue', (string)$requestID );
		$link = $this->makePageLink( $title, [], "#$requestID" );

		return $this->plaintext ? $link : Message::rawParam( $link );
	}

	private function formatRequestStatus( string $status ): string {
		$message = $this->msg( "requestimport-label-$status" );
		if ( !$message->exists() ) {
			return $status;
		}

		return $this->context->getLanguage()->lc( $message->text() );
	}
}
