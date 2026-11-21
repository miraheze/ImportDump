<?php

declare( strict_types = 1 );

$cfg = require __DIR__ . '/../vendor/mediawiki/mediawiki-phan-config/src/config.php';

$cfg['directory_list'] = array_merge(
	$cfg['directory_list'], [
		'../../extensions/Echo',
		'../../extensions/ManageWiki',
	]
);

$cfg['exclude_analysis_directory_list'] = array_merge(
	$cfg['exclude_analysis_directory_list'], [
		'../../extensions/Echo',
		'../../extensions/ManageWiki',
	]
);

$cfg['suppress_issue_types'] = [
	'PhanAccessMethodInternal',
	'SecurityCheck-LikelyFalsePositive',
];

$cfg['plugins'] = array_merge( $cfg['plugins'], [
	'AddNeverReturnTypePlugin',
	'AlwaysReturnPlugin',
	'DeprecateAliasPlugin',
	'DollarDollarPlugin',
	'DuplicateConstantPlugin',
	'EmptyMethodAndFunctionPlugin',
	'EmptyStatementListPlugin',
	'FFIAnalysisPlugin',
	'InlineHTMLPlugin',
	'InvalidVariableIssetPlugin',
	'InvokePHPNativeSyntaxCheckPlugin',
	'LoopVariableReusePlugin',
	'MoreSpecificElementTypePlugin',
	'NotFullyQualifiedUsagePlugin',
	'PHPDocRedundantPlugin',
	'PHPUnitAssertionPlugin',
	'PHPUnitNotDeadCodePlugin',
	'PreferNamespaceUsePlugin',
	'PrintfCheckerPlugin',
	'RedundantAssignmentPlugin',
	'SimplifyExpressionPlugin',
	'SleepCheckerPlugin',
	'StrictComparisonPlugin',
	'StrictLiteralComparisonPlugin',
	'SuspiciousParamOrderPlugin',
	'UnknownClassElementAccessPlugin',
	// 'UnknownElementTypePlugin',
	'UnreachableCodePlugin',
	'UnsafeCodePlugin',
	'UseReturnValuePlugin',
] );

$cfg['plugins'][] = __DIR__ . '/../vendor/miraheze/phan-plugins/NoOptionalParamPlugin.php';

$cfg['enable_class_alias_support'] = false;

return $cfg;
