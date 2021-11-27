<?php
if ($_SERVER["SCRIPT_FILENAME"] == __FILE__) die();

(function(){
	include '_inc.env.php';

	if ($env['mode_debug']) {
		ini_set('error_reporting', E_ALL|E_STRICT);
		ini_set('display_errors', 1);
	}

	$_ENV['base_dir'] = dirname(__DIR__);
	$_ENV['mode_debug'] = $env['mode_debug'];
	$_ENV['project_dir'] = $env['project_dir'];
	$_ENV['config_dir'] = $env['config_dir'];
	$_ENV['data_dir'] = $env['data_dir'];
})();

require $_ENV['project_dir'] .'/vendor/autoload.php';
