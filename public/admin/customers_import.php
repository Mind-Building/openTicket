<?php
require_once __DIR__ . '/../../app/bootstrap.php';

use App\Core\Auth;
use App\Models\Customer;
use App\Services\LoggerService;

Auth::requireAdmin();

// ── CSV-Vorlage herunterladen ────────────────────────────────────────────────
if (isset($_GET['template'])) {
    // Alle bisherigen Ausgaben (z.B. PHP-Warnungen aus Bootstrap) verwerfen
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="kunden_vorlage.csv"');
    header('Cache-Control: no-cache, no-store, must-revalidate');
    echo "\xEF\xBB\xBF"; // UTF-8 BOM (für Excel)
    $out = fopen('php://output', 'w');
    fputcsv($out, ['anrede', 'vorname', 'nachname', 'strasse', 'hausnummer', 'postleitzahl', 'ort', 'email', 'telefon_vorwahl', 'telefonnummer', 'typ'], ';');
    fputcsv($out, ['Herr', 'Max', 'Mustermann', 'Musterstraße', '1', '12345', 'Musterstadt', 'max@example.com', '+49', '1234567890', 'ticket'], ';');
    fputcsv($out, ['Frau', 'Erika', 'Musterfrau', 'Beispielweg', '42b', '54321', 'Beispielort', 'erika@example.com', '', '', 'ticket'], ';');
    fclose($out);
    exit;
}

// ── CSV-Import verarbeiten ───────────────────────────────────────────────────
$importResults = null;

