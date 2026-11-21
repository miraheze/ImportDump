<?php

declare( strict_types = 1 );

namespace Miraheze\ImportDump\Notices;

use function array_column;
use function array_search;

/** How serious a notice is. The values are the types of an OOUI message widget. */
enum NoticeSeverity: string {

	case Error = 'error';
	case Warning = 'warning';
	case Notice = 'notice';
	case Success = 'success';

	/** Where the severity sorts, from the most important at 0. */
	public function getRank(): int {
		return (int)array_search( $this->value, array_column( self::cases(), 'value' ), true );
	}
}
