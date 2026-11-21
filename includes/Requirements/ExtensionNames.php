<?php

declare( strict_types = 1 );

namespace Miraheze\ImportDump\Requirements;

use MediaWiki\Config\ServiceOptions;
use MediaWiki\Language\MessageLocalizer;
use MediaWiki\MainConfigNames;
use MediaWiki\Registration\ExtensionProcessor;
use Wikimedia\ObjectCache\BagOStuff;
use function array_merge;
use function glob;
use const MW_VERSION;

/**
 * Finds the name that an extension gives itself. It reads the extension files, so that
 * extensions that are not loaded on this wiki have their names too.
 */
class ExtensionNames {

	public const array CONSTRUCTOR_OPTIONS = [
		MainConfigNames::ExtensionDirectory,
		MainConfigNames::StyleDirectory,
	];

	/** @var ?array<string, string> */
	private ?array $messageKeys = null;

	public function __construct(
		private readonly BagOStuff $cache,
		private readonly ServiceOptions $options,
	) {
		$options->assertRequiredOptions( self::CONSTRUCTOR_OPTIONS );
	}

	/** Gives the name of the extension in the language of the localizer, or $name if it has none. */
	public function getName( MessageLocalizer $localizer, string $name ): string {
		$key = $this->getMessageKeys()[$name] ?? null;
		if ( $key === null ) {
			return $name;
		}

		$message = $localizer->msg( $key );
		return $message->exists() ? $message->text() : $name;
	}

	/** @return array<string, string> The keys of the name messages, by the name of the extension. */
	private function getMessageKeys(): array {
		$this->messageKeys ??= $this->cache->getWithSetCallback(
			$this->cache->makeGlobalKey( 'ImportDump', 'extension-names', MW_VERSION ),
			BagOStuff::TTL_DAY,
			/** @return array<string, string> */
			function (): array {
				$paths = array_merge(
					glob( $this->options->get( MainConfigNames::ExtensionDirectory ) . '/*/extension*.json' ) ?: [],
					glob( $this->options->get( MainConfigNames::StyleDirectory ) . '/*/skin.json' ) ?: []
				);

				$processor = new ExtensionProcessor();
				foreach ( $paths as $path ) {
					$processor->extractInfoFromFile( $path );
				}

				$keys = [];
				foreach ( $processor->getExtractedInfo()['credits'] as $credit ) {
					if ( isset( $credit['name'] ) && isset( $credit['namemsg'] ) ) {
						$keys[(string)$credit['name']] = (string)$credit['namemsg'];
					}
				}

				return $keys;
			}
		);

		return $this->messageKeys;
	}
}
