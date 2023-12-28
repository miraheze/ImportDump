<?php

namespace Miraheze\ImportDump\ImportDump;

use LogFormatter;
use MediaWiki\MediaWikiServices;
use Message;
use SpecialPage;
use Title;

class ImportDumpLogFormatter extends LogFormatter {

	/**
	 * @return array
	 */
	protected function getMessageParameters() {
		$params = parent::getMessageParameters();
		$subtype = $this->entry->getSubtype();

		$linkRenderer = MediaWikiServices::getInstance()->getLinkRenderer();

		if ( $subtype === 'interwiki' ) {
			$params[6] = str_replace( '#', '', $params[6] );
			if ( !$this->plaintext ) {
				// @phan-suppress-next-line SecurityCheck-DoubleEscaped
				$requestQueueLink = SpecialPage::getTitleValueFor( 'RequestImportDumpQueue', (string)$params[6] );
				$requestLink = $linkRenderer->makeLink( $requestQueueLink, "#{$params[6]}" );
				$params[6] = Message::rawParam( $requestLink );
			} else {
				$params[6] = Message::rawParam(
					'#' . $params[6]
				);
			}
		} else if ( $subtype === 'request' ) {
			$params[5] = str_replace( '#', '', $params[5] );

			if ( !$this->plaintext ) {
				// @phan-suppress-next-line SecurityCheck-DoubleEscaped
				$requestQueueLink = SpecialPage::getTitleValueFor( 'RequestImportDumpQueue', $params[5] );
				$requestLink = $linkRenderer->makeLink( $requestQueueLink, "#{$params[5]}" );
				$params[5] = Message::rawParam( $requestLink );
			} else {
				$params[5] = Message::rawParam(
					'#' . $params[5]
				);
			}
		} else {
			$params[3] = str_replace( '#', '', $params[3] );
			var_dump($params[3]);

			if ( !$this->plaintext ) {
				$requestQueueLink = SpecialPage::getTitleValueFor( 'RequestImportDumpQueue', (string)$params[3] );
				$requestLink = $linkRenderer->makeLink( $requestQueueLink, "#{$params[3]}" );
				// @phan-suppress-next-line SecurityCheck-DoubleEscaped
				$params[3] = Message::rawParam( $requestLink);
			} else {
				$params[3] = Message::rawParam(
					'#' . $params[3]
				);
			}
		}

		return $params;
	}
}
