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
$categoryId = (int)($input['category_id'] ?? 0);

if (!$eventId || empty($eventDate) || empty($seatIdentifier)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Missing required fields']);
    exit;
}

$sessionId = session_id();
$reservationModel = new Reservation();

try {
    $db = Database::getInstance();
    
    $db->query("START TRANSACTION");
    
    $stmt = $db->query(
        "SELECT id, status_id FROM reservations WHERE session_id = :session AND seat_identifier = :seat AND event_id = :event AND event_date = :date FOR UPDATE",
        ['session' => $sessionId, 'seat' => $seatIdentifier, 'event' => $eventId, 'date' => $eventDate]
    );
    $existingSameSession = $stmt->fetch();
    
    if ($existingSameSession) {
        $db->query("ROLLBACK");
        echo json_encode(['success' => false, 'error' => 'Seat already in session reservation']);
        exit;
    }
    
    $stmt = $db->query(
        "SELECT id, status_id, session_id FROM reservations WHERE seat_identifier = :seat AND event_date = :date FOR UPDATE",
        ['seat' => $seatIdentifier, 'date' => $eventDate]
    );
    $existing = $stmt->fetch();
    
    if ($existing && (int)$existing['status_id'] !== 5) {
        $db->query("ROLLBACK");
        echo json_encode(['success' => false, 'error' => 'Seat already reserved']);
        exit;
    }
    
    $sql = "INSERT INTO reservations (session_id, customer_id, event_id, event_date, seat_identifier, category_id, status_id, is_reduced, is_fn_card, is_honor_card, created_at, updated_at) 
            VALUES (:session_id, :customer_id, :event_id, :event_date, :seat_identifier, :category_id, :status_id, :is_reduced, :is_fn_card, :is_honor_card, NOW(), NOW())";
    
    $stmt = $db->query($sql, [
        'session_id' => $sessionId,
        'customer_id' => $_SESSION['customer_id'] ?? null,
        'event_id' => $eventId,
        'event_date' => $eventDate,
        'seat_identifier' => $seatIdentifier,
        'category_id' => $categoryId ?: null,
        'status_id' => 1,
        'is_reduced' => 0,
        'is_fn_card' => 0,
        'is_honor_card' => 0
    ]);
    
    $db->query("COMMIT");

    // Reset reservation countdown
    $reservationExpiresAt = Session::resetReservationTimeout();

    LoggerService::logCustomer('customer_seat_selected', [
        'event_id' => $eventId,
        'event_date' => $eventDate,
        'seat_identifier' => $seatIdentifier,
        'category_id' => $categoryId ?: null,
        'session_id' => substr($sessionId, 0, 32)
    ]);

    echo json_encode([
        'success' => true,
        'message' => 'Seat reserved successfully',
        'seat_identifier' => $seatIdentifier,
        'reservation_expires_at' => $reservationExpiresAt
    ]);

} catch (\Exception $e) {
    try {
        $db->query("ROLLBACK");
    } catch (\Exception $rollbackError) {
        error_log("Rollback error: " . $rollbackError->getMessage());
    }
    error_log("Customer reserve seat error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
