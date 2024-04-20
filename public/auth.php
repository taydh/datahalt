<?php
include 'inc.header.php';

$clientId = $_POST['clientId'] ?? null;
$otp = $_POST['otp'] ?? null;

$result = [];

$servant = new \Taydh\Datahalt\Servant\EndpointServant();

try {
	$result['status'] = 'ok';
	$result['data'] = $servant->authenticate($clientId, $otp);
}
catch(\Exception $exc) {
	$result['status'] = 'fail';
	$result['message'] = $exc->getMessage();
	http_response_code(422); // 422 Unprocessable Entity
}

header('Content-Type: application/json; charset=utf-8');
echo json_encode($result);