// CSRF-Schutz für alle POST-Aktionen dieser Seite
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !\App\Core\Csrf::validatePost()) {
    http_response_code(403);
    die('Ungültige Anfrage: Sicherheits-Token fehlt oder ist abgelaufen. Bitte die Seite neu laden und erneut versuchen.');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (empty($_FILES['csv_file']['tmp_name']) || $_FILES['csv_file']['error'] !== UPLOAD_ERR_OK) {
        $importResults = ['error' => 'Bitte eine CSV-Datei auswählen.'];
    } else {
        $file = $_FILES['csv_file']['tmp_name'];
        $handle = fopen($file, 'r');

        if (!$handle) {
            $importResults = ['error' => 'Die Datei konnte nicht geöffnet werden.'];
        } else {
            // BOM entfernen falls vorhanden
            $bom = fread($handle, 3);
            if ($bom !== "\xEF\xBB\xBF") {
                rewind($handle);
            }

            // Delimiter automatisch erkennen (Semikolon oder Komma)
            $firstLine = fgets($handle);
            rewind($handle);
            if ($bom === "\xEF\xBB\xBF") {
                fread($handle, 3); // BOM erneut überspringen
            }
            $delimiter = (substr_count($firstLine, ';') >= substr_count($firstLine, ',')) ? ';' : ',';

            // Header-Zeile einlesen
            $headers = fgetcsv($handle, 0, $delimiter, '"', '');
            if (!$headers) {
                $importResults = ['error' => 'Die CSV-Datei enthält keine Kopfzeile.'];
                fclose($handle);
            } else {
                // Header normalisieren (Leerzeichen, BOM-Rest, Groß-/Kleinschreibung)
                $headers = array_map(fn($h) => strtolower(trim(preg_replace('/^\xEF\xBB\xBF/', '', $h))), $headers);

                // Spaltenmapping: CSV-Spalte → DB-Feld
                $columnMap = [
                    'anrede'          => 'salutation',
                    'salutation'      => 'salutation',
                    'vorname'         => 'first_name',
                    'first_name'      => 'first_name',
                    'nachname'        => 'last_name',
                    'last_name'       => 'last_name',
                    'strasse'         => 'street',
                    'straße'          => 'street',
                    'street'          => 'street',
                    'hausnummer'      => 'house_number',
                    'house_number'    => 'house_number',
                    'postleitzahl'    => 'postal_code',
                    'plz'             => 'postal_code',
                    'postal_code'     => 'postal_code',
                    'ort'             => 'city',
                    'city'            => 'city',
                    'stadt'           => 'city',
                    'email'           => 'email',
                    'e-mail'          => 'email',
                    'telefon_vorwahl' => 'phone_prefix',
                    'phone_prefix'    => 'phone_prefix',
                    'vorwahl'         => 'phone_prefix',
                    'telefonnummer'   => 'phone_number',
                    'phone_number'    => 'phone_number',
                    'telefon'         => 'phone_number',
                    'typ'             => 'item_type',
                    'item_type'       => 'item_type',
                    'type'            => 'item_type',
                ];

                $required = ['first_name', 'last_name', 'street', 'house_number', 'postal_code', 'city', 'email'];

                $customerModel = new Customer();
                $counters = ['imported' => 0, 'updated' => 0, 'skipped' => 0];
                $rowErrors = [];
                $rowNum = 1;
                $importSessionId = 'csv_import_' . time();

                while (($row = fgetcsv($handle, 0, $delimiter, '"', '')) !== false) {
                    $rowNum++;

                    // Leere Zeilen überspringen
                    if (empty(array_filter($row))) {
                        continue;
                    }

                    // Werte auf DB-Felder mappen
                    $data = [];
                    foreach ($headers as $colIdx => $colName) {
                        $dbField = $columnMap[$colName] ?? null;
                        if ($dbField) {
                            $data[$dbField] = isset($row[$colIdx]) ? trim($row[$colIdx]) : '';
                        }
                    }

                    // Pflichtfelder prüfen
                    $missing = [];
                    foreach ($required as $field) {
                        if (empty($data[$field])) {
                            $missing[] = $field;
                        }
                    }
                    if (!empty($missing)) {
                        $rowErrors[] = "Zeile {$rowNum}: Pflichtfelder fehlen – " . implode(', ', $missing);
                        $counters['skipped']++;
                        continue;
                    }

                    // E-Mail-Format prüfen
                    if (!filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
                        $rowErrors[] = "Zeile {$rowNum}: Ungültige E-Mail-Adresse – " . htmlspecialchars($data['email']);
                        $counters['skipped']++;
                        continue;
                    }

                    // item_type normalisieren
                    $data['item_type'] = in_array(strtolower($data['item_type'] ?? ''), ['voucher', 'gutschein'])
                        ? 'voucher'
                        : 'ticket';

                    $data['session_id'] = $importSessionId;

                    // Duplikat prüfen und speichern
                    $existingId = $customerModel->findExisting($data);
                    if ($existingId !== null) {
                        $customerModel->update($existingId, $data);
                        $counters['updated']++;
                    } else {
                        $customerModel->create($data);
                        $counters['imported']++;
                    }
                }

                fclose($handle);

                LoggerService::logAdmin('customers_csv_imported', [
                    'imported'      => $counters['imported'],
                    'updated'       => $counters['updated'],
                    'skipped'       => $counters['skipped'],
                    'admin_user_id' => $_SESSION['user_id'] ?? null,
                ]);

                $importResults = [
                    'counters'  => $counters,
                    'rowErrors' => $rowErrors,
                ];
            }
        }
    }
}

// ── Seite aufbauen ───────────────────────────────────────────────────────────
$pageTitle = 'Kunden importieren';
$showSidebar = true;
$showHeader  = true;

// ── Ergebnis-HTML ────────────────────────────────────────────────────────────
$resultsHtml = '';
if ($importResults !== null) {
    if (isset($importResults['error'])) {
        $resultsHtml = '<div class="alert alert-danger"><i class="bi bi-exclamation-triangle"></i> ' . htmlspecialchars($importResults['error']) . '</div>';
    } else {
        $c = $importResults['counters'];
        $resultsHtml = '
        <div class="alert alert-success">
            <strong><i class="bi bi-check-circle"></i> Import abgeschlossen</strong><br>
            <span class="badge bg-success">' . $c['imported'] . ' neu importiert</span>
            <span class="badge bg-warning text-dark ms-1">' . $c['updated'] . ' aktualisiert</span>
            <span class="badge bg-secondary ms-1">' . $c['skipped'] . ' übersprungen</span>
        </div>';
        if (!empty($importResults['rowErrors'])) {
            $resultsHtml .= '<div class="alert alert-warning"><strong>Hinweise zu übersprungenen Zeilen:</strong><ul class="mb-0 mt-1">';
            foreach ($importResults['rowErrors'] as $err) {
                $resultsHtml .= '<li>' . htmlspecialchars($err) . '</li>';
            }
            $resultsHtml .= '</ul></div>';
        }
    }
}

