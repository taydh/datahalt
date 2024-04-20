<?php
include 'inc.header.php';

$backendId = $_GET['_be_id'];
$group = $_GET['_be_group'];
$action = $_GET['_be_action'];
$externalArgs = [];
$result = [];

$servant = new \Taydh\Datahalt\Servant\BackendServant( $backendId );

try {
	array_walk($_POST, function (&$item, $key) use (&$externalArgs, $servant) {
		$externalArgs[$servant->getExternalArgumentPrefix().$key] = $_POST[$key];
	});

	$result['status'] = 'ok';
	$result['data'] = $servant->process($group, $action, $externalArgs);
}
catch(\Exception $exc) {
	$result['status'] = 'fail';
	$result['message'] = $exc->getMessage();
	http_response_code(422); // 422 Unprocessable Entity
}

header('Content-Type: application/json; charset=utf-8');
echo json_encode($result);
