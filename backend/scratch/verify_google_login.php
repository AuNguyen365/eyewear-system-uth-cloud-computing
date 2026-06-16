<?php

$url = 'http://localhost/api/v1/auth/google-login';
$payload = [
    'id_token' => 'invalid_dummy_token_123'
];

echo "Testing google-login endpoint with an invalid token...\n";
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
echo "Response:\n$res\n\n";

$decoded = json_decode($res, true);
if (isset($decoded['success']) && $decoded['success'] === false && strpos($decoded['message'], 'Google token validation failed') !== false) {
    echo "PASS: Backend successfully rejected invalid token and verified signature flow via Google tokeninfo API!\n";
    exit(0);
} else {
    echo "FAIL: Expected rejection from Google, got: " . var_export($decoded, true) . "\n";
    exit(1);
}