$content = '
<div class="card mb-4">
    <div class="card-header d-flex justify-content-between align-items-center">
        <h5 class="card-title mb-0"><i class="bi bi-upload"></i> Kunden importieren (CSV)</h5>
        <a href="' . url('admin/customers.php') . '" class="btn btn-sm btn-outline-secondary">
            <i class="bi bi-arrow-left"></i> Zurück zur Kundenliste
        </a>
    </div>
    <div class="card-body">

        ' . $resultsHtml . '

        <div class="row g-4">

            <!-- Vorlage herunterladen -->
            <div class="col-md-4">
                <div class="card h-100 border-0 bg-light">
                    <div class="card-body">
                        <h6 class="card-title"><i class="bi bi-file-earmark-arrow-down text-primary"></i> Schritt 1: Vorlage herunterladen</h6>
                        <p class="card-text small text-muted">Lade die CSV-Vorlage herunter und fülle sie mit deinen Kundendaten. Die Datei ist für Excel (Semikolon-getrennt, UTF-8) optimiert.</p>
                        <a href="' . url('admin/customers_import.php') . '?template=1" class="btn btn-outline-primary btn-sm">
                            <i class="bi bi-download"></i> CSV-Vorlage herunterladen
                        </a>
                    </div>
                </div>
            </div>

            <!-- Spalten-Infos -->
            <div class="col-md-4">
                <div class="card h-100 border-0 bg-light">
                    <div class="card-body">
                        <h6 class="card-title"><i class="bi bi-info-circle text-info"></i> Schritt 2: Pflichtfelder</h6>
                        <ul class="small mb-0">
                            <li><strong>vorname</strong>, <strong>nachname</strong></li>
                            <li><strong>strasse</strong>, <strong>hausnummer</strong></li>
                            <li><strong>postleitzahl</strong>, <strong>ort</strong></li>
                            <li><strong>email</strong></li>
                        </ul>
                        <p class="small text-muted mt-2 mb-0">Optional: <em>anrede, telefon_vorwahl, telefonnummer, typ</em> (<code>ticket</code> oder <code>gutschein</code>)</p>
                    </div>
                </div>
            </div>

            <!-- Hinweise -->
            <div class="col-md-4">
                <div class="card h-100 border-0 bg-light">
                    <div class="card-body">
                        <h6 class="card-title"><i class="bi bi-lightbulb text-warning"></i> Hinweise</h6>
                        <ul class="small mb-0">
                            <li>Trennzeichen: Semikolon <code>;</code> oder Komma <code>,</code></li>
                            <li>Encoding: UTF-8 (empfohlen)</li>
                            <li>Bereits vorhandene Kunden werden aktualisiert (Abgleich per E-Mail + Adresse)</li>
                            <li>Zeilen mit fehlenden Pflichtfeldern werden übersprungen</li>
                        </ul>
                    </div>
                </div>
            </div>

        </div>

        <!-- Upload-Formular -->
        <form method="POST" enctype="multipart/form-data" class="mt-4">
            ' . \App\Core\Csrf::field() . '
            <h6><i class="bi bi-cloud-upload"></i> Schritt 3: CSV-Datei hochladen</h6>
            <div class="input-group" style="max-width: 500px;">
                <input type="file" class="form-control" name="csv_file" accept=".csv,text/csv" required>
                <button type="submit" class="btn btn-primary">
                    <i class="bi bi-upload"></i> Importieren
                </button>
            </div>
            <div class="form-text">Maximale Dateigröße: ' . ini_get('upload_max_filesize') . '</div>
        </form>

    </div>
</div>
';

require __DIR__ . '/../../app/views/layouts/main.php';
