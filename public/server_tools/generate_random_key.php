<?php
echo "New random key for client id: " . bin2hex(random_bytes($_GET['size'] ?? 8)) . PHP_EOL;