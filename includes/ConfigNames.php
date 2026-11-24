<?php

declare( strict_types = 1 );

// phpcs:disable Generic.NamingConventions.UpperCaseConstantName.ClassConstantNotUpperCase
namespace Miraheze\ImportDump;

/**
 * A class containing constants representing the names of configuration variables,
 * to protect against typos.
 */
class ConfigNames {

	public const string ChunkedUploadThreshold = 'RequestImportChunkedUploadThreshold';

	public const string DumpCommand = 'RequestImportDumpCommand';

	public const string EnableAutomatedJob = 'RequestImportEnableAutomatedJob';

	public const string EnableChunkedUploads = 'RequestImportEnableChunkedUploads';

	public const string EnableHistoryDetection = 'RequestImportEnableHistoryDetection';

	public const string EnableImageImports = 'RequestImportEnableImageImports';

	public const string ExtensionRequirements = 'RequestImportExtensionRequirements';

	public const string ExternalBinaries = 'RequestImportExternalBinaries';

	public const string FileExpiryDays = 'RequestImportFileExpiryDays';

	public const string HelpUrl = 'RequestImportHelpUrl';

	public const string ImagesChunkSize = 'RequestImportImagesChunkSize';

	public const string ImagesCommand = 'RequestImportImagesCommand';

	public const string InterwikiMap = 'RequestImportInterwikiMap';

	public const string MaintenanceCommand = 'RequestImportMaintenanceCommand';

	public const string MaxArchiveEntries = 'RequestImportMaxArchiveEntries';

	public const string MaxExtractedSize = 'RequestImportMaxExtractedSize';

	public const string MaxSkippedImages = 'RequestImportMaxSkippedImages';

	public const string MaxUploadSize = 'RequestImportMaxUploadSize';

	public const string RequiredRights = 'RequestImportRequiredRights';

	public const string StalledImportHours = 'RequestImportStalledImportHours';

	public const string TempDirectory = 'RequestImportTempDirectory';

	public const string UsersNotifiedOnAllRequests = 'RequestImportUsersNotifiedOnAllRequests';

	public const string UsersNotifiedOnFailedImports = 'RequestImportUsersNotifiedOnFailedImports';
}
