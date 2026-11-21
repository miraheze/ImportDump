<?php

namespace Miraheze\ImportDump\Notices;

/** What the person who views the request is to it. */
enum NoticeRole {

	case Handler;
	case Requester;
	case Viewer;
}
