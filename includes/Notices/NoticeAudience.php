<?php

declare( strict_types = 1 );

namespace Miraheze\ImportDump\Notices;

/** Who a provider shows its notices to. */
enum NoticeAudience {

	case Handlers;

	/** Everyone who can see the request. Only for notices that show nothing from the target wiki. */
	case Everyone;

	/** The handlers and the requester of the request. */
	case Parties;
}
