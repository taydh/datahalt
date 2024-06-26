<?php
include 'inc.header.php';

$result = [];

$servant = new \Taydh\Datahalt\Servant\EndpointServant();

try {
	$result['status'] = 'ok';
	$result['data'] = $servant->process();
}
catch(\Exception $exc) {
	$result['status'] = 'fail';
	$result['message'] = $exc->getMessage();
	http_response_code(422); // 422 Unprocessable Entity
}

header('Content-Type: application/json; charset=utf-8');
echo json_encode($result);
