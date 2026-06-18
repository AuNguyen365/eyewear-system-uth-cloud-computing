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

    // Default to the logged-in user in screenshot
    $email = $argv[1] ?? 'danhng0409@gmail.com';
    $email = trim($email);

    echo "--- Deleting all data for user email: $email ---\n";

    // 1. Fetch user ID
    $stmt = $db->prepare('SELECT id FROM `user` WHERE email = ?');
    $stmt->execute([$email]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        echo "ERROR: User with email '$email' not found.\n";
        exit(1);
    }

    $userId = (int)$user['id'];
    echo "Found user ID: $userId\n";

    // Start database transaction
    $db->beginTransaction();

    // 2. Find all orders for the user
    $orderStmt = $db->prepare('SELECT id FROM `order` WHERE user_id = ?');
    $orderStmt->execute([$userId]);
    $orders = $orderStmt->fetchAll(PDO::FETCH_COLUMN);

    if (!empty($orders)) {
        echo "Found " . count($orders) . " orders. Deleting order dependencies...\n";
        $orderIdsPlaceholder = implode(',', array_fill(0, count($orders), '?'));

        // Delete returnrequest related to order items
        $delReturnReq = $db->prepare('DELETE FROM `returnrequest` WHERE user_id = ?');
        $delReturnReq->execute([$userId]);
        echo "- Deleted return requests.\n";

        // Delete payment records
        $delPayment = $db->prepare("DELETE FROM `payment` WHERE order_id IN ($orderIdsPlaceholder)");
        $delPayment->execute($orders);
        echo "- Deleted payments.\n";

        // Delete shipment records
        $delShipment = $db->prepare("DELETE FROM `shipment` WHERE order_id IN ($orderIdsPlaceholder)");
        $delShipment->execute($orders);
        echo "- Deleted shipments.\n";

        // Delete orderitem records
        $delOrderItems = $db->prepare("DELETE FROM `orderitem` WHERE order_id IN ($orderIdsPlaceholder)");
        $delOrderItems->execute($orders);
        echo "- Deleted order items.\n";

        // Delete orders
        $delOrders = $db->prepare("DELETE FROM `order` WHERE id IN ($orderIdsPlaceholder)");
        $delOrders->execute($orders);
        echo "- Deleted orders.\n";
    } else {
        echo "No orders found for this user.\n";
    }

    // 3. Delete from `user` table (cascades to: profiles, user_addresses, user_roles, supporttickets, ticket_replies, cart, cartitem, prescription, etc.)
    echo "Deleting user record and triggering cascades...\n";
    $delUser = $db->prepare('DELETE FROM `user` WHERE id = ?');
    $delUser->execute([$userId]);

    $db->commit();
    echo "SUCCESS: User '$email' and all related data have been completely deleted!\n";

} catch (Throwable $e) {
    if (isset($db) && $db->inTransaction()) {
        $db->rollBack();
    }
    echo "ERROR: " . $e->getMessage() . "\n";
    exit(1);
}
