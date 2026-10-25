<?php
/**
 * One-time migration: Alternativbelegung für Sitze (Ausweichspielstätte)
 *
 * Legt an:
 *   • seat_plan_elements.alt_row   – alternative Reihe je Sitz
 *   • seat_plan_elements.alt_seat  – alternativer Platz je Sitz
 *   • events.alt_location_name     – Name der Ausweichspielstätte
 *
 * Im Browser aufrufen (z. B. https://domain.de/migrate_alternative_seats.php),
 * danach DIESE DATEI LÖSCHEN!
 *
 * Der Aufruf ist gefahrlos wiederholbar – bereits vorhandene Spalten werden
 * übersprungen, Daten werden nicht verändert.
 */

header('Content-Type: text/html; charset=utf-8');

$envFile = __DIR__ . '/.env';
if (!file_exists($envFile)) {
    die('Error: .env file not found. Run setup.php first.');
}

// Read .env
$env = [];
foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
    $line = trim($line);
    if (empty($line) || strpos($line, '#') === 0 || strpos($line, '=') === false) {
        continue;
    }
    [$key, $val] = explode('=', $line, 2);
    $env[trim($key)] = trim($val);
}

/**
 * Spalte nur anlegen, wenn sie noch nicht existiert.
 * ("ADD COLUMN IF NOT EXISTS" kennt nur MariaDB, nicht MySQL.)
 */
function addColumnIfMissing(PDO $pdo, string $database, string $table, string $column, string $definition): string
{
    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = :db AND TABLE_NAME = :tbl AND COLUMN_NAME = :col"
    );
    $stmt->execute([':db' => $database, ':tbl' => $table, ':col' => $column]);

    if ((int)$stmt->fetchColumn() > 0) {
        return 'bereits vorhanden';
    }

    $pdo->exec("ALTER TABLE `{$table}` ADD COLUMN `{$column}` {$definition}");

    return 'angelegt';
}

echo '<!DOCTYPE html><html lang="de"><head><meta charset="utf-8">'
    . '<title>Migration: Alternativbelegung</title>'
    . '<style>body{font-family:sans-serif;max-width:760px;margin:40px auto;padding:0 16px;line-height:1.5}'
    . 'table{border-collapse:collapse;width:100%;margin:16px 0}'
    . 'th,td{border:1px solid #ccc;padding:8px;text-align:left}th{background:#f2f2f2}'
    . 'code{background:#f2f2f2;padding:1px 4px;border-radius:3px}'
    . '.ok{color:#157347}.skip{color:#6c757d}.err{color:#b02a37}</style></head><body>';
echo '<h1>Migration: Alternativbelegung für Sitze</h1>';

try {
    $pdo = new PDO(
        "mysql:host={$env['DB_HOST']};dbname={$env['DB_NAME']};charset=utf8mb4",
        $env['DB_USER'],
        $env['DB_PASS'] ?? '',
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    $database = $env['DB_NAME'];

    $steps = [
        ['seat_plan_elements', 'alt_row',           "varchar(100) DEFAULT NULL AFTER `table_seat_number`"],
        ['seat_plan_elements', 'alt_seat',          "varchar(50) DEFAULT NULL AFTER `alt_row`"],
        ['events',             'alt_location_name', "varchar(200) DEFAULT NULL AFTER `reservation_phone`"],
    ];

    echo '<table><tr><th>Tabelle</th><th>Spalte</th><th>Ergebnis</th></tr>';
    foreach ($steps as [$table, $column, $definition]) {
        $result = addColumnIfMissing($pdo, $database, $table, $column, $definition);
        $class = $result === 'angelegt' ? 'ok' : 'skip';
        echo '<tr><td><code>' . htmlspecialchars($table) . '</code></td>'
            . '<td><code>' . htmlspecialchars($column) . '</code></td>'
            . '<td class="' . $class . '">' . htmlspecialchars($result) . '</td></tr>';
    }
    echo '</table>';

    echo '<p class="ok"><strong>&#10003; Migration erfolgreich abgeschlossen.</strong></p>';
    echo '<p>Die Alternativplätze können jetzt im <strong>Sitzplan-Editor</strong> gepflegt '
        . 'oder über <strong>Excel-Import</strong> eingelesen werden. Der Name der Ausweichspielstätte '
        . 'wird unter <em>Veranstaltung bearbeiten &rarr; Alternativer Veranstaltungsort</em> gesetzt.</p>';
    echo '<p class="err"><strong>Bitte diese Datei jetzt löschen!</strong></p>';
} catch (PDOException $e) {
    echo '<p class="err">Fehler: ' . htmlspecialchars($e->getMessage()) . '</p>';
}

echo '</body></html>';
