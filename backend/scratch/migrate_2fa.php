<?php
define('APP_ROOT', dirname(__DIR__));

if (!function_exists('str_starts_with')) {
    function str_starts_with(string $haystack, string $needle): bool
    {
        return $needle === '' || strncmp($haystack, $needle, strlen($needle)) === 0;
    }
}

require_once APP_ROOT . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'Infrastructure' . DIRECTORY_SEPARATOR . 'env.php';

echo "--- Eyewear System 2FA DB Migration ---\n";

try {
    $config = load_env_config();
    $host = env_value($config, ['DB_HOST', 'MYSQL_HOST'], '127.0.0.1');
    $port = env_value($config, ['DB_PORT', 'MYSQL_PORT'], '3306');
    $dbName = env_value($config, ['DB_DATABASE', 'MYSQL_DATABASE'], 'eyewear_system');
    $user = env_value($config, ['DB_USERNAME', 'MYSQL_USER'], 'root');
    $pass = env_value($config, ['DB_PASSWORD', 'MYSQL_PASSWORD'], '');

    echo "Connecting to MySQL at $host:$port...\n";
    $pdo = new PDO("mysql:host=$host;port=$port", $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
    ]);
    
    $pdo->exec("USE `$dbName` ");
    
    // Check if two_factor_enabled column exists
    $checkQuery = "SHOW COLUMNS FROM `user` LIKE 'two_factor_enabled'";
    $stmt = $pdo->query($checkQuery);
    $columnExists = $stmt->rowCount() > 0;
    
    if ($columnExists) {
        echo "2FA columns already exist in `user` table. Skipping.\n";
    } else {
        echo "Adding 2FA columns to `user` table...\n";
        $pdo->exec("ALTER TABLE `user` 
            ADD COLUMN `two_factor_enabled` TINYINT(1) DEFAULT 0,
            ADD COLUMN `two_factor_code` VARCHAR(10) NULL,
            ADD COLUMN `two_factor_expires_at` TIMESTAMP NULL;
        ");
        echo "Migration completed successfully!\n";
    }
} catch (Throwable $e) {
    echo "FATAL ERROR: " . $e->getMessage() . "\n";
    exit(1);
}
