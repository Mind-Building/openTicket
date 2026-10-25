<?php
// Suppress any output before JSON
ob_start();

require_once __DIR__ . '/../../app/bootstrap.php';

use App\Core\Session;
use App\Core\ApiGuard;
use App\Models\Reservation;

// Clear any output
ob_clean();

header('Content-Type: application/json; charset=utf-8');

Session::start();
ApiGuard::publicApi('cart', 90, 60);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);

if (!isset($input['reservation_id'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid data']);
    exit;
}

$reservationId = (int)$input['reservation_id'];
$sessionId = session_id();

try {
    $reservationModel = new Reservation();
    
    // Verify that reservation belongs to current session
    $reservation = $reservationModel->getById($reservationId);
    if (!$reservation || $reservation['session_id'] !== $sessionId) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Unauthorized']);
        exit;
    }
    
    // Only allow removal of cart items (status_id = 1)
    if ($reservation['status_id'] != 1) {
        echo json_encode(['success' => false, 'error' => 'Nur Warenkorb-Artikel können entfernt werden']);
        exit;
    }
    
    $reservationModel->delete($reservationId);
    
    // Log seat removal from cart
    if (function_exists('logSeatsReleased')) {
        $userId = $_SESSION['user_id'] ?? null;
        logSeatsReleased($sessionId, (int)$reservation['event_id'], $reservation['event_date'], [$reservation['seat_identifier']], $userId, 'user_removed');
    }
    
    // Reset or clear reservation timer based on remaining items
    $remainingCartItems = $reservationModel->getBySession($sessionId);
    if (!empty($remainingCartItems)) {
        Session::resetReservationTimeout();
        $connection = $reservationModel->getConnection();
        foreach ($remainingCartItems as $item) {
            $sql = "UPDATE reservations SET created_at = NOW() WHERE id = :id";
            $stmt = $connection->prepare($sql);
            $stmt->execute([':id' => $item['id']]);
        }
    } else {
        Session::clearReservationTimeout();
    }
    
    echo json_encode(['success' => true, 'message' => 'Platz aus Warenkorb entfernt']);
    
} catch (\Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
} catch (\Error $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}

