<?php

namespace Miraheze\ImportDump;

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

	/** The place of this stage in the order, starting at 0. */
	public function getPosition(): int {
		return (int)array_search( $this, self::cases(), true );
	}

	/** Whether the pages are already in the wiki when this stage starts. */
	public function hasImported(): bool {
		return $this !== self::Download && $this !== self::Import;
	}

	/** The message key of the name of this stage. */
	public function getLabelKey(): string {
		return 'importdump-stage-' . $this->value;
	}
}
