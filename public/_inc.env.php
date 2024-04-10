<?php
if ($_SERVER["SCRIPT_FILENAME"] == __FILE__) die();
	
/* Changes all directories below to match your installation */

$privateDir = __DIR__ . '/../private';
$env = [
	'mode_debug' => true,
	'project_dir' => $privateDir . '/project',
	'config_dir' => $privateDir . '/config-dev',
	'data_dir' => $privateDir . '/data-dev',
];
