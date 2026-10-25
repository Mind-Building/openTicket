<?php
/**
 * Sitzplan-Import (Excel/CSV)
 *
 * Importiert für bestehende Sitze die Alternativbelegung (Reihe/Platz in der
 * Ausweichspielstätte) und kann optional fehlende Sitze anlegen.
 *
 * Ablauf: 1. Datei hochladen -> 2. Spalten zuordnen + Vorschau -> 3. Importieren
 */

require_once __DIR__ . '/../../app/bootstrap.php';

use App\Core\Auth;
use App\Core\Csrf;
use App\Helpers\SpreadsheetReader;
use App\Models\Event;
use App\Models\SeatCategory;
use App\Models\SeatPlan;
use App\Services\LoggerService;
use App\Services\SeatPlanImporter;

Auth::requireAdmin();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !Csrf::validatePost()) {
    http_response_code(403);
    die('Ungültige Anfrage: Sicherheits-Token fehlt oder ist abgelaufen. Bitte die Seite neu laden und erneut versuchen.');
}

$eventModel = new Event();
$seatPlanModel = new SeatPlan();
$categoryModel = new SeatCategory();

$events = $eventModel->getAll();
$eventId = (int)($_POST['event_id'] ?? $_GET['event_id'] ?? 0);

$uploadDir = __DIR__ . '/../../storage/imports/';

// -----------------------------------------------------------------------
// Hilfsfunktionen
// -----------------------------------------------------------------------

/**
 * Entfernt Import-Dateien, die älter als eine Stunde sind.
 */
function cleanupImportFiles(string $dir): void
{
    foreach (glob($dir . 'seatimport_*') ?: [] as $file) {
        if (is_file($file) && filemtime($file) < time() - 3600) {
            @unlink($file);
        }
    }
}

/**
 * Findet die abgelegte Import-Datei zu einem Token.
 */
function findImportFile(string $dir, string $token): ?string
{
    if (!preg_match('/^[a-f0-9]{32}$/', $token)) {
        return null;
    }
    $matches = glob($dir . 'seatimport_' . $token . '.*') ?: [];
    return $matches[0] ?? null;
}

/**
 * Excel-Spaltenbuchstabe für einen 0-basierten Index ("A", "B", ..., "AA").
 */
function columnLetter(int $index): string
{
    $letter = '';
    $index++;
    while ($index > 0) {
        $remainder = ($index - 1) % 26;
        $letter = chr(65 + $remainder) . $letter;
        $index = (int)(($index - $remainder - 1) / 26);
    }
    return $letter;
}


// -----------------------------------------------------------------------
// Verarbeitung
// -----------------------------------------------------------------------

cleanupImportFiles($uploadDir);

$step = 'upload';
$error = null;
$analysis = null;   // Kopfzeile, Vorschau, Zuordnung
$result = null;     // Import-Ergebnis
$action = $_POST['action'] ?? '';

