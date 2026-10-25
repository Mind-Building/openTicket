<?php
/**
 * Datenbank-Update für eine Installation (Kommandozeile)
 *
 * Prüft, welche Datenbank-Migrationen in der Datenbank dieser Installation (laut .env) noch
 * fehlen, und spielt sie in der richtigen Reihenfolge ein. Kann gefahrlos mehrfach ausgeführt
 * werden – bereits vorhandene Änderungen werden erkannt und übersprungen.
 *
 *   php database/update_database.php           Fehlende Migrationen einspielen
 *   php database/update_database.php --check   Nur anzeigen, nichts ändern
 *
 * Ohne Kommandozeile: im Admin-Bereich unter "Datenbank-Update" (admin/database_update.php).
 * Vor dem Einspielen empfiehlt sich eine Sicherung der Datenbank.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Nur über die Kommandozeile ausführbar.\n");
}

require_once __DIR__ . '/../app/bootstrap.php';

use App\Services\DatabaseMigrator;

$checkOnly = in_array('--check', $argv, true);

try {
    $migrator = new DatabaseMigrator();
} catch (\Throwable $e) {
    fwrite(STDERR, "Keine Verbindung zur Datenbank: " . $e->getMessage() . "\n");
    exit(2);
}

echo "Datenbank: " . $migrator->databaseName() . "\n\n";

$status = $migrator->status();
foreach ($status as $migration) {
    printf("  [%s] %-20s %s\n", $migration['applied'] ? 'ok' : '--', $migration['id'], $migration['description']);
}

$pending = array_filter($status, fn($m) => !$m['applied']);
echo "\n";
if (!$pending) {
    echo "Die Datenbank ist auf dem aktuellen Stand.\n";
    exit(0);
}

echo count($pending) . " Migration(en) ausstehend.\n";
if ($checkOnly) {
    echo "Nur geprüft (--check), nichts geändert.\n";
    exit(1);
}

echo "\nSpiele ein ...\n";
$failed = false;
foreach ($migrator->run() as $entry) {
    printf("  [%s] %-20s %s\n", $entry['ok'] ? 'ok' : 'FEHLER', $entry['id'], $entry['message']);
    $failed = $failed || !$entry['ok'];
}

if ($failed) {
    echo "\nAbgebrochen. Weitere Migrationen wurden nicht ausgeführt.\n";
    exit(2);
}
echo "\nFertig – die Datenbank ist auf dem aktuellen Stand.\n";
exit(0);
