<?php
if ($_SERVER["SCRIPT_FILENAME"] == __FILE__) die();
	
/* 
[IG.1B]
Changes configuration below to match your installation
*/

$_ENV['datahalt.mode_debug'] = true;
$_ENV['datahalt.project_dir'] = __DIR__ . '/../project';
$_ENV['datahalt.config_dir'] = __DIR__ . '/../private/config-dev';
$_ENV['datahalt.function_dir'] = __DIR__ . '/../private/function-dev';
$_ENV['datahalt.data_dir'] = __DIR__ . '/../private/data-dev';
