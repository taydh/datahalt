<?php
require(__DIR__ . '/../../project/vendor/autoload.php');

echo "New defuse key for app settings: " . \Defuse\Crypto\Key::createNewRandomKey()->saveToAsciiSafeString();
