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

require_once APP_ROOT . '/app/Infrastructure/env.php';
require_once APP_ROOT . '/app/Infrastructure/database.php';

try {
    connect_application_database();
    $db = \Core\Database::getInstance();
    $stmt = $db->query('SELECT id, email, full_name, status FROM `user`');
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "ID\tEmail\t\t\tName\t\tStatus\n";
    echo str_repeat("-", 80) . "\n";
    foreach ($users as $u) {
        echo "{$u['id']}\t{$u['email']}\t{$u['full_name']}\t{$u['status']}\n";
    }
} catch (Throwable $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}
