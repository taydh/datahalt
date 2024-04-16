<?php
namespace Taydh\DataHalt\Helper;

use Defuse\Crypto\Key;
use Defuse\Crypto\Crypto;
use Firebase\JWT\JWT;
use Firebase\JWT\Key as JWTKey;

final class RequestHelper {
	public static function processRequest() {
		$appSettings = ConfigurationHelper::readAppSettings();
		
		if (array_key_exists('bypass', $_GET) && $appSettings['app_debug']) {
			return self::runRequest('desktop');
		}
		else {
			$clientId = self::authorizeRequest();
			
			if (!$clientId) return null;
			else return self::runRequest($clientId);
		}
	}
	
	public static function authenticate() {
		$clientId = $_POST['clientId'] ?? null;
		$otp = $_POST['otp'] ?? null;

		if (!$clientId) return null;
		
		$clientSettings = ConfigurationHelper::readClientSettings($clientId);
		
		if (!$clientSettings) throw new \Exception('Client settings not found or invalid');
		if ($clientSettings['auth.disabled'] == 1) throw new \Exception('Auth not required');

		$g = new \Sonata\GoogleAuthenticator\GoogleAuthenticator();
	
		if (!$g->checkCode($clientSettings['auth.otp_key'], $otp)) throw new \Exception('invalid OTP');
		
		$appSettings = ConfigurationHelper::readAppSettings();
		$defKey = Key::loadFromAsciiSafeString($appSettings['encryption_keys'][0]);
		$authTime = time();
		
		$authInfo = [
			'clientId' => $clientId,
			'authTime' => $authTime,
			'authExpiresIn' => $_ENV['mode_debug'] ? 60*60 : $clientSettings['auth.exp_in'],
		];
		
		return [
			'authToken' => 'DATAHALT.' . Crypto::encrypt(serialize($authInfo), $defKey),
			'expiresIn' => $clientSettings['auth.exp_in'],
		];
	}
	
	private static function authorizeRequest() {
		$result = false;
		$level = 1;

		// check Authorization header
		$headers = apache_request_headers();
		$bearerToken = '';

		if (isset($headers['Authorization']) && strpos($headers['Authorization'], 'Bearer ') == 0) {
			$bearerToken = substr($headers['Authorization'], strpos($headers['Authorization'], ' ') + 1);
		}

		if (!$bearerToken) throw new \Exception('Authorization fail '.$level);
		++$level;

		if (strpos($bearerToken,'.') > 0 && count($authTokenParts = explode('.', $bearerToken, 2)) == 2 && $authTokenParts[0] == 'DATAHALT') {
			$authToken = $authTokenParts[1];
			$decrypted = null;
			$appSettings = ConfigurationHelper::readAppSettings();

			foreach ($appSettings['encryption_keys'] as $asciiKey){
				try {
					$defKey = Key::loadFromAsciiSafeString($asciiKey);
					$decrypted = Crypto::decrypt($authToken, $defKey);
					break;
				}
				catch(\Exception $ex){ /* skip */ }
			}
			
			if (!$decrypted) throw new \Exception('Authorization fail '. $level);
			++$level;
			
			// unserialize
			$authInfo = unserialize($decrypted);
			
			if (time() > $authInfo['authTime'] + $authInfo['authExpiresIn']) throw new \Exception('Authorization fail ' . $level);
			++$level;
			
			$result = $authInfo['clientId'];
		}
		else {
			// continue if bearer token is client id, settings exists and auth disabled
			$clientId = $bearerToken;
			$clientSettings = ConfigurationHelper::readClientSettings($clientId);
		
			if (!$clientSettings || $clientSettings['auth.disabled'] == 0)  throw new \Exception('Authorization fail '.$level);
			++$level;

			$result = $clientId;
		}
		
		return $result;
	}
	
	private static function runRequest($clientId) {
		$requestBody = file_get_contents('php://input');
		$data = json_decode($requestBody);
		
		if (!$data || !property_exists($data, 'entries')) throw new \Exception('Invalid content');
		
		$clientSettings = ConfigurationHelper::readClientSettings($clientId);
		$queryRunner = new \Taydh\TeleQuery\QueryRunner($clientSettings);

		return $queryRunner->run($data);
	}
}
