<?php
/**
 * Presales Seat Free API
 * Frees a previously reserved seat (for audit logging)
 */

require_once __DIR__ . '/../../app/bootstrap.php';

use App\Core\Session;
use App\Core\Auth;
use App\Models\Reservation;
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
    $reason = $data['reason'] ?? 'presales_deselected';
    
    if ($eventId <= 0 || empty($eventDate) || empty($seatIdentifier)) {
        echo json_encode(['success' => false, 'error' => 'Fehlende Daten']);
        exit;
    }
    
    $reservationModel = new Reservation();
    
    // Find the reservation for this seat
    $db = \App\Core\Database::getInstance();
    $stmt = $db->query(
        "SELECT id, status_id FROM reservations WHERE seat_identifier = :seat AND event_date = :date AND status_id = 1",
        ['seat' => $seatIdentifier, 'date' => $eventDate]
    );
    $existing = $stmt->fetch();
    
    if (!$existing) {
        echo json_encode(['success' => false, 'error' => 'Keine Reservierung gefunden']);
        exit;
    }
    
    // Get event name for logging
    $eventModel = new Event();
    $event = $eventModel->getById($eventId);
    $eventName = $event['name'] ?? '';
    
    // Log the release BEFORE deleting
    $userId = (string)Auth::id();
    $username = Auth::username() ?? 'unknown';
    
    \App\Services\LoggerService::logPresales('presales_seat_freed', [
        'user_id' => $userId,
        'presales_user' => $username,
        'event_id' => $eventId,
        'event_name' => $eventName,
        'event_date' => $eventDate,
        'seat_identifier' => $seatIdentifier,
        'reservation_id' => $existing['id'],
        'reason' => $reason
    ]);
    
    // Delete the reservation
    $reservationModel->delete($existing['id']);
    
    echo json_encode([
        'success' => true,
        'message' => 'Platz wieder freigegeben'
    ]);
    
} catch (\Exception $e) {
    error_log("Presales free seat error: " . $e->getMessage());
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
