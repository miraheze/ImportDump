<?php

declare( strict_types = 1 );

namespace Miraheze\ImportDump\Viewer;

use Closure;
use MediaWiki\HTMLForm\OOUIHTMLForm;
use MediaWiki\Logger\LoggerFactory;
use OOUI\FieldsetLayout;
use OOUI\HtmlSnippet;
use OOUI\IndexLayout;
use OOUI\PanelLayout;
use OOUI\TabPanelLayout;
use OOUI\Widget;
use function is_array;

class OOUIHTMLFormTabs extends OOUIHTMLForm {

	/** @var bool Override default value from HTMLForm */
	protected $mSubSectionBeforeFields = false;

	private ?Closure $noticesRenderer = null;

	/**
	 * The notices are rendered when the body is, which is after the form is submitted.
	 *
	 * @param Closure(): string $renderer
	 */
	public function setNoticesRenderer( Closure $renderer ): void {
		$this->noticesRenderer = $renderer;
	}

	/**
	 * @param string $legend
	 * @param string $section
	 * @param array $attributes
	 * @param bool $isRoot
	 * @return PanelLayout
	 */
	protected function wrapFieldSetSection( $legend, $section, $attributes, $isRoot ) {
		$layout = parent::wrapFieldSetSection( $legend, $section, $attributes, $isRoot );

		$layout->addClasses( [ 'ext-importdump-fieldset-wrapper' ] );
		$layout->removeClasses( [ 'oo-ui-panelLayout-framed' ] );

		return $layout;
	}

	/**
	 * @return string
	 */
	public function getBody() {
		$tabPanels = [];
		foreach ( $this->mFieldTree as $key => $val ) {
			if ( !is_array( $val ) ) {
				LoggerFactory::getInstance( 'ImportDump' )->debug(
					'Encountered a field not attached to a section: {key}',
					[ 'key' => $key ]
				);
				continue;
			}

			$label = $this->getLegend( $key );

			$content =
				$this->getHeaderHtml( $key ) .
				$this->displaySection(
					$val,
					'',
					"mw-section-$key-"
				) .
				$this->getFooterHtml( $key );

			$tabPanels[] = new TabPanelLayout( "mw-section-$key", [
				'classes' => [ 'mw-htmlform-autoinfuse-lazy' ],
				'label' => $label,
				'content' => new FieldsetLayout( [
					'classes' => [ 'ext-importdump-section-fieldset' ],
					'id' => "mw-section-$key",
					'label' => $label,
					'items' => [
						new Widget( [
							'content' => new HtmlSnippet( $content ),
						] ),
					],
				] ),
				'expanded' => false,
				'framed' => true,
			] );
		}

		$indexLayout = new IndexLayout( [
			'infusable' => true,
			'expanded' => false,
			'autoFocus' => false,
			'classes' => [ 'ext-importdump-tabs' ],
		] );

		$indexLayout->addTabPanels( $tabPanels );

		$header = $this->formatFormHeader();

		$form = new PanelLayout( [
			'framed' => true,
			'expanded' => false,
			'classes' => [ 'ext-importdump-tabs-wrapper' ],
			'content' => $indexLayout,
		] );

		$notices = $this->noticesRenderer ? ( $this->noticesRenderer )() : '';
		return $notices . $header . $form;
	}
}
