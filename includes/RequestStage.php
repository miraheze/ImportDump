<?php

namespace Miraheze\ImportDump;

interface RequestStage {

	public const string STAGE_IMPORT = 'import';

	public const string STAGE_SITESTATS = 'sitestats';

	public const string STAGE_TEXTINDEX = 'textindex';

	public const string STAGE_RECENTCHANGES = 'recentchanges';

	public const string STAGE_REFRESHLINKS = 'refreshlinks';

	public const string STAGE_COUNTS = 'counts';

	public const string STAGE_AFTERIMPORT = 'afterimport';

	/** In the order that the job runs them. */
	public const array STAGES = [
		self::STAGE_IMPORT,
		self::STAGE_SITESTATS,
		self::STAGE_TEXTINDEX,
		self::STAGE_RECENTCHANGES,
		self::STAGE_REFRESHLINKS,
		self::STAGE_COUNTS,
		self::STAGE_AFTERIMPORT,
	];
}
