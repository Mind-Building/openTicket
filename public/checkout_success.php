<?php
require_once __DIR__ . '/../app/bootstrap.php';

use App\Core\Session;
use App\Core\Config;
use App\Models\Reservation;
use App\Models\Customer;
use App\Models\Voucher;
use App\Models\SeatPlan;

Session::start();

// Prevent caching - user should not navigate back to this page
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

// Clear reservation timer
Session::clearReservationTimeout();

$sessionId = session_id();
$reservationModel = new Reservation();
$customerModel = new Customer();
$voucherModel = new Voucher();
$seatPlanModel = new SeatPlan();

$orderId = $_GET['order_id'] ?? null;
$reservationIds = [];
$voucherIds = [];

// Get reservation IDs from URL if provided
if (isset($_GET['reservation_ids'])) {
    $reservationIds = array_map('intval', explode(',', $_GET['reservation_ids']));
}

// Get voucher IDs from URL if provided
if (isset($_GET['voucher_ids'])) {
    $voucherIds = array_map('intval', explode(',', $_GET['voucher_ids']));
}

// Get paid reservations
// Status 2 = Paid via PayPal, Status 8 = Paid with voucher (0€ orders)
// Status 6 = Reserved online – kept separately so the email status box
// (and resend button) also works for the reservation checkout flow.
$reservedReservations = [];
if (!empty($reservationIds)) {
    // Get specific reservations by ID
    $paidReservations = [];
    foreach ($reservationIds as $id) {
        $reservation = $reservationModel->getById($id);
        if ($reservation && ((int)$reservation['status_id'] === 2 || (int)$reservation['status_id'] === 8)) {
            $paidReservations[] = $reservation;
        } elseif ($reservation && (int)$reservation['status_id'] === 6) {
            $reservedReservations[] = $reservation;
        }
    }
} else {
    // Fallback: Get paid reservations for this session
    $allReservations = $reservationModel->getAllBySession($sessionId);
    $paidReservations = array_filter($allReservations, function($r) {
        $statusId = (int)$r['status_id'];
        return $statusId === 2 || $statusId === 8; // Paid via PayPal or Paid with voucher
    });
}

$customer = null;
if (!empty($paidReservations)) {
    $firstReservation = reset($paidReservations);
    if ($firstReservation['customer_id']) {
        $customer = $customerModel->getById($firstReservation['customer_id']);
    }
}
if (!$customer && !empty($reservedReservations)) {
    $firstReserved = reset($reservedReservations);
    if ($firstReserved['customer_id']) {
        $customer = $customerModel->getById($firstReserved['customer_id']);
    }
}

// Get paid vouchers
$paidVouchers = [];
if (!empty($voucherIds)) {
    foreach ($voucherIds as $id) {
        $voucher = $voucherModel->getById($id);
        if ($voucher && (int)$voucher['status_id'] === 2) {
            $paidVouchers[] = $voucher;
        }
    }
}

// Get customer from voucher if not found from reservation
if (!$customer && !empty($paidVouchers)) {
    $firstVoucher = reset($paidVouchers);
    if ($firstVoucher['customer_id']) {
        $customer = $customerModel->getById($firstVoucher['customer_id']);
    }
}

// IMPORTANT: Clear session after retrieving all necessary data
// This ensures that new purchases are not linked to the previous customer
// Store session name before destroying
$sessionName = session_name();
$sessionDestroyed = false;

// Log checkout success page visit BEFORE destroying session
// Only log on the first visit (fresh checkout), not on page reload/revisit.
// After the first visit the session is destroyed, so a revisit starts with an empty session.
$isFirstVisit = !empty($_SESSION);
if ($isFirstVisit && (!empty($paidReservations) || !empty($paidVouchers))) {
    if (function_exists('logCheckoutSuccessPage')) {
        logCheckoutSuccessPage($sessionId, $customer['id'] ?? null, [
            'order_id' => $_GET['order_id'] ?? null,
            'reservation_count' => count($paidReservations),
            'voucher_count' => count($paidVouchers),
        ]);
    }
}

