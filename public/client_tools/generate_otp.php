<?php
include "../inc.header.php";

$otpKey = $_POST['otp_key'];

$g = new \Sonata\GoogleAuthenticator\GoogleAuthenticator();

echo "OTP: " . $g->getCode($otpKey);