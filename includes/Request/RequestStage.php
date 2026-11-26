<?php

declare( strict_types = 1 );

namespace Miraheze\ImportDump\Request;

use function array_column;
use function array_filter;
use function array_search;
use function array_values;

/** The stages of an import, in the order that the job runs them. */
enum RequestStage: string {

	case Download = 'download';
	case Import = 'import';
	case SiteStats = 'sitestats';
	case TextIndex = 'textindex';
	case RecentChanges = 'recentchanges';
	case RefreshLinks = 'refreshlinks';
	case Counts = 'counts';
	case AfterImport = 'afterimport';
	case Images = 'images';

	/**
	 * The stages that a request of this type goes through, in order.
	 *
	 * @return list<self>
	 */
	public static function forType( RequestType $type ): array {
		if ( $type === RequestType::Images ) {
			return [ self::Images ];
		}

		return array_values( array_filter(
			self::cases(),
			static fn ( self $stage ): bool => $stage !== self::Images
		) );
	}

	/** The place of this stage in the order, starting at 0. */
	public function getPosition(): int {
		return (int)array_search( $this->value, array_column( self::cases(), 'value' ), true );
	}

	/** Whether the pages are already in the wiki when this stage starts. */
	public function hasImported(): bool {
		return $this !== self::Download && $this !== self::Import && $this !== self::Images;
	}

	/** The message key of the name of this stage. */
	public function getLabelKey(): string {
		return 'requestimport-stage-' . $this->value;
	}
}
