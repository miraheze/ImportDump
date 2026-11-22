<?php

declare( strict_types = 1 );

namespace Miraheze\ImportDump\Request;

enum RequestType: string {

	case Images = 'images';
	case Xml = 'xml';
}
