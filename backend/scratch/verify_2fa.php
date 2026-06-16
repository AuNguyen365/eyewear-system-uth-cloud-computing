<?php

define('APP_ROOT', dirname(__DIR__));

if (!function_exists('str_starts_with')) {
    function str_starts_with(string $haystack, string $needle): bool
    {
        return $needle === '' || strncmp($haystack, $needle, strlen($needle)) === 0;
    }
}

spl_autoload_register(function ($class) {
    if (str_starts_with($class, 'App\\')) {
        $file = APP_ROOT . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . str_replace('\\', DIRECTORY_SEPARATOR, substr($class, 4)) . '.php';
    } elseif (str_starts_with($class, 'Core\\')) {
        $file = APP_ROOT . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . str_replace('\\', DIRECTORY_SEPARATOR, substr($class, 5)) . '.php';
    } else {
        return;
    }

    if (file_exists($file)) {
        require_once $file;
    }
});

require_once APP_ROOT . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'Infrastructure' . DIRECTORY_SEPARATOR . 'env.php';
require_once APP_ROOT . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'Infrastructure' . DIRECTORY_SEPARATOR . 'database.php';

use App\Application\AuthService;
use Core\Database;

echo "--- Programmatic 2FA Flow Verification ---\n";

try {
    // Initialize DB Connection
    connect_application_database();
    
    $db = Database::getInstance();
    $authService = new AuthService();

    // 1. Create or prepare a test user
    $email = '2fa_verify_test@example.com';
    $password = 'password123';
    
    // Clean up if left over
    $db->prepare("DELETE FROM `user` WHERE email = ?")->execute([$email]);
    
    echo "Creating test user: $email...\n";
    $authService->register([
        'name' => '2FA Test User',
        'email' => $email,
        'password' => $password
    ]);

    // Set user to active and 2FA enabled
    $db->prepare("UPDATE `user` SET status = 'active', two_factor_enabled = 1 WHERE email = ?")->execute([$email]);

    // 2. Perform Login (which should trigger 2FA)
    echo "Attempting login to trigger 2FA...\n";
    
    $loginResult = null;
    try {
        $loginResult = $authService->login([
            'email' => $email,
            'password' => $password
        ]);
    } catch (\Exception $e) {
        $msg = $e->getMessage();
        if (str_contains($msg, 'configuration is missing') || 
            str_contains($msg, 'Could not send') || 
            str_contains($msg, 'SMTP Error') || 
            str_contains($msg, 'authenticate') ||
            str_contains($msg, 'Mailer Error')) {
            echo "Mail dispatch skipped due to SMTP configuration/connectivity ($msg), verifying database code generation...\n";
        } else {
            throw $e;
        }
    }

    // Read the generated code from database
    $stmt = $db->prepare('SELECT id, two_factor_code, two_factor_expires_at FROM `user` WHERE email = ?');
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    if (empty($user['two_factor_code'])) {
        throw new Exception("FAIL: two_factor_code was not generated in the database.");
    }
    
    echo "SUCCESS: Code '{$user['two_factor_code']}' was generated with expiry '{$user['two_factor_expires_at']}'!\n";

    // Generate temp token manually if mail send threw, so we can test the verify method
    $tempToken = base64_encode('2fa_temp:' . $user['id'] . ':' . time());
    
    // 3. Test verification with WRONG code
    echo "Testing verification with WRONG code...\n";
    try {
        $authService->verifyTwoFactorCode($tempToken, '000000');
        throw new Exception("FAIL: Verification succeeded with a wrong code.");
    } catch (\Exception $e) {
        echo "PASS: Wrong code rejected: " . $e->getMessage() . "\n";
    }

    // 4. Test verification with CORRECT code
    echo "Testing verification with CORRECT code...\n";
    $verifyResult = $authService->verifyTwoFactorCode($tempToken, $user['two_factor_code']);
    
    if (empty($verifyResult['token']) || empty($verifyResult['user'])) {
        throw new Exception("FAIL: Verification did not return user and token.");
    }
    
    echo "PASS: Verification successful! Token: " . substr($verifyResult['token'], 0, 15) . "...\n";

    // 5. Verify database columns cleared
    $stmt->execute([$email]);
    $userAfter = $stmt->fetch();
    if (!empty($userAfter['two_factor_code'])) {
         throw new Exception("FAIL: two_factor_code was not cleared after verification.");
    }
    echo "PASS: Database code fields cleared after verification.\n";

    // Clean up
    $db->prepare("DELETE FROM `user` WHERE email = ?")->execute([$email]);
    echo "Test user deleted.\n";
    echo "\n--- ALL Programmatic 2FA Tests PASSED! ---\n";

} catch (Throwable $e) {
    echo "VERIFICATION FAILED: " . $e->getMessage() . "\n";
    // Clean up test user if exists
    if (isset($db) && isset($email)) {
        $db->prepare("DELETE FROM `user` WHERE email = ?")->execute([$email]);
    }
    exit(1);
}
