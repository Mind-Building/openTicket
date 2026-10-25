<?php
/**
 * One-time migration: Create device_tokens table for presales terminal authentication
 * Run once on existing installations, then DELETE this file!
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

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `device_tokens` (
          `id` int(11) NOT NULL AUTO_INCREMENT,
          `token` varchar(64) NOT NULL,
          `user_id` int(11) NOT NULL,
          `label` varchar(200) NOT NULL DEFAULT '',
          `expires_at` datetime DEFAULT NULL,
          `last_used_at` datetime DEFAULT NULL,
          `created_at` datetime NOT NULL DEFAULT current_timestamp(),
          `revoked_at` datetime DEFAULT NULL,
          PRIMARY KEY (`id`),
          UNIQUE KEY `token` (`token`),
          KEY `user_id` (`user_id`),
          CONSTRAINT `fk_device_tokens_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    echo "<p style='color:green;font-family:sans-serif'>&#10003; Migration erfolgreich: Tabelle <code>device_tokens</code> wurde erstellt (oder war bereits vorhanden).</p>";
    echo "<p style='color:red;font-family:sans-serif'><strong>Bitte diese Datei jetzt löschen!</strong></p>";

} catch (PDOException $e) {
    echo "<p style='color:red;font-family:sans-serif'>Fehler: " . htmlspecialchars($e->getMessage()) . "</p>";
}
