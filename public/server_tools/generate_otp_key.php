<?php
include "../_inc.header.php";

$g = new \Sonata\GoogleAuthenticator\GoogleAuthenticator();

echo "New otp key for client settings: " . $g->generateSecret();