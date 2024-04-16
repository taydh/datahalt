<?php
if ($_SERVER["SCRIPT_FILENAME"] == __FILE__) die();
	
/* 
[IG.1B]
Changes configuration below to match your installation
*/

$_ENV['mode_debug'] = true;
$_ENV['project_dir'] = __DIR__ . '/../project';
$_ENV['config_dir'] = __DIR__ . '/../private/config-dev';
$_ENV['data_dir'] = __DIR__ . '/../private/data-dev';
