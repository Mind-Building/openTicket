<?php
require_once __DIR__ . '/../app/bootstrap.php';

use App\Core\Database;

try {
    $db = Database::getInstance();
    $stmt = $db->query("SHOW COLUMNS FROM vouchers LIKE 'purchase_intent'");
    if ($stmt->rowCount() > 0) {
        echo "Column 'purchase_intent' exists.\n";
    } else {
        echo "Column 'purchase_intent' is missing. Adding it...\n";
        $db->query("ALTER TABLE vouchers ADD COLUMN purchase_intent ENUM('buy', 'redeem') DEFAULT 'buy' AFTER session_id");
        echo "Column 'purchase_intent' added successfully.\n";
    }
} catch (\Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}

