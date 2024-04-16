<?php
if ($_SERVER["SCRIPT_FILENAME"] == __FILE__) die();

(function(){
	$_ENV['env_name'] = 'silver-space-couscous'; /* [IG.1A] */
	$_ENV['base_dir'] = dirname(__DIR__);

	include "env.{$_ENV['env_name']}.php";

	if ($_ENV['mode_debug']) {
		ini_set('error_reporting', E_ALL|E_STRICT);
		ini_set('display_errors', 1);
	}	
})();

require $_ENV['project_dir'] .'/vendor/autoload.php';
