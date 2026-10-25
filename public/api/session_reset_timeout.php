<?php
/**
 * API Endpoint to reset session timeout
 */

require_once __DIR__ . '/../../app/bootstrap.php';

use App\Core\Session;
use App\Core\ApiGuard;

header('Content-Type: application/json; charset=utf-8');

Session::start();
ApiGuard::publicApi('cart_read', 180, 60);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

try {
    $minutes = isset($_POST['minutes']) ? (int)$_POST['minutes'] : null;
    $expiresAt = Session::resetTimeout($minutes);

    // Also reset reservation timer if there are active reservations
    $reservationExpiresAt = Session::getReservationTimeout();
    if ($reservationExpiresAt > 0) {
        $reservationExpiresAt = Session::resetReservationTimeout();
    }

    echo json_encode([
        'success' => true,
        'expires_at' => $expiresAt,
        'reservation_expires_at' => $reservationExpiresAt,
        'remaining_seconds' => Session::getRemainingSeconds(),
        'default_minutes' => Session::getDefaultTimeoutMinutes()
    ]);
} catch (\Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
