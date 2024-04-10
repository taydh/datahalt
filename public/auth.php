<?php
include '_inc.header.php';

$result = [
	'status' => 'ok',
	'data' => null,
];

try {
	$result['data'] = \Taydh\DataHalt\Helper\RequestHelper::authenticate();
}
catch(\Exception $exc) {
	$result['status'] = 'fail';
	$result['message'] = $exc->getMessage();
	http_response_code(422); // 422 Unprocessable Entity
}

header('Content-Type: application/json; charset=utf-8');
echo json_encode($result);
