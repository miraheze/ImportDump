<?php

// phpcs:disable Generic.NamingConventions.UpperCaseConstantName.ClassConstantNotUpperCase
namespace Miraheze\ImportDump;

/**
 * A class containing constants representing the names of configuration variables,
 * to protect against typos.
 */
class ConfigNames {

	public const EnableAutomatedJob = 'ImportDumpEnableAutomatedJob';

	public const EnableChunkedUploads = 'ImportDumpEnableChunkedUploads';

	public const FileExpiryDays = 'ImportDumpFileExpiryDays';

	public const HelpUrl = 'ImportDumpHelpUrl';

	public const InterwikiMap = 'ImportDumpInterwikiMap';

	public const MaxUploadSize = 'ImportDumpMaxUploadSize';

	public const ScriptCommand = 'ImportDumpScriptCommand';

	public const UsersNotifiedOnAllRequests = 'ImportDumpUsersNotifiedOnAllRequests';

	public const UsersNotifiedOnFailedImports = 'ImportDumpUsersNotifiedOnFailedImports';
}
