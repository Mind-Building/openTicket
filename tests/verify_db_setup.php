<?php
/**
 * Test script to verify database setup and seeding
 */

require_once __DIR__ . '/../app/bootstrap.php';

use App\Core\Database;

try {
    $db = Database::getInstance();
    $pdo = $db->getConnection();
    
    echo "Testing Database Setup...\n";
    
    // 1. Check if tables exist
    $tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
    echo "Current tables: " . implode(', ', $tables) . "\n";
    
    // 2. Try to run schema again (should fail if tables exist, but we want to see if it works on empty db)
    // For this test, we just check if key tables exist
    $requiredTables = ['users', 'configurations', 'events', 'reservation_statuses'];
    foreach ($requiredTables as $table) {
        if (in_array($table, $tables)) {
            echo "✓ Table '$table' exists.\n";
        } else {
            echo "✗ Table '$table' is MISSING!\n";
        }
    }
    
    // 3. Check if statuses were seeded
    $statusCount = $pdo->query("SELECT COUNT(*) FROM reservation_statuses")->fetchColumn();
    echo "Reservation statuses count: $statusCount\n";
    if ($statusCount > 0) {
        echo "✓ Statuses seeded.\n";
    } else {
        echo "✗ Statuses NOT seeded!\n";
    }
    
    // 4. Check if admin user exists
    $adminExists = $pdo->query("SELECT COUNT(*) FROM users WHERE username = 'admin'")->fetchColumn();
    if ($adminExists) {
        echo "✓ Admin user exists.\n";
    } else {
        echo "✗ Admin user MISSING!\n";
    }
    
    echo "\nVerification complete.\n";

} catch (Exception $e) {
    echo "Verification failed: " . $e->getMessage() . "\n";
}
