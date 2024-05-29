<?php

// phpcs:disable Generic.NamingConventions.UpperCaseConstantName.ClassConstantNotUpperCase
namespace Miraheze\ImportDump;

/**
 * A class containing constants representing the names of configuration variables,
 * to protect against typos.
 */
class ConfigNames {

	public const EnableAutomatedJob = 'ImportDumpEnableAutomatedJob';

	public const HelpUrl = 'ImportDumpHelpUrl';

	public const InterwikiMap = 'ImportDumpInterwikiMap';

	public const ScriptCommand = 'ImportDumpScriptCommand';

	public const UsersNotifiedOnAllRequests = 'ImportDumpUsersNotifiedOnAllRequests';

	public const UsersNotifiedOnFailedImports = 'ImportDumpUsersNotifiedOnFailedImports';
}
