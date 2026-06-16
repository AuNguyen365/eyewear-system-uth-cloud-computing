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

use App\Application\ProfileService;

try {
    connect_application_database();
    $profileService = new ProfileService();
    
    // Test with user ID 1 or find first user ID in DB
    $db = \Core\Database::getInstance();
    $stmt = $db->query('SELECT id FROM `user` LIMIT 1');
    $user = $stmt->fetch();
    
    if (!$user) {
        echo "No users found in database.\n";
        exit;
    }
    
    $userId = (int)$user['id'];
    echo "Debugging profile loading for user ID: $userId...\n";
    
    $profile = $profileService->getProfile($userId);
    echo "Profile loaded successfully! Result:\n";
    print_r($profile);
    
} catch (Throwable $e) {
    echo "ERROR caught during profile load:\n";
    echo $e->getMessage() . "\n";
    echo $e->getTraceAsString() . "\n";
}
