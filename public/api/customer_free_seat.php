<?php
require_once __DIR__ . '/../../app/bootstrap.php';

use App\Core\Session;
use App\Core\Database;
use App\Core\ApiGuard;
use App\Models\Reservation;
use App\Services\LoggerService;

header('Content-Type: application/json; charset=utf-8');

Session::start();
ApiGuard::publicApi('seat', 120, 60);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);

if (!$input) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid JSON input']);
    exit;
}

$eventId = (int)($input['event_id'] ?? 0);
$eventDate = $input['event_date'] ?? '';
$seatIdentifier = $input['seat_identifier'] ?? '';
$reason = $input['reason'] ?? 'customer_deselected';

if (!$eventId || empty($eventDate) || empty($seatIdentifier)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Missing required fields']);
    exit;
}

$sessionId = session_id();

try {
    $db = Database::getInstance();
    
    $db->query("START TRANSACTION");
    
    $stmt = $db->query(
        "SELECT id, status_id FROM reservations WHERE session_id = :session AND seat_identifier = :seat AND event_id = :event AND event_date = :date FOR UPDATE",
        ['session' => $sessionId, 'seat' => $seatIdentifier, 'event' => $eventId, 'date' => $eventDate]
    );
    $reservation = $stmt->fetch();
    
    if (!$reservation) {
        $db->query("ROLLBACK");
        echo json_encode(['success' => false, 'error' => 'Reservation not found']);
        exit;
    }
    
    $stmt = $db->query("DELETE FROM reservations WHERE id = :id", ['id' => $reservation['id']]);

    $db->query("COMMIT");

    // Check remaining reservations; reset or clear timer
    $reservationModel = new Reservation();
    $remaining = $reservationModel->getBySession($sessionId);
    if (!empty($remaining)) {
        $reservationExpiresAt = Session::resetReservationTimeout();
    } else {
        Session::clearReservationTimeout();
        $reservationExpiresAt = 0;
    }

    LoggerService::logCustomer('customer_seat_deselected', [
        'event_id' => $eventId,
        'event_date' => $eventDate,
        'seat_identifier' => $seatIdentifier,
        'reason' => $reason,
        'session_id' => substr($sessionId, 0, 32)
    ]);

    echo json_encode([
        'success' => true,
        'message' => 'Seat freed successfully',
        'seat_identifier' => $seatIdentifier,
        'reservation_expires_at' => $reservationExpiresAt
    ]);

} catch (\Exception $e) {
    try {
        $db->query("ROLLBACK");
    } catch (\Exception $rollbackError) {
        error_log("Rollback error: " . $rollbackError->getMessage());
    }
    error_log("Customer free seat error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
