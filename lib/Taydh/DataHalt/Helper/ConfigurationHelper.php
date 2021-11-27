<?php
namespace Taydh\DataHalt\Helper;

final class ConfigurationHelper {	
	public static function readAppSettings() {
		return parse_ini_file("{$_ENV['config_dir']}/app.ini");
	}
	
	public static function readClientSettings($clientId) {
		return parse_ini_file("{$_ENV['config_dir']}/clients/{$clientId}.ini");
	}
}
