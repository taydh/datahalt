<?php
if ($_SERVER["SCRIPT_FILENAME"] == __FILE__) die();

(function(){
	$_ENV['datahalt.http_host'] = $_SERVER['HTTP_HOST']; /* [IG.1A] */
	$_ENV['datahalt.base_dir'] = dirname(__DIR__);

	$hostString = strtolower($_ENV['datahalt.http_host']);
	$hostString = str_replace(':', '_', $hostString);

	include "env.{$hostString}.php";

	if ($_ENV['datahalt.mode_debug']) {
		ini_set('error_reporting', E_ALL|E_STRICT);
		ini_set('display_errors', 1);
	}
})();

require $_ENV['datahalt.project_dir'] .'/vendor/autoload.php';
