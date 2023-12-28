<?php

namespace Miraheze\ImportDump\ImportDump;

use LogFormatter;
use MediaWiki\MediaWikiServices;
use Message;
use SpecialPage;

class ImportDumpLogFormatter extends LogFormatter {

	/**
	 * @return array
	 */
	protected function getMessageParameters() {
		$params = parent::getMessageParameters();
		$subtype = $this->entry->getSubtype();

		$linkRenderer = MediaWikiServices::getInstance()->getLinkRenderer();

		if ( $subtype === 'interwiki' ) {
			$params[5] = str_replace( '#', '', $params[5] );
			if ( !$this->plaintext ) {
				$requestQueueLink = SpecialPage::getTitleValueFor( 'RequestImportDumpQueue', (string)$params[5] );
				// @phan-suppress-next-line SecurityCheck-XSS, SecurityCheck-DoubleEscaped
				$requestLink = $linkRenderer->makeLink( $requestQueueLink, '#' . $params[5] );
				$params[5] = Message::rawParam( $requestLink );
			} else {
				$params[5] = Message::rawParam(
					'#' . $params[5]
				);
			}
		} elseif ( $subtype === 'request' ) {
			$params[4] = str_replace( '#', '', $params[4] );

			if ( !$this->plaintext ) {
				$requestQueueLink = SpecialPage::getTitleValueFor( 'RequestImportDumpQueue', $params[4] );
				// @phan-suppress-next-line SecurityCheck-XSS, SecurityCheck-DoubleEscaped
				$requestLink = $linkRenderer->makeLink( $requestQueueLink, '#' . $params[4] );
				$params[4] = Message::rawParam( $requestLink );
			} else {
				$params[4] = Message::rawParam(
					'#' . $params[4]
				);
			}
		} else {
			$params[3] = str_replace( '#', '', $params[3] );

			if ( !$this->plaintext ) {
				$requestQueueLink = SpecialPage::getTitleValueFor( 'RequestImportDumpQueue', (string)$params[3] );
				// @phan-suppress-next-line SecurityCheck-XSS, SecurityCheck-DoubleEscaped
				$requestLink = $linkRenderer->makeLink( $requestQueueLink, '#' . $params[3] );
				$params[3] = Message::rawParam( $requestLink );
			} else {
				$params[3] = Message::rawParam(
					'#' . $params[3]
				);
			}
		}

		return $params;
	}
}
