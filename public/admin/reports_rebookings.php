<?php
require_once __DIR__ . '/../../app/bootstrap.php';

use App\Core\Auth;
use App\Core\Csrf;
use App\Models\Event;
use App\Models\Rebooking;
use App\Models\SeatPlan;
use App\Services\LoggerService;

Auth::requireAdmin();

$eventModel     = new Event();
$rebookingModel = new Rebooking();
$seatPlanModel  = new SeatPlan();

// Backfill aus den Logdateien (einmalige Wartungsaktion) — CSRF-geschuetzt.
$backfill = null;      // Ergebnis-Array fuer die Anzeige
$backfillDryRun = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'backfill') {
    if (!Csrf::validatePost()) {
        http_response_code(403);
        exit('Ungültiges CSRF-Token.');
    }
    $backfillDryRun = ($_POST['mode'] ?? '') === 'dryrun';
    $backfill = $rebookingModel->backfillFromLogs(LoggerService::getLogPath(), $backfillDryRun);
    if (!$backfillDryRun) {
        LoggerService::logAdmin('rebookings_backfill', [
            'scanned'  => $backfill['scanned'],
            'items'    => $backfill['items'],
            'inserted' => $backfill['inserted'],
            'skipped'  => $backfill['skipped'],
        ]);
    }
}

$eventId = (int)($_GET['event_id'] ?? 0);
$events  = $eventModel->getAll();
$counts  = $rebookingModel->countsByEvent();

$rows = ($eventId > 0) ? $rebookingModel->getByEvent($eventId) : [];

/** Datum TT.MM.JJJJ */
$fmtDate = static function (?string $d): string {
    if (empty($d)) {
        return '—';
    }
    $ts = strtotime($d);
    return $ts ? date('d.m.Y', $ts) : htmlspecialchars($d);
};
/** Zeitstempel TT.MM.JJJJ HH:MM */
$fmtDateTime = static function (?string $d): string {
    if (empty($d)) {
        return '—';
    }
    $ts = strtotime($d);
    return $ts ? date('d.m.Y H:i', $ts) : htmlspecialchars($d);
};

$pageTitle   = 'Umbuchungen';
$showSidebar = true;
$showHeader  = true;

// --- Ergebnis-Banner des Backfills (falls gerade ausgeloest) ---
$backfillResultHtml = '';
if ($backfill !== null) {
    if (!empty($backfill['errors'])) {
        $backfillResultHtml .= '<div class="alert alert-danger"><strong>Fehler beim Nachladen:</strong><br>'
            . htmlspecialchars(implode("\n", $backfill['errors'])) . '</div>';
    }
    if ($backfillDryRun) {
        $msg = 'Vorschau: <strong>' . (int)$backfill['items'] . '</strong> Umbuchung(en) in '
            . (int)$backfill['scanned'] . ' Log-Eintrag/-Eintraegen gefunden. Es wurde noch nichts geschrieben.';
        $backfillResultHtml .= '<div class="alert alert-info">' . $msg;
        if (!empty($backfill['entries'])) {
            $backfillResultHtml .= '<ul class="mb-0 mt-2 small">';
            foreach (array_slice($backfill['entries'], 0, 50) as $e) {
                $backfillResultHtml .= '<li>' . htmlspecialchars($e['created_at']) . ' — Karte #'
                    . (int)$e['reservation_id'] . ': '
                    . htmlspecialchars($e['old_date']) . ' / ' . htmlspecialchars($e['old_seat']) . ' → '
                    . htmlspecialchars($e['new_date']) . ' / ' . htmlspecialchars($e['new_seat']) . '</li>';
            }
            $backfillResultHtml .= '</ul>';
            if (count($backfill['entries']) > 50) {
                $backfillResultHtml .= '<div class="small text-muted mt-1">… weitere '
                    . (count($backfill['entries']) - 50) . ' nicht angezeigt.</div>';
            }
        }
        $backfillResultHtml .= '</div>';
    } else {
        $backfillResultHtml .= '<div class="alert alert-success">Nachladen abgeschlossen: <strong>'
            . (int)$backfill['inserted'] . '</strong> neu übernommen, '
            . (int)$backfill['skipped'] . ' bereits vorhanden ('
            . (int)$backfill['items'] . ' Umbuchungen in ' . (int)$backfill['scanned'] . ' Log-Eintraegen geprüft).</div>';
    }
}

$content = '
<div class="d-flex justify-content-between align-items-center mb-4">
    <h2>Umbuchungen</h2>
    <a href="' . url('admin/reports.php') . '" class="btn btn-outline-secondary">
        <i class="bi bi-arrow-left"></i> Zurück
    </a>
</div>

<div class="alert alert-info d-flex align-items-start">
    <i class="bi bi-info-circle me-2 fs-5"></i>
    <div>
        Übersicht aller umgebuchten Karten je Veranstaltung (Sitz- und/oder Datumswechsel).
        <strong>Achtung:</strong> Wurde eine bereits verkaufte Karte umgebucht, kann der Kunde noch
        einen <em>alten</em> Ausdruck mit dem ursprünglichen Platz/Datum besitzen. Bei Datumswechseln
        ist am Einlass besonders darauf zu achten.
    </div>
</div>

' . $backfillResultHtml . '

