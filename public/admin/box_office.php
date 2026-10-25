<?php
require_once __DIR__ . '/../../app/bootstrap.php';

use App\Core\Auth;
use App\Core\Config;
use App\Core\Database;
use App\Core\Session;
use App\Models\Reservation;
use App\Models\SeatPlan;
use App\Services\LoggerService;

Auth::requireAdmin();
Session::start();

$db               = Database::getInstance();
$reservationModel = new Reservation();
$seatPlanModel    = new SeatPlan();
$processedIds     = [];

$message     = '';
$messageType = '';
$step        = 'select'; // select | preview | done

// Box office statuses: reserved_presales (3) and reserved_online (6)
$BOX_OFFICE_STATUSES = [3, 6];
$BOX_OFFICE_TARGET   = 7; // printed_box_office

// ── POST handling ───────────────────────────────────────────────────────────
// CSRF-Schutz für alle POST-Aktionen dieser Seite
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !\App\Core\Csrf::validatePost()) {
    http_response_code(403);
    die('Ungültige Anfrage: Sicherheits-Token fehlt oder ist abgelaufen. Bitte die Seite neu laden und erneut versuchen.');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $eventId   = (int)($_POST['event_id'] ?? 0);
    $eventDate = trim($_POST['event_date'] ?? '');

    // ── Step 2: Preview ────────────────────────────────────────────────────
    if (isset($_POST['preview']) && $eventId > 0 && $eventDate !== '') {
        $step = 'preview';

    // ── Step 3: Confirm + process ──────────────────────────────────────────
    } elseif (isset($_POST['confirm'])) {
        $pin = $_POST['pin'] ?? '';
        $configuredPin = Config::get('box_office_pin', '');

        if (empty($configuredPin)) {
            $message     = 'Die Abendkasse-Funktion ist nicht verfügbar. Bitte konfigurieren Sie zuerst eine PIN in den Einstellungen.';
            $messageType = 'danger';
            $step = 'select';
        } elseif (!hash_equals((string)$configuredPin, (string)$pin)) {
            $message     = 'Falsche PIN. Bitte versuchen Sie es erneut.';
            $messageType = 'danger';
            $step = 'preview';
        } else {
            // PIN correct – Convert existing reservations to Abendkasse (status 7)
            $placeholders = implode(',', array_fill(0, count($BOX_OFFICE_STATUSES), '?'));
            $stmt = $db->getConnection()->prepare(
                "SELECT r.id, r.seat_identifier FROM reservations r
                 WHERE r.event_id = ? AND r.event_date = ?
                   AND r.status_id IN ($placeholders)
                 ORDER BY r.seat_identifier"
            );
            $stmt->execute(array_merge([$eventId, $eventDate], $BOX_OFFICE_STATUSES));
            $reservations = $stmt->fetchAll();

            foreach ($reservations as $res) {
                $reservationModel->updateStatus((int)$res['id'], $BOX_OFFICE_TARGET);
                $processedIds[] = (int)$res['id'];
            }
            $convertedCount = count($processedIds);

            LoggerService::logAdminBoxOffice(
                (string)Auth::id(),
                Auth::username() ?? '',
                $eventId,
                $eventDate,
                $processedIds,
                count($processedIds)
            );

            if ($convertedCount === 0) {
                $message     = 'Keine offenen Reservierungen für diesen Termin gefunden. Freie Plätze bleiben frei.';
                $messageType = 'warning';
                $step        = 'select';
            } else {
                $step    = 'done';
                $message = $convertedCount . ' Reservierung' . ($convertedCount != 1 ? 'en' : '')
                         . ' wurde' . ($convertedCount != 1 ? 'n' : '')
                         . ' erfolgreich auf "Abendkasse" umgestellt. Freie Plätze sind unverändert.';
                $messageType = 'success';
            }
        }
    } else {
        // Bad POST – reset
        $step      = 'select';
        $eventId   = 0;
        $eventDate = '';
    }
} else {
    $eventId   = (int)($_GET['event_id'] ?? 0);
    $eventDate = trim($_GET['event_date'] ?? '');
}