// Destroy current session completely
if (session_status() === PHP_SESSION_ACTIVE) {
    $_SESSION = []; // Clear all session data
    
    // Delete session cookie
    if (isset($_COOKIE[$sessionName])) {
        $params = session_get_cookie_params();
        setcookie($sessionName, '', time() - 3600, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
    }
    
    session_destroy(); // Destroy the session
    $sessionDestroyed = true;
}

// Start a completely new session for the next visit
if ($sessionDestroyed) {
    \App\Core\Session::applyCookieParams();
    session_start();
    session_regenerate_id(true); // Generate new session ID with delete old session file
    
    // Clear any remaining session data
    $_SESSION = [];
    
    // New session started after checkout completion
}

$pageTitle = 'Bestellung erfolgreich';
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $pageTitle ?> - Ticket System</title>
    <link href="<?= url('libs/css/bootstrap.min.css') ?>" rel="stylesheet">
    <link href="<?= url('libs/css/bootstrap-icons.css') ?>" rel="stylesheet">
</head>
<body>
    <?php
    use App\Core\Auth;
    $isLoggedIn = Auth::check();
    $userRole = Auth::role();
    ?>
    <nav class="navbar navbar-expand-lg navbar-dark bg-primary">
        <div class="container">
            <a class="navbar-brand" href="<?= url('') ?>">
                <i class="bi bi-ticket-perforated"></i> Ticket System
            </a>
            <div class="ms-auto d-flex gap-2">
                <?php if ($isLoggedIn && $userRole === 'presales'): ?>
                    <a href="<?= url('presales/index.php') ?>" class="btn btn-outline-light btn-sm">
                        <i class="bi bi-house-door"></i> Vorverkauf
                    </a>
                <?php elseif ($isLoggedIn && $userRole === 'admin'): ?>
                    <a href="<?= url('admin/index.php') ?>" class="btn btn-outline-light btn-sm">
                        <i class="bi bi-house-door"></i> Admin
                    </a>
                <?php endif; ?>
            </div>
        </div>
    </nav>

    <div class="container mt-5 mb-5">
        <div class="row justify-content-center">
            <div class="col-md-8">
                <div class="card border-success">
                    <div class="card-header bg-success text-white text-center">
                        <h3 class="mb-0"><i class="bi bi-check-circle"></i> Bestellung erfolgreich!</h3>
                    </div>
                    <div class="card-body">
                        <?php if ($customer): ?>
                            <p class="lead">Hallo <?= htmlspecialchars(trim(($customer['first_name'] ?? '') . ' ' . ($customer['last_name'] ?? '')) ?: $customer['email']) ?>,</p>
                        <?php endif; ?>
                        
                        <p><?= htmlspecialchars(Config::get('checkout_success_message', 'Ihre Bestellung wurde erfolgreich aufgenommen!')) ?></p>
                        <p>Vielen Dank für Ihre Bestellung! Ihre Zahlung wurde erfolgreich bearbeitet.</p>
                        
                        <?php if (!empty($paidReservations)): ?>
                            <?php
                            // Check if this is a no-seatplan event (all reservations have no_seatplan_ identifier)
                            $firstReservation = $paidReservations[0];
                            $isNoSeatPlan = strpos($firstReservation['seat_identifier'] ?? '', 'no_seatplan_') === 0;
                            ?>
                            <div class="alert alert-info">
                                <h5><i class="bi bi-ticket"></i> Ihre Tickets</h5>
                                
                                <?php if ($isNoSeatPlan): ?>
                                    <p>Sie haben <strong><?= count($paidReservations) ?></strong> Ticket(s) bestellt.</p>
                                    <p>
                                        <strong><?= htmlspecialchars($firstReservation['event_name'] ?? 'Veranstaltung') ?></strong><br>
                                        <?php if ($firstReservation['event_date']): ?>
                                            <small class="text-muted"><?= date('d.m.Y', strtotime($firstReservation['event_date'])) ?></small>
                                        <?php endif; ?>
                                    </p>
                                <?php else: ?>
                                    <p>Sie haben <strong><?= count($paidReservations) ?></strong> Ticket(s) bestellt.</p>
                                    
                                    <div class="mt-3">
                                        <?php foreach ($paidReservations as $reservation): ?>
                                            <div class="mb-2">
                                                <strong><?= htmlspecialchars($reservation['event_name'] ?? 'Veranstaltung') ?></strong><br>
                                                <small class="text-muted">
                                                    <?php if ($reservation['event_date']): ?>
                                                        <?= date('d.m.Y', strtotime($reservation['event_date'])) ?>
                                                    <?php endif; ?>
                                                    - <?= htmlspecialchars($seatPlanModel->getSeatDisplayName((int)$reservation['event_id'], $reservation['seat_identifier'] ?? '')) ?>
                                                </small>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                            
                            <div class="d-grid gap-2">
                                <?php
                                $reservationIdsParam = implode(',', array_column($paidReservations, 'id'));
                                $token = generateTicketDownloadToken(array_column($paidReservations, 'id'));
                                ?>
                                <a href="<?= url('api/ticket_download.php?reservation_ids=' . $reservationIdsParam . '&token=' . $token) ?>" class="btn btn-primary btn-lg" target="_blank">
                                    <i class="bi bi-download"></i> Tickets herunterladen
                                </a>
                            </div>
                        <?php endif; ?>
                        
                        <?php if (!empty($paidVouchers)): ?>
                            <div class="alert alert-success mt-4">
                                <h5><i class="bi bi-gift"></i> Ihre Gutscheine</h5>
                                <p>Sie haben <strong><?= count($paidVouchers) ?></strong> Gutschein(e) gekauft.</p>
                                
                                <div class="mt-3">
                                    <?php foreach ($paidVouchers as $voucher): ?>
                                        <div class="mb-3 p-3 bg-light rounded">
                                            <div class="d-flex justify-content-between align-items-center">
                                                <div>
                                                    <strong class="text-primary"><?= htmlspecialchars($voucher['voucher_code']) ?></strong><br>
                                                    <small class="text-muted">
                                                        <?= htmlspecialchars($voucher['category_name'] ?? 'Kategorie') ?>
                                                        <?php if ($voucher['event_name']): ?>
                                                            - <?= htmlspecialchars($voucher['event_name']) ?>
                                                        <?php endif; ?>
                                                    </small>
                                                </div>
                                                <div class="text-end">
                                                    <strong><?= number_format((float)($voucher['category_price'] ?? 0), 2, ',', '.') ?> €</strong>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                                
                                <div class="alert alert-warning mt-3">
                                    <i class="bi bi-info-circle"></i> 
                                    <strong>Wichtig:</strong> Bitte notieren Sie sich Ihre Gutschein-Codes. Sie können diese später einlösen, um Tickets zu kaufen.
                                </div>
                            </div>
                        <?php endif; ?>
                        
                        <?php
                        $emailStatus = $_GET['email'] ?? null;
                        // IDs the confirmation email refers to (paid order or online reservation)
                        $emailReservationIds = !empty($paidReservations)
                            ? array_column($paidReservations, 'id')
                            : array_column($reservedReservations, 'id');
                        $isTicketEmail = !empty($paidReservations);
                        if ($customer && !empty($customer['email']) && !empty($emailReservationIds)):
                            if ($emailStatus === 'sent'): ?>
                            <div class="alert alert-success mt-4">
                                <i class="bi bi-envelope-check"></i>
                                <strong>E-Mail versendet</strong><br>
                                <?php if ($isTicketEmail): ?>
                                    Eine Bestätigungs-E-Mail mit Ihren Tickets wurde an <strong><?= htmlspecialchars($customer['email']) ?></strong> gesendet.
                                <?php else: ?>
                                    Eine Bestätigungs-E-Mail zu Ihrer Reservierung wurde an <strong><?= htmlspecialchars($customer['email']) ?></strong> gesendet.
                                <?php endif; ?>
                            </div>
                        <?php elseif ($emailStatus === 'failed'): ?>
                            <div class="alert alert-warning mt-4" id="emailFailedBox">
                                <i class="bi bi-exclamation-triangle"></i>
                                <strong>E-Mail konnte nicht gesendet werden</strong><br>
                                Leider ist beim Versand an <strong><?= htmlspecialchars($customer['email']) ?></strong> ein Fehler aufgetreten.
                                <?php if ($isTicketEmail): ?>
                                    Bitte laden Sie Ihre Tickets direkt über den Button oben herunter und speichern Sie diese.
                                <?php else: ?>
                                    Ihre Reservierung wurde trotzdem gespeichert.
                                <?php endif; ?>
                                <div class="mt-3">
                                    <button type="button" class="btn btn-outline-primary btn-sm" id="emailResendBtn">
                                        <i class="bi bi-envelope-arrow-up"></i> E-Mail erneut senden
                                    </button>
                                    <span id="emailResendStatus" class="ms-2 small"></span>
                                </div>
                            </div>
                        <?php endif; ?>
                        <?php endif; ?>
                        
                        <div class="mt-4 text-center">
                            <a href="<?= url('') ?>" class="btn btn-outline-secondary">
                                <i class="bi bi-house"></i> Zur Startseite
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="<?= url('libs/js/bootstrap.bundle.min.js') ?>"></script>
    <?php if (($emailStatus ?? null) === 'failed' && !empty($emailReservationIds)): ?>
    <script>
    (function() {
        var btn = document.getElementById('emailResendBtn');
        var status = document.getElementById('emailResendStatus');
        if (!btn) return;
        btn.addEventListener('click', function() {
            btn.disabled = true;
            status.textContent = 'Wird gesendet…';
            status.className = 'ms-2 small text-muted';
            fetch('<?= url('api/email_resend.php') ?>', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    reservation_ids: <?= json_encode(array_map('intval', $emailReservationIds)) ?>,
                    token: <?= json_encode(generateEmailResendToken($emailReservationIds)) ?>
                })
            })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (data.success) {
                    var box = document.getElementById('emailFailedBox');
                    box.className = 'alert alert-success mt-4';
                    box.innerHTML = '<i class="bi bi-envelope-check"></i> <strong>E-Mail versendet</strong><br>' +
                        'Die Bestätigungs-E-Mail wurde erneut an <strong><?= htmlspecialchars($customer['email'] ?? '', ENT_QUOTES) ?></strong> gesendet.';
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
    <?php endif; ?>
</body>
</html>

