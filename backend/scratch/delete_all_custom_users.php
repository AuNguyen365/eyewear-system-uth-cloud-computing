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

    echo "--- Deleting all custom registered users and their related data ---\n";

    // System seed emails to preserve
    $preserveEmails = [
        'admin@eyewear.com',
        'manager@eyewear.com',
        'sales@eyewear.com',
        'operations@eyewear.com',
        'customer@eyewear.com',
        'vana@gmail.com',
        'thib@gmail.com',
        'vanc@gmail.com'
    ];

    // Get all users not in the preservation list
    $placeholders = implode(',', array_fill(0, count($preserveEmails), '?'));
    $stmt = $db->prepare("SELECT id, email FROM `user` WHERE email NOT IN ($placeholders)");
    $stmt->execute($preserveEmails);
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($users)) {
        echo "No custom users found to delete.\n";
        exit(0);
    }

    echo "Found " . count($users) . " custom users to delete.\n";

    foreach ($users as $user) {
        $userId = (int)$user['id'];
        $email = $user['email'];
        echo "\nProcessing User: $email (ID: $userId)...\n";

        $db->beginTransaction();

        // 1. Find all orders for this user
        $orderStmt = $db->prepare('SELECT id FROM `order` WHERE user_id = ?');
        $orderStmt->execute([$userId]);
        $orders = $orderStmt->fetchAll(PDO::FETCH_COLUMN);

        if (!empty($orders)) {
            echo "  Deleting " . count($orders) . " orders and dependencies...\n";
            $orderIdsPlaceholder = implode(',', array_fill(0, count($orders), '?'));

            // Delete return requests
            $delReturnReq = $db->prepare('DELETE FROM `returnrequest` WHERE user_id = ?');
            $delReturnReq->execute([$userId]);

            // Delete payments
            $delPayment = $db->prepare("DELETE FROM `payment` WHERE order_id IN ($orderIdsPlaceholder)");
            $delPayment->execute($orders);

            // Delete shipments
            $delShipment = $db->prepare("DELETE FROM `shipment` WHERE order_id IN ($orderIdsPlaceholder)");
            $delShipment->execute($orders);

            // Delete order items
            $delOrderItems = $db->prepare("DELETE FROM `orderitem` WHERE order_id IN ($orderIdsPlaceholder)");
            $delOrderItems->execute($orders);

            // Delete orders
            $delOrders = $db->prepare("DELETE FROM `order` WHERE id IN ($orderIdsPlaceholder)");
            $delOrders->execute($orders);
        }

        // 2. Delete user (cascades to profile, address, cart, tickets, etc.)
        $delUser = $db->prepare('DELETE FROM `user` WHERE id = ?');
        $delUser->execute([$userId]);

        $db->commit();
        echo "  Successfully deleted '$email'.\n";
    }

    echo "\nAll custom users have been successfully cleaned up!\n";

} catch (Throwable $e) {
    if (isset($db) && $db->inTransaction()) {
        $db->rollBack();
    }
    echo "ERROR: " . $e->getMessage() . "\n";
    exit(1);
}