// ── Load events/dates: those with pending reservations OR a seatplan ─────────
$placeholders = implode(',', array_fill(0, count($BOX_OFFICE_STATUSES), '?'));
$stmtDates = $db->getConnection()->prepare(
    "SELECT ed.event_id, ed.date AS event_date, e.name AS event_name, e.show_start_time AS event_time,
            COUNT(r.id) AS reservation_count,
            (SELECT COUNT(*) FROM seat_plan_elements spe
             JOIN seat_plan_rows spr ON spe.row_id = spr.id
             WHERE spr.event_id = ed.event_id AND spe.element_type = 'seat') AS total_seat_count,
            (SELECT COUNT(*) FROM reservations r2
             WHERE r2.event_id = ed.event_id AND r2.event_date = ed.date AND r2.status_id >= 2) AS occupied_count
     FROM event_dates ed
     JOIN events e ON e.id = ed.event_id
     LEFT JOIN reservations r
            ON r.event_id = ed.event_id AND r.event_date = ed.date AND r.status_id IN ($placeholders)
     WHERE ed.event_id IN (SELECT DISTINCT event_id FROM seat_plan_rows)
        OR ed.event_id IN (SELECT DISTINCT event_id FROM reservations WHERE status_id IN ($placeholders))
     GROUP BY ed.event_id, ed.date
     ORDER BY ed.date ASC, e.name ASC"
);
$stmtDates->execute(array_merge($BOX_OFFICE_STATUSES, $BOX_OFFICE_STATUSES));
$availableDates = $stmtDates->fetchAll();

// ── Load preview data (step=preview) ─────────────────────────────────────────
$previewReservations = [];
$previewCountPresales = 0;
$previewCountOnline   = 0;
if ($step === 'preview' && $eventId > 0 && $eventDate !== '') {
    $stmt = $db->getConnection()->prepare(
        "SELECT r.*, e.name as event_name, e.show_start_time as event_time,
                 c.first_name, c.last_name, rs.name as status_name,
                 sc.name as category_name
         FROM reservations r
         LEFT JOIN events e ON r.event_id = e.id
         LEFT JOIN customers c ON r.customer_id = c.id
         LEFT JOIN reservation_statuses rs ON r.status_id = rs.id
         LEFT JOIN seat_categories sc ON r.category_id = sc.id
         WHERE r.event_id = ? AND r.event_date = ?
           AND r.status_id IN ($placeholders)
         ORDER BY r.seat_identifier"
    );
    $stmt->execute(array_merge([$eventId, $eventDate], $BOX_OFFICE_STATUSES));
    $previewReservations = $stmt->fetchAll();
    foreach ($previewReservations as $r) {
        if ((int)$r['status_id'] === 3) $previewCountPresales++;
        if ((int)$r['status_id'] === 6) $previewCountOnline++;
    }
}

// ── Download URLs (step=done) ────────────────────────────────────────────────
$ticketDownloadUrl   = '';
$seatplanDownloadUrl = '';
if ($step === 'done' && !empty($processedIds)) {
    $ticketDownloadUrl     = url('api/ticket_download.php?reservation_ids=' . implode(',', $processedIds));
    $seatplanDownloadUrl   = url('admin/box_office_plan.php?event_id=' . $eventId . '&date=' . urlencode($eventDate));
    $seatplanDownloadUrlBw = $seatplanDownloadUrl . '&bw=1';
}

// ── Page layout ──────────────────────────────────────────────────────────────
$pageTitle   = 'Abendkasse';
$showSidebar = true;
$showHeader  = true;

// ── Build content ────────────────────────────────────────────────────────────
$content = '<div class="card"><div class="card-header"><h5 class="card-title mb-0"><i class="bi bi-door-open"></i> Abendkasse</h5></div><div class="card-body">';

if ($message) {
    $content .= '<div class="alert alert-' . $messageType . ' alert-dismissible fade show" role="alert">'
        . htmlspecialchars($message)
        . '<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>';
}

