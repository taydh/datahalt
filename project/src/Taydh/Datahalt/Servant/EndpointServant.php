<?php
namespace Taydh\Datahalt\Servant;

use Defuse\Crypto\Key;
use Defuse\Crypto\Crypto;

class EndpointServant
{
	public static function readEndpointSettings() {
		return parse_ini_file("{$_ENV['datahalt.config_dir']}/endpoint.ini");
	}
	
	public static function readClientSettings($clientId) {
		return @parse_ini_file("{$_ENV['datahalt.config_dir']}/clients/{$clientId}.ini", true);
	}

	public function authenticate ( $clientId, $otp ) {
		if (!$clientId) return null;
		
		$clientSettings = self::readClientSettings($clientId);
		
		if (!$clientSettings) throw new \Exception('Client settings not found or invalid');
		if ($clientSettings['auth.disabled'] == 1) throw new \Exception('Auth not required');

		$g = new \Sonata\GoogleAuthenticator\GoogleAuthenticator();
	
		if (!$g->checkCode($clientSettings['auth.otp_key'], $otp)) throw new \Exception('invalid OTP');
		
		$appSettings = self::readEndpointSettings();
		$defKey = Key::loadFromAsciiSafeString($appSettings['encryption_keys'][0]);
		$authTime = time();
		
		$authInfo = [
			'clientId' => $clientId,
			'authTime' => $authTime,
			'authExpiresIn' => $_ENV['datahalt.mode_debug'] ? 60*60 : $clientSettings['auth.exp_in'],
		];
		
		return [
			'authToken' => 'DATAHALT.' . Crypto::encrypt(serialize($authInfo), $defKey),
			'expiresIn' => $clientSettings['auth.exp_in'],
		];
	}

	public function process ( $clientId = null, $queryObject = null )
	{
		$appSettings = self::readEndpointSettings();

		if (!$clientId) {
			$clientId = $this->authorize();
		}
		
		if (!$clientId) return null;
		else return $this->start($clientId, $queryObject);
	}
	
	private function authorize() {
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
			$appSettings = self::readEndpointSettings();

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
			$clientSettings = self::readClientSettings($clientId);
		
			if (!$clientSettings || $clientSettings['auth.disabled'] == 0)  throw new \Exception('Authorization fail '.$level);
			++$level;

			$result = $clientId;
		}
		
		return $result;
	}
	
	private function start ( $clientId, $queryObject ) {
		if (!$queryObject) {
			$requestBody = file_get_contents('php://input');
			$queryObject = json_decode($requestBody);
			
			if (!$queryObject || !property_exists($queryObject, 'entries')) throw new \Exception('Invalid JSON, cannot process Telequery');
		}
		
		$clientSettings = self::readClientSettings($clientId);
		$queryRunner = new \Taydh\TeleQuery\QueryRunner($clientSettings);

		return $queryRunner->run($queryObject);
	}
}