<?php
/**
 * Datenbank-Update (Admin)
 *
 * Zeigt, welche Datenbank-Migrationen in dieser Installation fehlen, und spielt sie auf
 * Knopfdruck ein – für Server ohne Kommandozeile. Gleiche Logik wie
 * database/update_database.php (App\Services\DatabaseMigrator).
 */
require_once __DIR__ . '/../../app/bootstrap.php';

use App\Core\Auth;
use App\Core\Csrf;
use App\Services\DatabaseMigrator;
use App\Services\LoggerService;

Auth::requireAdmin();

$error = '';
$runLog = null;

try {
    $migrator = new DatabaseMigrator();
} catch (\Throwable $e) {
    $migrator = null;
    $error = 'Keine Verbindung zur Datenbank: ' . $e->getMessage();
}

if ($migrator && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['run_migrations'])) {
    if (!Csrf::validatePost()) {
        $error = 'Ungültige Anfrage. Bitte die Seite neu laden.';
    } else {
        $runLog = $migrator->run();
        LoggerService::logAdmin('database_update', [
            'username' => Auth::username(),
            'database' => $migrator->databaseName(),
            'result' => array_map(fn($e) => $e['id'] . ':' . ($e['ok'] ? 'ok' : 'error'), $runLog),
        ], array_filter($runLog, fn($e) => !$e['ok']) ? 'error' : 'info');
    }
}

$status = $migrator ? $migrator->status() : [];
$pendingCount = count(array_filter($status, fn($m) => !$m['applied']));

$pageTitle = 'Datenbank-Update';
$showSidebar = true;
$showHeader = true;

$content = '
<div class="d-flex justify-content-between align-items-center mb-4">
    <h2>Datenbank-Update</h2>
    <a href="' . url('admin/settings.php') . '" class="btn btn-outline-secondary"><i class="bi bi-arrow-left"></i> Einstellungen</a>
</div>';

if ($error) {
    $content .= '<div class="alert alert-danger">' . htmlspecialchars($error) . '</div>';
}

if ($runLog !== null) {
    $failed = array_filter($runLog, fn($e) => !$e['ok']);
    $content .= '<div class="alert ' . ($failed ? 'alert-danger' : 'alert-success') . '">'
        . ($runLog ? '' : 'Es war nichts zu tun. ')
        . ($failed ? '<strong>Abgebrochen.</strong> Weitere Migrationen wurden nicht ausgeführt.' : ($runLog ? '<strong>Fertig.</strong> Alle ausstehenden Migrationen wurden eingespielt.' : ''))
        . '<ul class="mb-0 mt-2">';
    foreach ($runLog as $entry) {
        $content .= '<li>' . ($entry['ok'] ? '✔' : '✘') . ' ' . htmlspecialchars($entry['description'])
            . ' – ' . htmlspecialchars($entry['message']) . '</li>';
    }
    $content .= '</ul></div>';
}

if ($migrator) {
    $rows = '';
    foreach ($status as $migration) {
        $rows .= '<tr>'
            . '<td>' . ($migration['applied']
                ? '<span class="badge bg-success">eingespielt</span>'
                : '<span class="badge bg-warning text-dark">ausstehend</span>') . '</td>'
            . '<td>' . htmlspecialchars($migration['description']) . '</td>'
            . '<td><code>database/' . htmlspecialchars($migration['file']) . '</code></td>'
            . '</tr>';
    }

    $content .= '
<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span>Datenbank <code>' . htmlspecialchars($migrator->databaseName()) . '</code></span>
        ' . ($pendingCount
            ? '<span class="badge bg-warning text-dark">' . $pendingCount . ' ausstehend</span>'
            : '<span class="badge bg-success">aktuell</span>') . '
    </div>
    <div class="card-body">
        <p class="text-muted small">
            Nach einem Update der Programmdateien prüft diese Seite, welche Änderungen an der Datenbank noch fehlen.
            Bereits vorhandene Änderungen (auch von Hand eingespielte) werden erkannt und übersprungen; das Einspielen kann
            gefahrlos wiederholt werden. Vorher empfiehlt sich eine Sicherung der Datenbank.
        </p>
        <table class="table table-sm align-middle">
            <thead><tr><th style="width: 130px;">Stand</th><th>Änderung</th><th>Datei</th></tr></thead>
            <tbody>' . $rows . '</tbody>
        </table>
        ' . ($pendingCount ? '
        <form method="POST" onsubmit="return confirm(\'Ausstehende Datenbank-Änderungen jetzt einspielen?\');">
            ' . Csrf::field() . '
            <button type="submit" name="run_migrations" value="1" class="btn btn-primary">
                <i class="bi bi-database-up"></i> ' . $pendingCount . ' ausstehende Änderung(en) einspielen
            </button>
        </form>' : '<p class="mb-0 text-success"><i class="bi bi-check-circle"></i> Die Datenbank ist auf dem aktuellen Stand.</p>') . '
        <p class="text-muted small mt-3 mb-0">Alternativ per Kommandozeile im Installationsverzeichnis: <code>php database/update_database.php</code></p>
    </div>
</div>';
}

require __DIR__ . '/../../app/views/layouts/main.php';
