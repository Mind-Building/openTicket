<?php
/**
 * Email Resend API
 * Re-sends the confirmation email for a set of reservations after the
 * initial send failed (shown on the checkout success pages).
 *
 * POST JSON:
 *   {
 *     "reservation_ids": [int, ...],
 *     "token": "..."   // required for customers; presales/admin are
 *                      // authorized via their session instead
 *   }
 *
 * The email type (ticket / reservation / presales / VIP) is derived from
 * the reservation status via EmailService::sendGroupConfirmationEmail().
 * Every attempt is logged (action "email_resend" plus the regular
 * "email_sent" entry from EmailService).
 */

require_once __DIR__ . '/../../app/bootstrap.php';

use App\Core\Auth;
use App\Core\Session;
use App\Models\Reservation;
use App\Services\EmailService;
use App\Services\LoggerService;

Session::start();
header('Content-Type: application/json');

// Rate limiting: per session and ID set – min. seconds between attempts, max. attempts total
const RESEND_COOLDOWN_SECONDS = 30;
const RESEND_MAX_ATTEMPTS = 5;

function respond(bool $success, string $message, int $httpCode = 200): void
{
    http_response_code($httpCode);
    echo json_encode(['success' => $success, 'message' => $message], JSON_UNESCAPED_UNICODE);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    respond(false, 'Nur POST erlaubt.', 405);
}

$input = json_decode(file_get_contents('php://input'), true);
$rawIds = $input['reservation_ids'] ?? [];
if (is_string($rawIds)) {
    $rawIds = explode(',', $rawIds);
}
$reservationIds = array_values(array_unique(array_filter(array_map('intval', (array)$rawIds), fn($id) => $id > 0)));
$token = (string)($input['token'] ?? '');

if (empty($reservationIds) || count($reservationIds) > 100) {
    respond(false, 'Ungültige Eingabe.', 400);
}

$isStaff = Auth::check() && in_array(Auth::role(), ['admin', 'presales'], true);

// Customers must present the HMAC token bound to exactly this ID set
// (the checkout session is already destroyed at this point).
if (!$isStaff) {
    if ($token === '' || !hash_equals(generateEmailResendToken($reservationIds), $token)) {
        respond(false, 'Nicht autorisiert.', 403);
    }
}

// Rate limit per session + ID set
$idsKey = md5(implode(',', $reservationIds));
$rl = $_SESSION['email_resend'][$idsKey] ?? ['count' => 0, 'last' => 0];
if ($rl['count'] >= RESEND_MAX_ATTEMPTS) {
    respond(false, 'Maximale Anzahl an Versuchen erreicht. Bitte wenden Sie sich an den Veranstalter.', 429);
}
if (time() - $rl['last'] < RESEND_COOLDOWN_SECONDS) {
    respond(false, 'Bitte warten Sie einen Moment, bevor Sie es erneut versuchen.', 429);
}
$_SESSION['email_resend'][$idsKey] = ['count' => $rl['count'] + 1, 'last' => time()];

try {
    $reservationModel = new Reservation();
    $reservations = [];
    foreach ($reservationIds as $id) {
        $res = $reservationModel->getById($id);
        if (!$res) {
            respond(false, 'Reservierung nicht gefunden.', 404);
        }
        $reservations[] = $res;
    }

    // All reservations must belong to the same customer
    $customerIds = array_unique(array_map(fn($r) => (int)($r['customer_id'] ?? 0), $reservations));
    if (count($customerIds) !== 1) {
        respond(false, 'Ungültige Reservierungsgruppe.', 400);
    }

    $emailService = new EmailService();
    $result = $emailService->sendGroupConfirmationEmail($reservations);

    $first = $reservations[0];
    $logContext = [
        'reservation_ids' => $reservationIds,
        'reservation_count' => count($reservations),
        'customer_id' => $first['customer_id'] ?? null,
        'event_id' => $first['event_id'] ?? null,
        'event_name' => $first['event_name'] ?? '',
        'event_date' => $first['event_date'] ?? '',
        'success' => (bool)$result['sent'],
        'email_type' => $result['type'],
        'reason' => $result['reason'],
        'error' => $result['sent'] ? null : $emailService->getLastError(),
        'requested_by' => $isStaff ? Auth::role() : 'customer',
        'user_id' => $_SESSION['user_id'] ?? null,
        'attempt' => $rl['count'] + 1,
        'session_id' => substr(session_id(), 0, 32),
        'ip' => $_SERVER['REMOTE_ADDR'] ?? 'N/A',
    ];
    $level = $result['sent'] ? 'info' : 'warning';
    if (Auth::isPresales()) {
        LoggerService::logPresales('email_resend', $logContext, $level);
    } elseif (Auth::isAdmin()) {
        LoggerService::logAdmin('email_resend', $logContext, $level);
    } else {
        LoggerService::logCustomer('email_resend', $logContext, $level);
    }

    if ($result['sent']) {
        respond(true, 'Die E-Mail wurde erneut versendet.');
    }
    // Keep the message generic for customers; the concrete transport error
    // is in the logs. Staff-facing reasons from sendGroupConfirmationEmail
    // (no email address, sending disabled, ...) are safe to show.
    respond(false, $result['reason'] ?: 'Der Versand ist erneut fehlgeschlagen. Bitte versuchen Sie es später noch einmal.');
} catch (\Throwable $e) {
    error_log('email_resend.php - Error: ' . $e->getMessage());
    respond(false, 'Der Versand ist fehlgeschlagen. Bitte versuchen Sie es später noch einmal.', 500);
}
