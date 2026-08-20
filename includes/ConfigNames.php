<?php

// phpcs:disable Generic.NamingConventions.UpperCaseConstantName.ClassConstantNotUpperCase
namespace Miraheze\ImportDump;

/**
 * A class containing constants representing the names of configuration variables,
 * to protect against typos.
 */
class ConfigNames {

	public const EnableAutomatedJob = 'RequestImportEnableAutomatedJob';

	public const HelpUrl = 'RequestImportHelpUrl';

	public const InterwikiMap = 'RequestImportInterwikiMap';

	public const ScriptCommand = 'RequestImportScriptCommand';

	public const UsersNotifiedOnAllRequests = 'RequestImportUsersNotifiedOnAllRequests';

	public const UsersNotifiedOnFailedImports = 'RequestImportUsersNotifiedOnFailedImports';
}
