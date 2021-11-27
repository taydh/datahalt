<?php
namespace Taydh\DataHalt\Helper;

use Defuse\Crypto\Key;
use Defuse\Crypto\Crypto;
use Firebase\JWT\JWT;
use Firebase\JWT\Key as JWTKey;

final class RequestHelper {
	const AUTHINFO_EXPIRESIN = 60 * 60;
	
	public static function processRequest() {
		$appSettings = ConfigurationHelper::readAppSettings();
		
		if (array_key_exists('bypass', $_GET) && $appSettings['app_debug']) {
			return self::runRequest('desktop');
		}
		else {
			$authInfo = self::authorizeRequest();
			
			if (!$authInfo) return null;
			else return self::runRequest($authInfo['clientId']);
		}
	}
	
	public static function authenticate() {
		$clientId = $_POST['clientId'] ?? null;
		$otp = $_POST['otp'] ?? null;
		
		if (!$clientId || !$otp) return null;
		
		$appSettings = ConfigurationHelper::readAppSettings();
		$clientSettings = @ConfigurationHelper::readClientSettings($clientId);
		
		if (!$clientSettings) return null;
		
		$g = new \Sonata\GoogleAuthenticator\GoogleAuthenticator();
		
		if (!$g->checkCode($clientSettings['auth.otp_key'], $otp)) throw new \Exception('invalid OTP');
		
		$defKey = Key::loadFromAsciiSafeString($appSettings['encryption_keys'][0]);
		$requestSignKey = bin2hex(random_bytes(8));
		
		$authInfo = [
			'clientId' => $clientId,
			'requestSignKey' => $requestSignKey,
			'authTime' => time(),
		];
		
		return [
			'authInfoToken' => Crypto::encrypt(serialize($authInfo), $defKey),
			'authInfoExpiresIn' => self::AUTHINFO_EXPIRESIN,
			'requestSignKey' => $requestSignKey,
		];
	}
	
	private static function authorizeRequest() {
		$result = false;
		
		// check Authorization header
		$headers = apache_request_headers();

		if(isset($headers['Authorization']) && strpos($headers['Authorization'], 'Bearer ') == 0){
			$jwt = substr($headers['Authorization'], strpos($headers['Authorization'], ' ') + 1);
			
			// extract encrypted auth info in jwt payload
			$appSettings = ConfigurationHelper::readAppSettings();
			$payload = json_decode(base64_decode(explode('.', $jwt)[1]), true);
			$decrypted = null;
			
			foreach ($appSettings['encryption_keys'] as $asciiKey){
				try {
					$defKey = Key::loadFromAsciiSafeString($asciiKey);
					$decrypted = Crypto::decrypt($payload['authInfoToken'], $defKey);
					break;
				}
				catch(\Exception $ex){ /* skip */ }
			}
			
			if (!$decrypted) throw new \Exception('Authorization fail 1');
			
			// unserialize
			$authInfo = unserialize($decrypted);
			
			if (time() > $authInfo['authTime'] + self::AUTHINFO_EXPIRESIN) throw new \Exception('Authorization fail 2');
			
			// verify jwt
			$decoded = JWT::decode($jwt, new JWTKey($authInfo['requestSignKey'], 'HS256'));
			
			if (!$decoded) throw new \Exception('Authorization fail 3');
			
			$result = $authInfo;
		}
		
		return $result;
	}
	
	private static function runRequest($clientId) {
		$requestBody = file_get_contents('php://input');
		$data = json_decode($requestBody);
		
		if (!$data) throw new \Exception('Invalid content');
		
		$clientSettings = ConfigurationHelper::readClientSettings($clientId);
		$queryRunner = new \Taydh\TeleQuery\QueryRunner($clientSettings);
		
		if (is_array($data) || property_exists($data, 'batch')) {
			$result = [];
			$mainEntries = is_array($data) ? $data : $data->batch;
			
			foreach ($mainEntries as $mainEntry) {
				$result[] = $queryRunner->run($mainEntry);
			}
			
			return $result;
		}
		else {
			return $queryRunner->run($data);
		}
	}
}
