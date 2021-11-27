<?php
require(__DIR__ . '/../../vendor/autoload.php');

echo "New defuse key for app settings: " . \Defuse\Crypto\Key::createNewRandomKey()->saveToAsciiSafeString();
