<?php
define('APP_ROOT', dirname(__DIR__));

spl_autoload_register(function ($class) {
    if (str_starts_with($class, 'App\\')) {
        $file = APP_ROOT . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . str_replace('\\', DIRECTORY_SEPARATOR, substr($class, 4)) . '.php';
    } else {
        return;
    }
    if (file_exists($file)) {
        require_once $file;
    }
});

use App\Infrastructure\GoogleAuthenticator;

echo "--- Testing Google Authenticator TOTP Logic ---\n";

$secret = GoogleAuthenticator::generateSecret();
echo "Generated Secret: $secret\n";

$qrUrl = GoogleAuthenticator::getQrCodeUrl('EVELENS', 'test@example.com', $secret);
echo "QR Code URL: $qrUrl\n";

$code = GoogleAuthenticator::getCode($secret);
echo "Current Code (Calculated): $code\n";

echo "Verifying current code: ";
if (GoogleAuthenticator::verifyCode($secret, $code)) {
    echo "SUCCESS\n";
} else {
    echo "FAILED\n";
}

echo "Verifying invalid code '000000': ";
if (!GoogleAuthenticator::verifyCode($secret, '000000')) {
    echo "SUCCESS (rejected correctly)\n";
} else {
    echo "FAILED (accepted invalid code)\n";
}
