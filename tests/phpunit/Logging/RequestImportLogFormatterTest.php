<?php

declare( strict_types = 1 );

namespace Miraheze\ImportDump\Tests\Logging;

use MediaWiki\Logging\DatabaseLogEntry;
use MediaWiki\Logging\ManualLogEntry;
use MediaWiki\MainConfigNames;
use MediaWiki\Title\TitleValue;
use MediaWikiIntegrationTestCase;
use Miraheze\ImportDump\Logging\RequestImportLogFormatter;
use function html_entity_decode;
use const ENT_QUOTES;
use const NS_SPECIAL;

/**
 * @group ImportDump
 * @group Database
 * @group medium
 * @covers \Miraheze\ImportDump\Logging\RequestImportLogFormatter
 */
class RequestImportLogFormatterTest extends MediaWikiIntegrationTestCase {

	protected function setUp(): void {
		parent::setUp();

		$this->setUserLang( 'en' );
		$this->overrideConfigValue( MainConfigNames::LanguageCode, 'en' );
	}

	private function newFormatter( string $type, string $action, array $params ): RequestImportLogFormatter {
		$entry = new ManualLogEntry( $type, $action );
		$entry->setPerformer( $this->getTestUser()->getUser() );
		$entry->setTarget( new TitleValue( NS_SPECIAL, 'RequestImportQueue/7' ) );
		$entry->setParameters( $params );

		$logID = $entry->insert( $this->getDb() );
		$logEntry = DatabaseLogEntry::newFromId( $logID, $this->getDb() );
		$this->assertNotNull( $logEntry );

		$formatter = $this->getServiceContainer()->getLogFormatterFactory()
			->newFromEntry( $logEntry );

		$this->assertInstanceOf( RequestImportLogFormatter::class, $formatter );
		return $formatter;
	}

	public function testStatusUpdateLinksRequestAndTranslatesStatus(): void {
		$formatter = $this->newFormatter( 'requestimport', 'statusupdate', [
			'4:request-link:requestID' => 7,
			'5:request-status:requestStatus' => 'inprogress',
		] );

		$html = $formatter->getActionText();
		$this->assertStringContainsString( 'RequestImportQueue/7', $html );
		$this->assertStringContainsString( '>#7</a>', $html );
		$this->assertStringContainsString(
			"to 'in progress'",
			html_entity_decode( $html, ENT_QUOTES )
		);
	}

	public function testPlainTextHasNoHtml(): void {
		$formatter = $this->newFormatter( 'requestimport', 'statusupdate', [
			'4:request-link:requestID' => 7,
			'5:request-status:requestStatus' => 'complete',
		] );

		$text = $formatter->getPlainActionText();
		$this->assertStringContainsString( '[[Special:RequestImportQueue/7]]', $text );
		$this->assertStringContainsString( "to 'complete'", $text );
		$this->assertStringNotContainsString( '<a ', $text );
	}

	public function testUnknownStatusIsShownAsStored(): void {
		$formatter = $this->newFormatter( 'requestimport', 'statusupdate', [
			'4:request-link:requestID' => 7,
			'5:request-status:requestStatus' => 'something else',
		] );

		$this->assertStringContainsString( "to 'something else'", $formatter->getPlainActionText() );
	}

	public function testRequestEntryKeepsTarget(): void {
		$formatter = $this->newFormatter( 'requestimport', 'request', [
			'4::requestTarget' => 'testwiki',
			'5:request-link:requestID' => 7,
		] );

		$html = $formatter->getActionText();
		$this->assertStringContainsString( 'testwiki', $html );
		$this->assertStringContainsString( '>#7</a>', $html );
	}

	public function testStoredHtmlIsNotRendered(): void {
		$formatter = $this->newFormatter( 'requestimport', 'request', [
			'4::requestTarget' => '<script>alert(1)</script>',
			'5:request-link:requestID' => 7,
		] );

		$this->assertStringNotContainsString( '<script>', $formatter->getActionText() );
	}
}
