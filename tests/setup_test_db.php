<?php
/**
 * One-time setup script for the test database.
 *
 * Creates a fresh `ticket_test` database (or whatever DB_NAME points to,
 * as long as it contains the word "test") and loads database/schema.sql
 * into it. Safe to re-run – the script DROPS and recreates the DB each
 * time, so the schema and seeded reservation_statuses stay current with
 * production.
 *
 * Usage:
 *   php tests/setup_test_db.php
 *
 * Override defaults via shell env:
 *   DB_HOST=localhost DB_NAME=ticket_test DB_USER=root DB_PASS= php tests/setup_test_db.php
 */

declare(strict_types=1);

$host = getenv('DB_HOST') ?: 'localhost';
$name = getenv('DB_NAME') ?: 'ticket_test';
$user = getenv('DB_USER') ?: 'root';
$pass = getenv('DB_PASS') ?: '';

// Safety: refuse to recreate a database whose name doesn't look like a test DB.
if (stripos($name, 'test') === false) {
    fwrite(STDERR, "Refusing to operate on database \"{$name}\" – name must contain \"test\".\n");
    exit(1);
}

echo "Setting up test database '{$name}' on {$host} as '{$user}'...\n";

try {
    // Connect WITHOUT selecting a database so we can drop/create.
    $pdo = new PDO(
        "mysql:host={$host};charset=utf8mb4",
        $user,
        $pass,
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]
    );
} catch (PDOException $e) {
    fwrite(STDERR, "Could not connect to MySQL server: " . $e->getMessage() . "\n");
    exit(1);
}

// Drop + recreate so re-running yields a clean baseline that matches schema.sql.
$pdo->exec("DROP DATABASE IF EXISTS `{$name}`");
$pdo->exec("CREATE DATABASE `{$name}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
$pdo->exec("USE `{$name}`");

$schemaPath = __DIR__ . '/../database/schema.sql';
$sql = file_get_contents($schemaPath);
if ($sql === false) {
    fwrite(STDERR, "Could not read schema file: {$schemaPath}\n");
    exit(1);
}

// schema.sql is a single transactional batch – exec runs it whole.
$pdo->exec($sql);

// Verify the schema landed: check a known table.
$check = $pdo->query("SELECT COUNT(*) AS cnt FROM reservation_statuses")->fetch();
$statusCount = (int)($check['cnt'] ?? 0);

if ($statusCount === 0) {
    fwrite(STDERR, "Schema loaded but reservation_statuses is empty – something went wrong.\n");
    exit(1);
}

echo "  ✓ Database '{$name}' created\n";
echo "  ✓ Schema loaded from " . basename($schemaPath) . "\n";
echo "  ✓ {$statusCount} reservation status rows present\n";
echo "Done. Run 'php vendor/bin/phpunit' to execute the test suite.\n";
