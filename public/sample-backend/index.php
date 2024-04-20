<?php
include_once __DIR__ . '/../inc.header.php';

$endpointClientId = 'codespace_42cbb3013ed46199';
$action = $_GET['action'];

$servant = new Taydh\Datahalt\Servant\SampleBackendServant();
$servant->process($endpointClientId, $action, $params);
