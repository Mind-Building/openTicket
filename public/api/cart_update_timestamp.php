<?php
/**
 * API Endpoint to update cart item timestamps (reset countdown)
 */

if (ob_get_level()) {
    ob_clean();
} else {
    ob_start();
}

require_once __DIR__ . '/../../app/bootstrap.php';

use App\Core\Session;
use App\Core\ApiGuard;
use App\Models\Reservation;
use App\Models\Voucher;

Session::start();

header('Content-Type: application/json; charset=utf-8');

ApiGuard::publicApi('cart_read', 180, 60);

try {
    $sessionId = session_id();
    $reservationModel = new Reservation();
    $voucherModel = new Voucher();

    // Keep-alive heartbeat: the customer is still on a cart-bearing page, so restart
    // the expiry clock for seats and vouchers. touchCartItems() drops seats another
    // session has taken over in the meantime and reports them back.
    $touchResult = $reservationModel->touchCartItems($sessionId);
    $voucherModel->touchCartItems($sessionId);
    Session::resetReservationTimeout();

    $conflictSeats = array_column($touchResult['conflicts'], 'seat');

    echo json_encode([
        'success'        => true,
        'message'        => 'Countdown zurückgesetzt.',
        'updated_count'  => $touchResult['refreshed'],
        'expires_at'     => Session::getReservationTimeout(),
        'conflict_seats' => $conflictSeats,
    ]);
} catch (\Exception $e) {
    error_log("Cart timestamp update error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Fehler beim Aktualisieren: ' . $e->getMessage()
    ]);
}
exit;

