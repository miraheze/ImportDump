<?php

namespace Miraheze\ImportDump;

interface RequestHistory {

	public const string HISTORY_FULL = 'full';

	public const string HISTORY_PARTIAL = 'partial';

	public const string HISTORY_UNKNOWN = 'unknown';
}
