<?php

namespace Miraheze\ImportDump\Tests\Logging;

use MediaWiki\Logging\DatabaseLogEntry;
use MediaWiki\Logging\ManualLogEntry;
use MediaWiki\Title\TitleValue;
use MediaWikiIntegrationTestCase;
use Miraheze\ImportDump\Logging\ImportDumpLogFormatter;

/**
 * @group ImportDump
 * @group Database
 * @group medium
 * @covers \Miraheze\ImportDump\Logging\ImportDumpLogFormatter
 */
class ImportDumpLogFormatterTest extends MediaWikiIntegrationTestCase {

	protected function setUp(): void {
		parent::setUp();

		$this->setUserLang( 'en' );
		$this->setContentLang( 'en' );
	}

	private function newFormatter( string $type, string $action, array $params ): ImportDumpLogFormatter {
		$entry = new ManualLogEntry( $type, $action );
		$entry->setPerformer( $this->getTestUser()->getUser() );
		$entry->setTarget( new TitleValue( NS_SPECIAL, 'RequestImportQueue/7' ) );
		$entry->setParameters( $params );

		$logID = $entry->insert( $this->getDb() );
		$row = $this->getDb()->newSelectQueryBuilder()
			->queryInfo( DatabaseLogEntry::getSelectQueryData() )
			->where( [ 'log_id' => $logID ] )
			->caller( __METHOD__ )
			->fetchRow();

		$formatter = $this->getServiceContainer()->getLogFormatterFactory()
			->newFromEntry( DatabaseLogEntry::newFromRow( $row ) );

		$this->assertInstanceOf( ImportDumpLogFormatter::class, $formatter );
		return $formatter;
	}

	public function testStatusUpdateLinksRequestAndTranslatesStatus(): void {
		$formatter = $this->newFormatter( 'importdump', 'statusupdate', [
			'4:request-link:requestID' => 7,
			'5:request-status:requestStatus' => 'inprogress',
		] );

		$html = $formatter->getActionText();
		$this->assertStringContainsString( 'RequestImportQueue/7', $html );
		$this->assertStringContainsString( '>#7</a>', $html );
		$this->assertStringContainsString( "to 'in progress'", $html );
	}

	public function testPlainTextHasNoHtml(): void {
		$formatter = $this->newFormatter( 'importdump', 'statusupdate', [
			'4:request-link:requestID' => 7,
			'5:request-status:requestStatus' => 'complete',
		] );

		$text = $formatter->getPlainActionText();
		$this->assertStringContainsString( '[[Special:RequestImportQueue/7]]', $text );
		$this->assertStringContainsString( "to 'complete'", $text );
		$this->assertStringNotContainsString( '<a ', $text );
	}

	public function testUnknownStatusIsShownAsStored(): void {
		$formatter = $this->newFormatter( 'importdumpprivate', 'statusupdate', [
			'4:request-link:requestID' => 7,
			'5:request-status:requestStatus' => 'something else',
		] );

		$this->assertStringContainsString( "to 'something else'", $formatter->getPlainActionText() );
	}

	public function testRequestEntryKeepsTarget(): void {
		$formatter = $this->newFormatter( 'importdump', 'request', [
			'4::requestTarget' => 'testwiki',
			'5:request-link:requestID' => 7,
		] );

		$html = $formatter->getActionText();
		$this->assertStringContainsString( 'testwiki', $html );
		$this->assertStringContainsString( '>#7</a>', $html );
	}

	public function testStoredHtmlIsNotRendered(): void {
		$formatter = $this->newFormatter( 'importdump', 'request', [
			'4::requestTarget' => '<script>alert(1)</script>',
			'5:request-link:requestID' => 7,
		] );

		$this->assertStringNotContainsString( '<script>', $formatter->getActionText() );
	}
}
