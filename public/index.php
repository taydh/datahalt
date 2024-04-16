<?php
$do = $_GET['do'] ?? null;
$endpointMap = [
    'auth' => './auth.php',
    'query' => './query.php',
];

if (array_key_exists($do, $endpointMap))
    include $endpointMap[$do];
else
    echo 'No endpoint matched';