<?php
/**
 * Test Database Connection
 */

// Load environment variables
if (file_exists(__DIR__ . '/../.env')) {
    $lines = file(__DIR__ . '/../.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $line = trim($line);
        if (empty($line) || strpos($line, '#') === 0 || strpos($line, '=') === false) {
            continue;
        }
        $parts = explode('=', $line, 2);
        if (count($parts) === 2) {
            $name = trim($parts[0]);
            $value = trim($parts[1]);
            if (!empty($name)) {
                $_ENV[$name] = $value;
            }
        }
    }
}

// Load database config
$config = require __DIR__ . '/../app/config/database.php';

echo "=== Database Connection Test ===\n\n";
echo "Host: " . $config['host'] . "\n";
echo "Database: " . $config['database'] . "\n";
echo "Username: " . $config['username'] . "\n";
echo "Password: " . (empty($config['password']) ? '(empty)' : '(set)') . "\n\n";

try {
    $dsn = sprintf(
        "mysql:host=%s;dbname=%s;charset=%s",
        $config['host'],
        $config['database'],
        $config['charset']
    );
    
    echo "Attempting connection...\n";
    $pdo = new PDO(
        $dsn,
        $config['username'],
        $config['password'],
        $config['options']
    );
    
    echo "✓ Connection successful!\n\n";
    
    // Test query
    $stmt = $pdo->query("SELECT DATABASE() as db");
    $result = $stmt->fetch();
    echo "Connected to database: " . $result['db'] . "\n";
    
} catch (PDOException $e) {
    echo "✗ Connection failed!\n\n";
    echo "Error: " . $e->getMessage() . "\n\n";
    echo "Possible solutions:\n";
    echo "1. Check if MySQL/MariaDB is running\n";
    echo "2. Verify database '" . $config['database'] . "' exists\n";
    echo "3. Check user '" . $config['username'] . "' has access\n";
    echo "4. Verify .env file contains correct DB_HOST, DB_NAME, DB_USER, DB_PASS\n";
    exit(1);
}


