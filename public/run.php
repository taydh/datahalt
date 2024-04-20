<?php
include 'inc.header.php';

$backendId = $_GET['id'];
$group = $_GET['group'];
$action = $_GET['action'];
$params = $_POST;
$result = [];

$servant = new \Taydh\Datahalt\Servant\BackendServant( $backendId );

try {
	$result['status'] = 'ok';
	$result['data'] = $servant->process($group, $action, $params);
}
catch(\Exception $exc) {
	$result['status'] = 'fail';
	$result['message'] = $exc->getMessage();
	http_response_code(422); // 422 Unprocessable Entity
}

header('Content-Type: application/json; charset=utf-8');
echo json_encode($result);
