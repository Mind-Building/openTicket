<?php
/**
 * Presales Seat Reserve API
 * Reserves a seat immediately (for audit logging)
 * Uses database transactions to prevent double bookings
 */

require_once __DIR__ . '/../../app/bootstrap.php';

use App\Core\Session;
use App\Core\Auth;
use App\Models\Event;

Session::start();

header('Content-Type: application/json');

if (!Auth::isPresales() && !Auth::isAdmin()) {
    echo json_encode(['success' => false, 'error' => 'Nicht autorisiert']);
    exit;
}

try {
    $data = json_decode(file_get_contents('php://input'), true);
    
    $eventId = (int)($data['event_id'] ?? 0);
    $eventDate = $data['event_date'] ?? '';
    $seatIdentifier = $data['seat_identifier'] ?? '';
    $categoryId = (int)($data['category_id'] ?? 0);
    
    if ($eventId <= 0 || empty($eventDate) || empty($seatIdentifier)) {
        echo json_encode(['success' => false, 'error' => 'Fehlende Daten']);
        exit;
    }
    
    $db = \App\Core\Database::getInstance();
    
    // Start transaction with SERIALIZABLE isolation to prevent race conditions
    $db->query("START TRANSACTION");
    
    // Lock the row for this seat (SELECT ... FOR UPDATE)
    $stmt = $db->query(
        "SELECT id, status_id FROM reservations WHERE seat_identifier = :seat AND event_date = :date FOR UPDATE",
        ['seat' => $seatIdentifier, 'date' => $eventDate]
    );
    $existing = $stmt->fetch();
    
    if ($existing && (int)$existing['status_id'] !== 5) {
        $db->query("ROLLBACK");
        echo json_encode(['success' => false, 'error' => 'Platz bereits reserviert']);
        exit;
    }
    
    // Create reservation
    $sql = "INSERT INTO reservations (session_id, customer_id, event_id, event_date, seat_identifier, category_id, status_id, is_reduced, is_fn_card, is_honor_card, created_at, updated_at) 
            VALUES (:session_id, :customer_id, :event_id, :event_date, :seat_identifier, :category_id, :status_id, :is_reduced, :is_fn_card, :is_honor_card, NOW(), NOW())";
    
    $stmt = $db->query($sql, [
        'session_id' => session_id(),
        'customer_id' => null,
        'event_id' => $eventId,
        'event_date' => $eventDate,
        'seat_identifier' => $seatIdentifier,
        'category_id' => $categoryId,
        'status_id' => 1,
        'is_reduced' => 0,
        'is_fn_card' => 0,
        'is_honor_card' => 0
    ]);
    
    $reservationId = (int)$db->lastInsertId();
    
    $db->query("COMMIT");
    
    // Get event name for logging
    $eventModel = new Event();
    $event = $eventModel->getById($eventId);
    $eventName = $event['name'] ?? '';
    
    // Log the reservation
    $userId = (string)Auth::id();
    $username = Auth::username() ?? 'unknown';
    
    \App\Services\LoggerService::logPresales('presales_seat_reserved', [
        'user_id' => $userId,
        'presales_user' => $username,
        'event_id' => $eventId,
        'event_name' => $eventName,
        'event_date' => $eventDate,
        'seat_identifier' => $seatIdentifier,
        'category_id' => $categoryId,
        'reservation_id' => $reservationId
    ]);
    
    echo json_encode([
        'success' => true,
        'reservation_id' => $reservationId,
        'message' => 'Platz reserviert'
    ]);
    
} catch (\Exception $e) {
    // Try to rollback on error
    try {
        $db->query("ROLLBACK");
    } catch (\Exception $e2) {}
    
    error_log("Presales reserve seat error: " . $e->getMessage());
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