if ($action === 'analyze') {
    try {
        if ($eventId <= 0) {
            throw new RuntimeException('Bitte zuerst eine Veranstaltung auswählen.');
        }
        if (empty($_FILES['import_file']['tmp_name']) || $_FILES['import_file']['error'] !== UPLOAD_ERR_OK) {
            throw new RuntimeException('Bitte eine Excel- (.xlsx) oder CSV-Datei auswählen.');
        }

        $originalName = (string)$_FILES['import_file']['name'];
        $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        if (!in_array($extension, ['xlsx', 'xlsm', 'csv', 'txt'], true)) {
            throw new RuntimeException('Nicht unterstütztes Dateiformat. Erlaubt sind .xlsx und .csv.');
        }

        if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true) && !is_dir($uploadDir)) {
            throw new RuntimeException('Das Verzeichnis für Import-Dateien konnte nicht angelegt werden.');
        }

        $token = bin2hex(random_bytes(16));
        $storedPath = $uploadDir . 'seatimport_' . $token . '.' . $extension;
        if (!move_uploaded_file($_FILES['import_file']['tmp_name'], $storedPath)) {
            throw new RuntimeException('Die hochgeladene Datei konnte nicht gespeichert werden.');
        }

        $rows = SpreadsheetReader::read($storedPath, $originalName);
        if (count($rows) < 2) {
            @unlink($storedPath);
            throw new RuntimeException('Die Datei enthält keine auswertbaren Zeilen.');
        }

        $headerRowNumber = 1;
        $headers = $rows[0];
        $mapping = SeatPlanImporter::guessMapping($headers);

        $analysis = [
            'token'      => $token,
            'file_name'  => $originalName,
            'header_row' => $headerRowNumber,
            'headers'    => $headers,
            'preview'    => array_slice($rows, 1, 10),
            'total_rows' => count($rows) - 1,
            'mapping'    => $mapping,
        ];
        $step = 'mapping';
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
} elseif ($action === 'import') {
    try {
        if ($eventId <= 0) {
            throw new RuntimeException('Keine Veranstaltung ausgewählt.');
        }

        $token = (string)($_POST['token'] ?? '');
        $path = findImportFile($uploadDir, $token);
        if ($path === null || !is_file($path)) {
            throw new RuntimeException('Die hochgeladene Datei ist nicht mehr verfügbar. Bitte erneut hochladen.');
        }

        $mapping = [
            'row'      => ($_POST['col_row'] ?? '') === '' ? null : (int)$_POST['col_row'],
            'seat'     => ($_POST['col_seat'] ?? '') === '' ? null : (int)$_POST['col_seat'],
            'alt_row'  => ($_POST['col_alt_row'] ?? '') === '' ? null : (int)$_POST['col_alt_row'],
            'alt_seat' => ($_POST['col_alt_seat'] ?? '') === '' ? null : (int)$_POST['col_alt_seat'],
            'table'    => ($_POST['col_table'] ?? '') === '' ? null : (int)$_POST['col_table'],
            'category' => ($_POST['col_category'] ?? '') === '' ? null : (int)$_POST['col_category'],
        ];

        $options = [
            // 0 = die Datei hat keine Kopfzeile, die Daten beginnen in Zeile 1
            'header_row'        => max(0, (int)($_POST['header_row'] ?? 1)),
            'create_missing'    => !empty($_POST['create_missing']),
            'clear_existing'    => !empty($_POST['clear_existing']),
            'new_seat_category' => ($_POST['new_seat_category'] ?? '') === '' ? null : (int)$_POST['new_seat_category'],
        ];

        $rows = SpreadsheetReader::read($path, basename($path));
        $result = (new SeatPlanImporter($seatPlanModel, $categoryModel))->import($eventId, $rows, $mapping, $options);

        @unlink($path);
        $step = 'result';

        LoggerService::logAdmin('seatplan_alt_imported', [
            'event_id'  => $eventId,
            'entries'   => $result['total_entries'],
            'updated'   => $result['updated'],
            'created'   => $result['created'],
            'not_found' => count($result['not_found']),
        ]);
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

// -----------------------------------------------------------------------
// Ausgabe
// -----------------------------------------------------------------------

$event = $eventId > 0 ? $eventModel->getById($eventId) : null;
$categories = $eventId > 0 ? $categoryModel->getByEvent($eventId) : [];

$pageTitle = 'Sitzplan-Import';
$showSidebar = true;
$showHeader = true;

$content = '
<div class="d-flex justify-content-between align-items-center mb-4">
    <h2><i class="bi bi-file-earmark-spreadsheet"></i> Sitzplan-Import (Alternativplätze)</h2>
    <a href="' . url('admin/seatplan.php?event_id=' . $eventId) . '" class="btn btn-outline-secondary">
        <i class="bi bi-arrow-left"></i> Zum Sitzplan-Editor
    </a>
</div>';

if ($error) {
    $content .= '<div class="alert alert-danger"><i class="bi bi-exclamation-triangle"></i> ' . htmlspecialchars($error) . '</div>';
}

if ($step === 'result' && $result) {
    $content .= '
    <div class="card mb-4">
        <div class="card-header"><h5 class="mb-0">Import abgeschlossen</h5></div>
        <div class="card-body">
            <div class="row g-3 mb-3">
                <div class="col-6 col-md-3"><div class="border rounded p-3 text-center">
                    <div class="fs-4 fw-bold">' . $result['updated'] . '</div><div class="small text-muted">aktualisiert</div>
                </div></div>
                <div class="col-6 col-md-3"><div class="border rounded p-3 text-center">
                    <div class="fs-4 fw-bold">' . $result['created'] . '</div><div class="small text-muted">neu angelegt</div>
                </div></div>
                <div class="col-6 col-md-3"><div class="border rounded p-3 text-center">
                    <div class="fs-4 fw-bold">' . $result['unchanged'] . '</div><div class="small text-muted">unverändert</div>
                </div></div>
                <div class="col-6 col-md-3"><div class="border rounded p-3 text-center">
                    <div class="fs-4 fw-bold">' . count($result['not_found']) . '</div><div class="small text-muted">nicht gefunden</div>
                </div></div>
            </div>
            <p class="text-muted small mb-0">
                ' . $result['total_entries'] . ' eindeutige Plätze in der Datei gefunden.'
                . ($result['categories_set'] > 0 ? ' Bei ' . $result['categories_set'] . ' Plätzen wurde die Kategorie gesetzt.' : '')
                . ($result['cleared'] > 0 ? ' ' . $result['cleared'] . ' bestehende Alternativbelegungen wurden vorher entfernt.' : '') . '
            </p>
        </div>
    </div>';

    if (!empty($result['not_found'])) {
        $sample = array_slice($result['not_found'], 0, 30);
        $content .= '
        <div class="alert alert-warning">
            <strong>Nicht im Sitzplan gefunden (' . count($result['not_found']) . '):</strong><br>
            <span class="small">' . htmlspecialchars(implode(' · ', $sample))
            . (count($result['not_found']) > 30 ? ' …' : '') . '</span>
        </div>';
    }

    if (!empty($result['unknown_categories'])) {
        $unknownList = [];
        foreach ($result['unknown_categories'] as $token => $count) {
            $unknownList[] = $token . ' (' . $count . 'x)';
        }
        $content .= '
        <div class="alert alert-warning">
            <strong>Unbekannte Kategorien in der Datei:</strong>
            <span class="small">' . htmlspecialchars(implode(' · ', array_slice($unknownList, 0, 20))) . '</span><br>
            <span class="small text-muted">Diese Plätze wurden ohne Änderung der Kategorie importiert.
            Prüfen Sie das Zeichen der Kategorien in der Kategorieverwaltung.</span>
        </div>';
    }

    if (!empty($result['conflicts'])) {
        $content .= '<div class="alert alert-warning"><strong>Widersprüchliche Angaben (' . count($result['conflicts']) . '):</strong><ul class="small mb-0">';
        foreach (array_slice($result['conflicts'], 0, 15) as $conflict) {
            $content .= '<li>Zeile ' . $conflict['line'] . ' – ' . htmlspecialchars($conflict['label'])
                . ': übernommen "' . htmlspecialchars($conflict['kept']) . '", ignoriert "' . htmlspecialchars($conflict['other']) . '"</li>';
        }
        $content .= '</ul></div>';
    }

    if (!empty($result['invalid'])) {
        $content .= '<div class="alert alert-secondary"><strong>Übersprungene Zeilen (' . count($result['invalid']) . '):</strong><ul class="small mb-0">';
        foreach (array_slice($result['invalid'], 0, 15) as $invalid) {
            $content .= '<li>Zeile ' . $invalid['line'] . ' – ' . htmlspecialchars($invalid['reason']) . '</li>';
        }
        $content .= '</ul></div>';
    }

    $content .= '
    <a href="' . url('admin/seatplan.php?event_id=' . $eventId) . '" class="btn btn-primary">
        <i class="bi bi-grid"></i> Sitzplan prüfen
    </a>
    <a href="' . url('admin/seatplan_import.php?event_id=' . $eventId) . '" class="btn btn-outline-secondary">
        <i class="bi bi-arrow-repeat"></i> Weitere Datei importieren
    </a>';
} elseif ($step === 'mapping' && $analysis) {
    $columnOptions = static function (?int $selected) use ($analysis): string {
        $html = '<option value="">-- keine --</option>';
        foreach ($analysis['headers'] as $index => $header) {
            $label = trim((string)$header);
            if ($label === '') {
                $label = 'Spalte ' . ($index + 1);
            }
            $html .= '<option value="' . $index . '"' . ($selected === $index ? ' selected' : '') . '>'
                . htmlspecialchars(columnLetter($index) . ': ' . $label) . '</option>';
        }
        return $html;
    };

    $categoryOptions = '<option value="">-- keine Kategorie --</option>';
    foreach ($categories as $category) {
        $categoryOptions .= '<option value="' . (int)$category['id'] . '">'
            . htmlspecialchars($category['sign'] . ' – ' . $category['name']) . '</option>';
    }

    $content .= '
    <form method="POST">
        ' . Csrf::field() . '
        <input type="hidden" name="action" value="import">
        <input type="hidden" name="event_id" value="' . $eventId . '">
        <input type="hidden" name="token" value="' . htmlspecialchars($analysis['token']) . '">

        <div class="card mb-4">
            <div class="card-header"><h5 class="mb-0">Spalten zuordnen</h5></div>
            <div class="card-body">
                <p class="text-muted small">
                    Datei: <strong>' . htmlspecialchars($analysis['file_name']) . '</strong> ·
                    ' . (int)$analysis['total_rows'] . ' Datenzeilen ·
                    Veranstaltung: <strong>' . htmlspecialchars($event['name'] ?? '') . '</strong><br>
                    Mehrfach vorkommende Plätze (z. B. eine Zeile je Vorstellung) werden automatisch zusammengefasst.
                </p>
                <div class="row g-3">
                    <div class="col-md-4">
                        <label class="form-label" for="header_row">Kopfzeile in Zeile</label>
                        <input type="number" min="0" class="form-control" id="header_row" name="header_row"
                               value="' . (int)$analysis['header_row'] . '">
                        <div class="form-text">0 = die Datei hat keine Kopfzeile.</div>
                    </div>
                    <div class="col-md-8"></div>
                    <div class="col-md-4">
                        <label class="form-label">Reihe (Original)</label>
                        <select class="form-select" name="col_row">' . $columnOptions($analysis['mapping']['row']) . '</select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Platz (Original) <span class="text-danger">*</span></label>
                        <select class="form-select" name="col_seat">' . $columnOptions($analysis['mapping']['seat']) . '</select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Tisch (optional)</label>
                        <select class="form-select" name="col_table">' . $columnOptions($analysis['mapping']['table']) . '</select>
                        <div class="form-text">Nur bei Tischplätzen zuordnen – ersetzt dann die Reihe.</div>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Alternative Reihe</label>
                        <select class="form-select" name="col_alt_row">' . $columnOptions($analysis['mapping']['alt_row']) . '</select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Alternativer Platz</label>
                        <select class="form-select" name="col_alt_seat">' . $columnOptions($analysis['mapping']['alt_seat']) . '</select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Kategorie (optional)</label>
                        <select class="form-select" name="col_category">' . $columnOptions($analysis['mapping']['category']) . '</select>
                        <div class="form-text">
                            Abgleich über das Zeichen der angelegten Kategorie (z. B. „Kat. I." &rarr; Zeichen „I").
                            Preise werden nicht importiert – sie kommen aus der Kategorie.
                        </div>
                    </div>
                </div>

                <hr>

                <div class="form-check">
                    <input class="form-check-input" type="checkbox" name="clear_existing" id="clear_existing" value="1">
                    <label class="form-check-label" for="clear_existing">
                        Bestehende Alternativbelegungen dieser Veranstaltung vorher löschen
                    </label>
                </div>
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" name="create_missing" id="create_missing" value="1">
                    <label class="form-check-label" for="create_missing">
                        Fehlende Reihen/Plätze anlegen
                        <span class="text-muted small">(Tischplätze werden nie automatisch angelegt)</span>
                    </label>
                </div>
                <div class="mt-3" style="max-width: 400px;">
                    <label class="form-label">Kategorie für neu angelegte Plätze</label>
                    <select class="form-select" name="new_seat_category">' . $categoryOptions . '</select>
                </div>
            </div>
        </div>

        <div class="card mb-4">
            <div class="card-header"><h5 class="mb-0">Vorschau</h5></div>
            <div class="card-body p-0" style="overflow-x: auto;">
                <table class="table table-sm table-bordered mb-0">
                    <thead class="table-light"><tr>';

    foreach ($analysis['headers'] as $index => $header) {
        $content .= '<th class="small">' . htmlspecialchars(columnLetter($index) . ': ' . trim((string)$header)) . '</th>';
    }

    $content .= '</tr></thead><tbody>';

    foreach ($analysis['preview'] as $previewRow) {
        $content .= '<tr>';
        foreach ($analysis['headers'] as $index => $header) {
            $content .= '<td class="small">' . htmlspecialchars((string)($previewRow[$index] ?? '')) . '</td>';
        }
        $content .= '</tr>';
    }

    $content .= '
                    </tbody>
                </table>
            </div>
        </div>

        <button type="submit" class="btn btn-primary">
            <i class="bi bi-upload"></i> Import starten
        </button>
        <a href="' . url('admin/seatplan_import.php?event_id=' . $eventId) . '" class="btn btn-outline-secondary">Abbrechen</a>
    </form>';
} else {
    $eventOptions = '<option value="">-- Bitte wählen --</option>';
    foreach ($events as $item) {
        $eventOptions .= '<option value="' . (int)$item['id'] . '"' . ($eventId === (int)$item['id'] ? ' selected' : '') . '>'
            . htmlspecialchars($item['name']) . '</option>';
    }

    $content .= '
    <div class="row">
        <div class="col-lg-7">
            <div class="card">
                <div class="card-header"><h5 class="mb-0">Datei hochladen</h5></div>
                <div class="card-body">
                    <form method="POST" enctype="multipart/form-data">
                        ' . Csrf::field() . '
                        <input type="hidden" name="action" value="analyze">
                        <div class="mb-3">
                            <label class="form-label" for="event_id">Veranstaltung</label>
                            <select class="form-select" id="event_id" name="event_id" required>' . $eventOptions . '</select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label" for="import_file">Excel- oder CSV-Datei</label>
                            <input type="file" class="form-control" id="import_file" name="import_file"
                                   accept=".xlsx,.xlsm,.csv,.txt" required>
                            <div class="form-text">
                                Unterstützt: .xlsx (Excel), .csv · Maximale Dateigröße: ' . ini_get('upload_max_filesize') . '
                            </div>
                        </div>
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-search"></i> Datei einlesen
                        </button>
                    </form>
                </div>
            </div>
        </div>
        <div class="col-lg-5">
            <div class="card bg-light border-0">
                <div class="card-body">
                    <h6><i class="bi bi-info-circle text-info"></i> So funktioniert der Import</h6>
                    <ol class="small mb-3">
                        <li>Datei hochladen – die Kopfzeile muss in Zeile 1 stehen.</li>
                        <li>Spalten zuordnen (Reihe/Platz original und alternativ). Die Zuordnung wird
                            anhand der Spaltenüberschriften vorgeschlagen.</li>
                        <li>Import starten – die Alternativbelegung wird den vorhandenen Plätzen zugeordnet.</li>
                    </ol>
                    <h6><i class="bi bi-table"></i> Beispiel</h6>
                    <table class="table table-sm table-bordered small mb-0 bg-white">
                        <thead><tr><th>Reihe</th><th>Platz</th><th>Reihe (alt.)</th><th>Platz (alt.)</th></tr></thead>
                        <tbody>
                            <tr><td>Reihe 1</td><td>Platz 13</td><td>Reihe 1</td><td>Platz 1</td></tr>
                            <tr><td>Reihe 1</td><td>Platz 14</td><td>Reihe 1</td><td>Platz 2</td></tr>
                        </tbody>
                    </table>
                    <p class="small text-muted mt-2 mb-0">
                        Zusätzliche Spalten (Kategorie, Preis, Datum, …) werden ignoriert. Kommt ein Platz
                        mehrfach vor – etwa je Vorstellung – zählt er nur einmal.
                    </p>
                </div>
            </div>
        </div>
    </div>';
}

require __DIR__ . '/../../app/views/layouts/main.php';
