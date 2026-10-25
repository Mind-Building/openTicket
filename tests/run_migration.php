<?php
/**
 * Utility script to run the SQL migration from PHP
 */

require_once __DIR__ . '/../app/bootstrap.php';

use App\Core\Database;

try {
    echo "Applying Migration: d:/xampp/htdocs/ticket2.0/database/migration_fix_schema.sql ...\n";
    
    $db = Database::getInstance();
    $connection = $db->getConnection();
    
    $sql = file_get_contents(__DIR__ . '/../database/migration_fix_schema.sql');
    if ($sql === false) {
        throw new Exception("Could not read migration file.");
    }
    
    // Split SQL into individual statements
    // This is a simple split, might fail on complex SQL but should work for ALTER TABLEs
    $statements = array_filter(array_map('trim', explode(';', $sql)));
    
    foreach ($statements as $stmt) {
        if (empty($stmt)) continue;
        echo "Executing: " . substr($stmt, 0, 50) . "...\n";
        $connection->exec($stmt);
    }
    
    echo "\n✓ Migration applied successfully!\n";

} catch (Exception $e) {
    echo "\n✗ MIGRATION FAILED: " . $e->getMessage() . "\n";
    exit(1);
}
