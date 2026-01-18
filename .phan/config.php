<?php

$cfg = require __DIR__ . '/../vendor/mediawiki/mediawiki-phan-config/src/config.php';

$cfg['minimum_target_php_version'] = '8.2';

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

$cfg['plugins'][] = __DIR__ . '/../vendor/miraheze/phan-plugins/NoOptionalParamPlugin.php';

$cfg['enable_class_alias_support'] = false;

return $cfg;
