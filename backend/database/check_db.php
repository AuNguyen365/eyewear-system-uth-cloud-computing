<?php

define('APP_ROOT', dirname(__DIR__));

if (!function_exists('str_starts_with')) {
    function str_starts_with(string $haystack, string $needle): bool
    {
        return $needle === '' || strncmp($haystack, $needle, strlen($needle)) === 0;
    }
}

if (!function_exists('str_ends_with')) {
    function str_ends_with(string $haystack, string $needle): bool
    {
        return $needle === '' || substr_compare($haystack, $needle, -strlen($needle)) === 0;
    }
}

if (!function_exists('str_contains')) {
    function str_contains(string $haystack, string $needle): bool
    {
        return $needle === '' || strpos($haystack, $needle) !== false;
    }
}

require_once APP_ROOT . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'Infrastructure' . DIRECTORY_SEPARATOR . 'env.php';

$config = load_env_config();
$host = env_value($config, ['DB_HOST', 'MYSQL_HOST'], '127.0.0.1');
$port = env_value($config, ['DB_PORT', 'MYSQL_PORT'], '3306');
$dbName = env_value($config, ['DB_DATABASE', 'MYSQL_DATABASE'], 'eyewear_system');
$user = env_value($config, ['DB_USERNAME', 'MYSQL_USER'], 'root');
$pass = env_value($config, ['DB_PASSWORD', 'MYSQL_PASSWORD'], '');

try {
    $pdo = new PDO("mysql:host=$host;port=$port", $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
    ]);

    // Check if database exists
    $stmt = $pdo->query("SHOW DATABASES LIKE '$dbName'");
    $dbExists = $stmt->rowCount() > 0;
    if (!$dbExists) {
        echo "Database `$dbName` does not exist. Needs initialization.\n";
        exit(1); // Needs initialization
    }

    $pdo->exec("USE `$dbName` ");
    
    // Check if tables exist
    $stmt = $pdo->query("SHOW TABLES");
    $tablesCount = $stmt->rowCount();
    if ($tablesCount === 0) {
        echo "Database `$dbName` has no tables. Needs initialization.\n";
        exit(1); // Needs initialization
    }

    echo "Database `$dbName` is already initialized with $tablesCount tables. Skipping initialization.\n";
    exit(0); // Already initialized
} catch (PDOException $e) {
    echo "Connection failed or database error: " . $e->getMessage() . "\n";
    exit(1); // Try initializing
}
