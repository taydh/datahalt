<?php
include "../_inc.header.php";

// provided in successful authentication
$expiresIn = $_POST['expiresIn'];
$signKey = $_POST['signKey']; 
$authInfoToken = $_POST['authInfoToken'];

use Firebase\JWT\JWT;
use Firebase\JWT\Key;

$now = time();
$payload = array(
    "iat" => $now,
	"exp" => $now + ($_ENV['mode_debug'] ? 60 * 60 : $expiresIn), // 10 seconds lifetime
	"authInfoToken" => $authInfoToken,
);

/**
 * IMPORTANT:
 * You must specify supported algorithms for your application. See
 * https://tools.ietf.org/html/draft-ietf-jose-json-web-algorithms-40
 * for a list of spec-compliant algorithms.
 */
$jwt = JWT::encode($payload, $signKey, 'HS256');

echo "'Bearer " . $jwt. "'";