<div class="row mb-4">
    <div class="col-md-5">
        <form method="GET" action="">
            <label for="event_id" class="form-label">Veranstaltung</label>
            <select class="form-select" id="event_id" name="event_id" onchange="this.form.submit()">
                <option value="">-- Bitte wählen --</option>';

foreach ($events as $event) {
    $selected = $eventId === (int)$event['id'] ? 'selected' : '';
    $cnt      = $counts[(int)$event['id']] ?? 0;
    $suffix   = $cnt > 0 ? ' (' . $cnt . ')' : '';
    $content .= '<option value="' . (int)$event['id'] . '" ' . $selected . '>'
        . htmlspecialchars($event['name']) . $suffix . '</option>';
}

$content .= '
            </select>
            <div class="form-text">Die Zahl in Klammern gibt die Anzahl umgebuchter Karten an.</div>
        </form>
    </div>
</div>';

if ($eventId > 0) {
    if (empty($rows)) {
        $content .= '<div class="alert alert-secondary">Für diese Veranstaltung sind keine Umbuchungen protokolliert.</div>';
    } else {
        $content .= '
<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <h5 class="mb-0">' . count($rows) . ' Umbuchung(en)</h5>
    </div>
    <div class="table-responsive">
        <table class="table table-striped table-hover mb-0 align-middle">
            <thead>
                <tr>
                    <th>Zeitpunkt</th>
                    <th>Karte</th>
                    <th>Kunde</th>
                    <th>Von (alt)</th>
                    <th>Nach (neu)</th>
                    <th>Bearbeiter</th>
                </tr>
            </thead>
            <tbody>';

        foreach ($rows as $r) {
            $resId    = (int)$r['reservation_id'];
            $oldSeat  = $seatPlanModel->getSeatDisplayName($eventId, (string)$r['old_seat_identifier']);
            $newSeat  = $seatPlanModel->getSeatDisplayName($eventId, (string)$r['new_seat_identifier']);
            $dateChanged = substr((string)$r['old_event_date'], 0, 10) !== substr((string)$r['new_event_date'], 0, 10);

            // Kundenanzeige (Reservierung kann inzwischen geloescht/ohne Kunde sein)
            $name = trim(($r['first_name'] ?? '') . ' ' . ($r['last_name'] ?? ''));
            if ($name !== '') {
                $customer = htmlspecialchars($name);
                if (!empty($r['email'])) {
                    $customer .= '<br><small class="text-muted">' . htmlspecialchars($r['email']) . '</small>';
                }
            } elseif (!empty($r['email'])) {
                $customer = htmlspecialchars($r['email']);
            } else {
                $customer = '<span class="text-muted">—</span>';
            }

            $dateBadge = $dateChanged
                ? ' <span class="badge bg-warning text-dark">Datum</span>'
                : '';

            $oldCell = $fmtDate($r['old_event_date']) . '<br><small class="text-muted">'
                . htmlspecialchars($oldSeat) . '</small>';
            $newCell = $fmtDate($r['new_event_date']) . $dateBadge . '<br><small class="text-muted">'
                . htmlspecialchars($newSeat) . '</small>';

            $bearbeiter = htmlspecialchars($r['username'] ?? '—')
                . '<br><small class="text-muted">' . htmlspecialchars($r['channel']) . '</small>';

            $content .= '
                <tr>
                    <td>' . $fmtDateTime($r['created_at']) . '</td>
                    <td>#' . $resId . '</td>
                    <td>' . $customer . '</td>
                    <td>' . $oldCell . '</td>
                    <td>' . $newCell . '</td>
                    <td>' . $bearbeiter . '</td>
                </tr>';
        }

        $content .= '
            </tbody>
        </table>
    </div>
</div>';
    }
}

// --- Wartung: Historie aus Logs nachladen (einmalig nach Einfuehrung/Deploy) ---
$content .= '
<div class="card mt-4 border-light">
    <div class="card-header bg-light">
        <button class="btn btn-link text-decoration-none p-0 text-dark" type="button"
                data-bs-toggle="collapse" data-bs-target="#backfillPanel">
            <i class="bi bi-tools"></i> Wartung: Historie aus Logdateien nachladen
        </button>
    </div>
    <div class="collapse' . ($backfill !== null ? ' show' : '') . '" id="backfillPanel">
        <div class="card-body">
            <p class="text-muted">
                Umbuchungen, die <strong>vor</strong> Einführung dieser Übersicht erfolgt sind, stehen nur in den
                Logdateien. Hier lassen sie sich einmalig in die Tabelle übernehmen. Die Aktion ist gefahrlos
                wiederholbar — bereits vorhandene Einträge werden übersprungen.
            </p>
            <form method="POST" action="" class="d-flex gap-2">
                ' . Csrf::field() . '
                <input type="hidden" name="action" value="backfill">
                <button type="submit" name="mode" value="dryrun" class="btn btn-outline-secondary">
                    <i class="bi bi-eye"></i> Vorschau (nur anzeigen)
                </button>
                <button type="submit" name="mode" value="run" class="btn btn-primary"
                        onclick="return confirm(\'Umbuchungen aus den Logdateien in die Tabelle übernehmen?\');">
                    <i class="bi bi-download"></i> Jetzt nachladen
                </button>
            </form>
        </div>
    </div>
</div>
';

require __DIR__ . '/../../app/views/layouts/main.php';
