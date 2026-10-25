<?php
require_once __DIR__ . '/../../app/bootstrap.php';

use App\Core\Auth;
use App\Core\Config;
use App\Core\Session;
use App\Models\Reservation;
use App\Models\Event;
use App\Models\SeatPlan;

Auth::requirePresales();
Session::start();

$reservationModel = new Reservation();
$eventModel = new Event();
$seatPlanModel = new SeatPlan();

$reservationIds = [];
if (isset($_GET['reservation_ids'])) {
    $reservationIds = array_map('intval', explode(',', $_GET['reservation_ids']));
}

// Result of the confirmation email sent by sell.php: sent | failed | null (not attempted)
$emailStatus = $_GET['email'] ?? null;

// Get reservations
$reservations = [];
foreach ($reservationIds as $id) {
    $reservation = $reservationModel->getById($id);
    if ($reservation) {
        $reservations[] = $reservation;
    }
}

// If no IDs provided, get latest reservations from session
if (empty($reservations)) {
    $sessionReservations = $reservationModel->getAllBySession(session_id());
    // Get the most recent ones (status 3 or 4)
    $reservations = array_filter($sessionReservations, function($r) {
        return (int)$r['status_id'] === 3 || (int)$r['status_id'] === 4;
    });
    // Sort by created_at DESC
    usort($reservations, function($a, $b) {
        return strtotime($b['created_at'] ?? $b['order_date']) - strtotime($a['created_at'] ?? $a['order_date']);
    });
    $reservations = array_values($reservations);
}

// Group by event and date
$groupedReservations = [];
foreach ($reservations as $reservation) {
    $key = $reservation['event_id'] . '_' . $reservation['event_date'];
    if (!isset($groupedReservations[$key])) {
        $event = $eventModel->getById($reservation['event_id']);
        $groupedReservations[$key] = [
            'event' => $event,
            'date' => $reservation['event_date'],
            'reservations' => []
        ];
    }
    $groupedReservations[$key]['reservations'][] = $reservation;
}

$pageTitle = 'Bestellung erfolgreich';
$showSidebar = true;
$showHeader = true;

$customerEmail = !empty($reservations) ? (string)($reservations[0]['email'] ?? '') : '';

// Mail status note inside the print modal
$modalMailNote = '';
if ($emailStatus === 'sent') {
    $modalMailNote = '<p class="text-muted small">Parallel wurde eine Bestätigungsmail an den Kunden gesendet.</p>';
} elseif ($emailStatus === 'failed') {
    $modalMailNote = '<p class="small text-danger fw-bold"><i class="bi bi-exclamation-triangle"></i> Die Bestätigungsmail an den Kunden konnte NICHT gesendet werden – bitte Hinweis nach dem Schließen beachten.</p>';
}

// Mail status alert on the page itself (with resend button on failure)
$emailAlert = '';
if ($emailStatus === 'sent') {
    $emailAlert = '
                <div class="alert alert-success">
                    <i class="bi bi-envelope-check"></i> <strong>Bestätigungsmail versendet</strong>' .
                    ($customerEmail !== '' ? ' an <strong>' . htmlspecialchars($customerEmail) . '</strong>' : '') . '.
                </div>';
} elseif ($emailStatus === 'failed') {
    $emailAlert = '
                <div class="alert alert-warning" id="emailFailedBox">
                    <i class="bi bi-exclamation-triangle"></i> <strong>Bestätigungsmail konnte nicht gesendet werden</strong><br>
                    Beim Versand' . ($customerEmail !== '' ? ' an <strong>' . htmlspecialchars($customerEmail) . '</strong>' : '') . ' ist ein Fehler aufgetreten.
                    Die Bestellung wurde trotzdem gespeichert.
                    <div class="mt-2">
                        <button type="button" class="btn btn-outline-primary btn-sm" id="emailResendBtn">
                            <i class="bi bi-envelope-arrow-up"></i> E-Mail erneut senden
                        </button>
                        <span id="emailResendStatus" class="ms-2 small"></span>
                    </div>
                </div>';
}

$ticketDownloadUrl = '';
$showPrintDialog = false;
if (!empty($reservations) && (int)$reservations[0]['status_id'] === 4) {
    $reservationIdsParam = implode(',', array_column($reservations, 'id'));
    $token = generateTicketDownloadToken(array_column($reservations, 'id'));
    $ticketDownloadUrl = url('api/ticket_download.php?reservation_ids=' . $reservationIdsParam . '&token=' . $token);
    $showPrintDialog = true;
}

$content = '
<div class="row justify-content-center">
    <div class="col-md-10">';

if ($showPrintDialog) {
    $content .= '
        <!-- Druckfenster Modal -->
        <div class="modal fade" id="printModal" tabindex="-1" data-bs-backdrop="static" data-bs-keyboard="false">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content">
                    <div class="modal-header bg-success text-white">
                        <h5 class="modal-title"><i class="bi bi-printer"></i> Karten drucken</h5>
                    </div>
                    <div class="modal-body text-center">
                        <p class="mb-3">Die Karten wurden erfolgreich verkauft.<br>Bitte jetzt die Karten drucken und dem Kunden aushändigen.</p>
                        ' . $modalMailNote . '
                    </div>
                    <div class="modal-footer justify-content-center gap-2">
                        <button type="button" class="btn btn-primary btn-lg" onclick="openPrintWindow()">
                            <i class="bi bi-printer"></i> Karten drucken
                        </button>
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">
                            Schließen
                        </button>
                    </div>
                </div>
            </div>
        </div>';
}

$content .= '
        <div class="card border-success">
            <div class="card-header bg-success text-white text-center">
                <h3 class="mb-0"><i class="bi bi-check-circle"></i> Bestellung erfolgreich!</h3>
            </div>
            <div class="card-body">';

