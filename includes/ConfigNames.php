<?php

// phpcs:disable Generic.NamingConventions.UpperCaseConstantName.ClassConstantNotUpperCase
namespace Miraheze\ImportDump;

/**
 * A class containing constants representing the names of configuration variables,
 * to protect against typos.
 */
class ConfigNames {

	public const string EnableAutomatedJob = 'ImportDumpEnableAutomatedJob';

	public const string EnableChunkedUploads = 'ImportDumpEnableChunkedUploads';

	public const string EnableHistoryDetection = 'ImportDumpEnableHistoryDetection';

	public const string FileExpiryDays = 'ImportDumpFileExpiryDays';

	public const string HelpUrl = 'ImportDumpHelpUrl';

	public const string InterwikiMap = 'ImportDumpInterwikiMap';

	public const string MaintenanceCommand = 'ImportDumpMaintenanceCommand';

	public const string MaxArchiveEntries = 'ImportDumpMaxArchiveEntries';

	public const string MaxExtractedSize = 'ImportDumpMaxExtractedSize';

	public const string MaxUploadSize = 'ImportDumpMaxUploadSize';

	public const string ScriptCommand = 'ImportDumpScriptCommand';

	public const string StalledImportHours = 'ImportDumpStalledImportHours';

	public const string UsersNotifiedOnAllRequests = 'ImportDumpUsersNotifiedOnAllRequests';

	public const string UsersNotifiedOnFailedImports = 'ImportDumpUsersNotifiedOnFailedImports';
}