// ── Step: Select event + date ────────────────────────────────────────────────
if ($step === 'select') {
    if (empty($availableDates)) {
        $content .= '<div class="alert alert-info"><i class="bi bi-info-circle"></i> Keine Veranstaltungen mit Sitzplan oder offenen Reservierungen gefunden.</div>';
    } else {
        $optionsHtml = '<option value="">Datum wählen...</option>';
        foreach ($availableDates as $ad) {
            $dateFormatted = date('d.m.Y', strtotime($ad['event_date']));
            $resCount  = (int)$ad['reservation_count'];
            $freeCount = max(0, (int)$ad['total_seat_count'] - (int)$ad['occupied_count']);
            $label = htmlspecialchars($ad['event_name']) . ' – ' . $dateFormatted;
            $infoParts = [];
            if ($resCount > 0)  $infoParts[] = $resCount  . ' Reservierung' . ($resCount  != 1 ? 'en' : '');
            if ($freeCount > 0) $infoParts[] = $freeCount . ' freie Plätze';
            if (!empty($infoParts)) $label .= ' (' . implode(', ', $infoParts) . ')';
            $selected = ($eventId === (int)$ad['event_id'] && $eventDate === $ad['event_date']) ? ' selected' : '';
            $optionsHtml .= '<option value="' . (int)$ad['event_id'] . '|' . htmlspecialchars($ad['event_date']) . '"' . $selected . '>' . $label . '</option>';
        }

        $content .= '
        <p class="text-muted mb-4">
            Die Abendkasse-Funktion stellt alle noch offenen Reservierungen (<em>Reserviert Vorverkauf</em>, <em>Reserviert Online</em>)
            eines Datums auf den Status <em>Abendkasse</em> um und bereitet die Tickets für den Sofortdruck vor.
            Freie Plätze bleiben unverändert frei.
        </p>
        <form method="POST" action="" id="select-form">
            ' . \App\Core\Csrf::field() . '
            <div class="mb-3">
                <label for="date-select" class="form-label fw-semibold">Veranstaltungsdatum wählen</label>
                <select class="form-select" id="date-select" name="_date_select" required onchange="applyDateSelect()">
                    ' . $optionsHtml . '
                </select>
                <input type="hidden" name="event_id" id="event_id_hidden" value="' . $eventId . '">
                <input type="hidden" name="event_date" id="event_date_hidden" value="' . htmlspecialchars($eventDate) . '">
                <input type="hidden" name="preview" value="1">
            </div>
            <button type="submit" class="btn btn-warning">
                <i class="bi bi-eye"></i> Vorschau anzeigen
            </button>
        </form>
        <hr class="my-4">
        <p class="text-muted small mb-2">
            <i class="bi bi-info-circle"></i> Den Sitzplan mit den freien Plätzen kannst du jederzeit unabhängig drucken
            (auch ohne Reservierungen umzuwandeln) – wähle dazu Veranstaltung und Datum oben aus und klicke auf den Button:
        </p>
        <div class="d-flex flex-wrap gap-2">
            <button type="button" class="btn btn-outline-info btn-sm seatplan-pdf-btn" onclick="openSeatplanPdf(false)" disabled>
                <i class="bi bi-file-earmark-pdf"></i> Sitzplan freier Plätze (Farbe)
            </button>
            <button type="button" class="btn btn-outline-secondary btn-sm seatplan-pdf-btn" onclick="openSeatplanPdf(true)" disabled>
                <i class="bi bi-file-earmark-pdf"></i> Sitzplan freier Plätze (Schwarz-Weiß)
            </button>
        </div>
        <script>
        function applyDateSelect() {
            var val = document.getElementById("date-select").value;
            var btns = document.querySelectorAll(".seatplan-pdf-btn");
            var parts = val ? val.split("|") : [];
            document.getElementById("event_id_hidden").value = parts[0] || "";
            document.getElementById("event_date_hidden").value = parts[1] || "";
            var enabled = !!(parts[0] && parts[1]);
            btns.forEach(function(b) { b.disabled = !enabled; });
        }
        function openSeatplanPdf(bw) {
            var eid = document.getElementById("event_id_hidden").value;
            var ed  = document.getElementById("event_date_hidden").value;
            if (!eid || !ed) return;
            var u = "' . url('admin/box_office_plan.php') . '?event_id=" + encodeURIComponent(eid) + "&date=" + encodeURIComponent(ed);
            if (bw) u += "&bw=1";
            window.open(u, "_blank");
        }
        // Init on load
        document.addEventListener("DOMContentLoaded", applyDateSelect);
        </script>';
    }

// ── Step: Preview ────────────────────────────────────────────────────────────
} elseif ($step === 'preview') {
    $selectedEvent = null;
    foreach ($availableDates as $ad) {
        if ((int)$ad['event_id'] === $eventId && $ad['event_date'] === $eventDate) {
            $selectedEvent = $ad;
            break;
        }
    }
    $eventName     = htmlspecialchars($selectedEvent['event_name'] ?? '');
    $dateFormatted = $eventDate ? date('d.m.Y', strtotime($eventDate)) : '';
    $totalResCount = count($previewReservations);

    if ($totalResCount === 0) {
        $content .= '<div class="alert alert-warning">Keine offenen Reservierungen für dieses Datum gefunden. Freie Plätze bleiben frei.</div>
            <a href="' . url('admin/box_office.php') . '" class="btn btn-secondary"><i class="bi bi-arrow-left"></i> Zurück</a>';
    } else {
        $tableRows = '';
        foreach ($previewReservations as $r) {
            $customerName = trim(($r['first_name'] ?? '') . ' ' . ($r['last_name'] ?? '')) ?: '<em class="text-muted">Barverkauf</em>';
            $statusBadge  = (int)$r['status_id'] === 3
                ? '<span class="badge bg-primary">' . htmlspecialchars($r['status_name'] ?? 'Reserviert VVK') . '</span>'
                : '<span class="badge bg-info">' . htmlspecialchars($r['status_name'] ?? 'Reserviert Online') . '</span>';
            $displayName  = htmlspecialchars($seatPlanModel->getSeatDisplayName($eventId, $r['seat_identifier']));
            $tableRows .= '<tr>
                <td>' . $displayName . '</td>
                <td>' . $customerName . '</td>
                <td>' . htmlspecialchars($r['category_name'] ?? '') . '</td>
                <td>' . $statusBadge . '</td>
            </tr>';
        }

        $configuredPin = Config::get('box_office_pin', '');
        $pinWarning    = empty($configuredPin)
            ? '<div class="alert alert-danger mb-3"><i class="bi bi-exclamation-triangle"></i> <strong>Keine PIN konfiguriert!</strong> Bitte legen Sie zuerst eine Abendkasse-PIN in den Einstellungen fest.</div>'
            : '';

        $summaryBadges = '<span class="badge bg-primary ms-1">' . $previewCountPresales . ' Vorverkauf</span>'
                       . '<span class="badge bg-info ms-1">' . $previewCountOnline . ' Online</span>';

        $content .= '
        <div class="alert alert-warning">
            <strong><i class="bi bi-exclamation-triangle"></i> Vorschau – noch keine Änderungen vorgenommen</strong><br>
            Veranstaltung: <strong>' . $eventName . '</strong>, Datum: <strong>' . $dateFormatted . '</strong><br>
            <strong>' . $totalResCount . '</strong> Reservierung' . ($totalResCount != 1 ? 'en werden' : ' wird') . ' auf Abendkasse umgestellt:
            ' . $summaryBadges . '<br>
            <small class="text-muted">Freie Plätze bleiben frei und werden nicht angefasst.</small>
        </div>

        <div class="table-responsive mb-4">
            <table class="table table-sm table-hover">
                <thead><tr><th>Platz</th><th>Kunde</th><th>Kategorie</th><th>Herkunft</th></tr></thead>
                <tbody>' . $tableRows . '</tbody>
            </table>
        </div>

        ' . $pinWarning . '

        <form method="POST" action="" onsubmit="return confirmBoxOffice()">
            ' . \App\Core\Csrf::field() . '
            <input type="hidden" name="event_id" value="' . $eventId . '">
            <input type="hidden" name="event_date" value="' . htmlspecialchars($eventDate) . '">
            <input type="hidden" name="confirm" value="1">
            <div class="row g-2 align-items-end mb-3">
                <div class="col-auto">
                    <label for="pin" class="form-label fw-semibold">Abendkasse-PIN</label>
                    <input type="password" class="form-control" id="pin" name="pin" required
                           placeholder="PIN eingeben" autocomplete="off" ' . (empty($configuredPin) ? 'disabled' : '') . '>
                </div>
                <div class="col-auto">
                    <button type="submit" class="btn btn-danger" ' . (empty($configuredPin) ? 'disabled' : '') . '>
                        <i class="bi bi-door-open"></i> Abendkasse starten
                    </button>
                </div>
            </div>
        </form>
        <a href="' . url('admin/box_office.php') . '" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-arrow-left"></i> Datum neu wählen
        </a>
        <script>
        function confirmBoxOffice() {
            return confirm("Wirklich ' . $totalResCount . ' Reservierung' . ($totalResCount != 1 ? 'en' : '') . ' auf Abendkasse umstellen?\nDieser Vorgang kann nicht rückgängig gemacht werden.");
        }
        </script>';
    }

// ── Step: Done ───────────────────────────────────────────────────────────────
} elseif ($step === 'done') {
    $content .= '
    <div class="alert alert-success">
        <i class="bi bi-check-circle"></i> <strong>' . htmlspecialchars($message) . '</strong>
    </div>
    <p>Die Tickets stehen jetzt zum Download und Druck bereit:</p>
    <div class="d-flex flex-wrap gap-3">
        <a href="' . htmlspecialchars($ticketDownloadUrl) . '" class="btn btn-primary btn-lg" target="_blank">
            <i class="bi bi-file-earmark-pdf"></i> Alle Tickets als PDF herunterladen
        </a>
        <a href="' . htmlspecialchars($seatplanDownloadUrl) . '" class="btn btn-outline-secondary btn-lg" target="_blank">
            <i class="bi bi-grid-3x3-gap"></i> Sitzplan freier Plätze (Farbe)
        </a>
        <a href="' . htmlspecialchars($seatplanDownloadUrlBw) . '" class="btn btn-outline-secondary btn-lg" target="_blank">
            <i class="bi bi-grid-3x3-gap"></i> Sitzplan freier Plätze (Schwarz-Weiß)
        </a>
    </div>
    <hr class="my-4">
    <a href="' . url('admin/box_office.php') . '" class="btn btn-outline-secondary">
        <i class="bi bi-arrow-left"></i> Zurück zur Abendkasse
    </a>';
}

$content .= '</div></div>';

require __DIR__ . '/../../app/views/layouts/main.php';