if (empty($reservations)) {
    $content .= '
                <div class="alert alert-warning">
                    <i class="bi bi-exclamation-triangle"></i> Keine Reservierungen gefunden.
                </div>
                <div class="text-center mt-4">
                    <a href="' . url('presales/sell.php') . '" class="btn btn-primary">
                        <i class="bi bi-cart-plus"></i> Neue Bestellung
                    </a>
                </div>';
} else {
    $totalCount = count($reservations);
    $isReserved = !empty($reservations) && (int)$reservations[0]['status_id'] === 3; // Reserved presales
    
    $content .= '
                <p class="lead">Die Bestellung wurde erfolgreich ' . ($isReserved ? 'reserviert' : 'abgeschlossen') . '!</p>
                ' . $emailAlert . '
                <div class="alert alert-info">
                    <h5><i class="bi bi-ticket"></i> ' . ($isReserved ? 'Reservierungen' : 'Tickets') . '</h5>
                    <p>Sie haben <strong>' . $totalCount . '</strong> ' . ($isReserved ? 'Reservierung(en)' : 'Ticket(s)') . ' erstellt.</p>';
    
    foreach ($groupedReservations as $group) {
        $content .= '
                    <div class="mb-3 p-3 border rounded">
                        <h6><strong>' . htmlspecialchars($group['event']['name'] ?? 'Veranstaltung') . '</strong></h6>
                        <p class="mb-2">
                            <i class="bi bi-calendar"></i> ' . date('d.m.Y', strtotime($group['date'])) . '
                            <span class="badge ' . ($isReserved ? 'bg-warning' : 'bg-success') . ' ms-2">' . ($isReserved ? 'Reserviert' : 'Bezahlt') . '</span>
                        </p>
                        <div class="row">';
        
        foreach ($group['reservations'] as $reservation) {
            $seatDisplayName = $seatPlanModel->getSeatDisplayName($reservation['event_id'], $reservation['seat_identifier']);
            $companionNote = !empty($reservation['is_companion'])
                ? ' <span class="badge bg-info">Begleitperson kostenfrei</span>'
                : '';
            $content .= '
                            <div class="col-md-6 mb-2">
                                <small>
                                    <i class="bi bi-geo-alt"></i> ' . htmlspecialchars($seatDisplayName) . $companionNote . '
                                </small>
                            </div>';
        }
        
        $content .= '
                        </div>
                    </div>';
    }
    
    $content .= '
                </div>
                
                <div class="d-grid gap-2 mt-4">';
    
    // Show print button for paid presales tickets
    if (!$isReserved && !empty($reservations) && !empty($ticketDownloadUrl)) {
        $content .= '
                    <button type="button" class="btn btn-primary btn-lg" onclick="openPrintWindow()">
                        <i class="bi bi-printer"></i> Karten drucken
                    </button>';
    }
    
    $content .= '
                    <div class="btn-group" role="group">
                        <a href="' . url('presales/reservations.php') . '" class="btn btn-outline-primary">
                            <i class="bi bi-list"></i> Zu den Reservierungen
                        </a>
                        <a href="' . url('presales/sell.php') . '" class="btn btn-outline-secondary">
                            <i class="bi bi-cart-plus"></i> Neue Bestellung
                        </a>
                    </div>
                </div>';
}

$content .= '
            </div>
        </div>
    </div>
</div>
';

$ticketUrlJs = json_encode($ticketDownloadUrl);
$additionalJs = <<<JS
<script>
var ticketDownloadUrl = {$ticketUrlJs};

function openPrintWindow() {
    if (ticketDownloadUrl) {
        window.open(ticketDownloadUrl, '_blank');
        var modalEl = document.getElementById('printModal');
        if (modalEl) {
            var modal = bootstrap.Modal.getInstance(modalEl);
            if (modal) modal.hide();
        }
    }
}

window.addEventListener('DOMContentLoaded', function() {
    if (ticketDownloadUrl) {
        // Auto-open print modal
        var modal = new bootstrap.Modal(document.getElementById('printModal'));
        modal.show();
    }
});
</script>
JS;

// Resend handler when the confirmation email failed
if ($emailStatus === 'failed' && !empty($reservations)) {
    $resendIdsJs = json_encode(array_map('intval', array_column($reservations, 'id')));
    $resendUrlJs = json_encode(url('api/email_resend.php'));
    $additionalJs .= <<<JS
<script>
(function() {
    var btn = document.getElementById('emailResendBtn');
    var status = document.getElementById('emailResendStatus');
    if (!btn) return;
    btn.addEventListener('click', function() {
        btn.disabled = true;
        status.textContent = 'Wird gesendet…';
        status.className = 'ms-2 small text-muted';
        fetch({$resendUrlJs}, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ reservation_ids: {$resendIdsJs} })
        })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.success) {
                var box = document.getElementById('emailFailedBox');
                box.className = 'alert alert-success';
                box.innerHTML = '<i class="bi bi-envelope-check"></i> <strong>Bestätigungsmail versendet</strong> – die E-Mail wurde erneut an den Kunden gesendet.';
            } else {
                status.textContent = data.message || 'Der Versand ist erneut fehlgeschlagen.';
                status.className = 'ms-2 small text-danger';
                // Allow another attempt after the cooldown
                setTimeout(function() { btn.disabled = false; }, 31000);
            }
        })
        .catch(function() {
            status.textContent = 'Der Versand ist erneut fehlgeschlagen.';
            status.className = 'ms-2 small text-danger';
            setTimeout(function() { btn.disabled = false; }, 31000);
        });
    });
})();
</script>
JS;
}

require __DIR__ . '/../../app/views/layouts/main.php';

