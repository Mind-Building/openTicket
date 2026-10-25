<?php
/**
 * One-time migration: Make users.email column nullable
 * Run once, then DELETE this file!
 */

$envFile = __DIR__ . '/.env';
if (!file_exists($envFile)) {
    die('Error: .env file not found. Run setup.php first.');
}

// Read .env
$env = [];
foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
    $line = trim($line);
    if (empty($line) || strpos($line, '#') === 0 || strpos($line, '=') === false) continue;
    [$key, $val] = explode('=', $line, 2);
    $env[trim($key)] = trim($val);
}

try {
    $pdo = new PDO(
        "mysql:host={$env['DB_HOST']};dbname={$env['DB_NAME']};charset=utf8mb4",
        $env['DB_USER'],
        $env['DB_PASS'] ?? '',
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    $pdo->exec("ALTER TABLE `users` MODIFY `email` varchar(200) DEFAULT NULL");

    echo "<p style='color:green;font-family:sans-serif'>✓ Migration erfolgreich: users.email ist jetzt optional (NULL erlaubt).</p>";
    echo "<p style='color:red;font-family:sans-serif'><strong>Bitte diese Datei jetzt löschen!</strong></p>";

} catch (PDOException $e) {
    echo "<p style='color:red;font-family:sans-serif'>Fehler: " . htmlspecialchars($e->getMessage()) . "</p>";
}
