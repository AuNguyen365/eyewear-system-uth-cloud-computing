<?php

$url = 'http://localhost/api/v1/auth/register';
$email = 'verify_test_' . time() . '@example.com';
$payload = [
    'name' => 'Verify Test User',
    'email' => $email,
    'password' => 'password123'
];

echo "Testing registration with email: $email...\n";
$body = json_encode($payload);
$context = stream_context_create([
    'http' => [
        'method' => 'POST',
        'header' => "Content-Type: application/json\r\n" .
                    "Content-Length: " . strlen($body) . "\r\n",
        'content' => $body,
        'ignore_errors' => true
    ]
]);
$res = file_get_contents($url, false, $context);
echo "Registration Response:\n$res\n\n";

$decoded = json_decode($res, true);
if (empty($decoded['success'])) {
    echo "FAIL: Registration failed\n";
    exit(1);
}

echo "Testing login with registered credentials...\n";
$loginUrl = 'http://localhost/api/v1/auth/login';
$loginPayload = [
    'email' => $email,
    'password' => 'password123'
];
$loginBody = json_encode($loginPayload);
$loginContext = stream_context_create([
    'http' => [
        'method' => 'POST',
        'header' => "Content-Type: application/json\r\n" .
                    "Content-Length: " . strlen($loginBody) . "\r\n",
        'content' => $loginBody,
        'ignore_errors' => true
    ]
]);
$loginRes = file_get_contents($loginUrl, false, $loginContext);
echo "Login Response:\n$loginRes\n\n";

$loginDecoded = json_decode($loginRes, true);
if (empty($loginDecoded['success'])) {
    echo "FAIL: Login failed\n";
    exit(1);
}

echo "PASS: Registration and Login verified successfully without email activation!\n";
exit(0);
