<?php
// Suppress any output before JSON
ob_start();

require_once __DIR__ . '/../../app/bootstrap.php';

use App\Core\Session;
use App\Core\Config;
use App\Core\Database;
use App\Core\ApiGuard;
use App\Models\Reservation;
use App\Models\Event;
use App\Models\SeatPlan;
use App\Services\EmailService;

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

if (!isset($input['event_id']) || !isset($input['event_date']) || !isset($input['seats'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid data']);
    exit;
}

$eventId = (int)$input['event_id'];
$eventDate = $input['event_date'];
$seats = $input['seats'];
$sessionId = session_id();

// Check if event date is still bookable
$eventModel = new Event();
$event = $eventModel->getById($eventId);
if (!$event) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Veranstaltung nicht gefunden']);
    exit;
}

$maxReservationTime = $event['max_reservation_time'] ?? '12:00:00';
$bookableCheck = isEventDateBookable($eventDate, $maxReservationTime);
if (!$bookableCheck['bookable']) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $bookableCheck['reason']]);
    exit;
}

try {
    $reservationModel = new Reservation();
    $added = 0;
    $errors = [];
    $alreadyInCart = 0;

    foreach ($seats as $seat) {
        $seatIdentifier = $seat['id'];
        $seatCategoryId = $seat['category'] ?? null;
        
        $db = Database::getInstance();
        
        // Start transaction with serializable isolation
        $db->query("START TRANSACTION");
        
        // Check if already in this user's cart
        $stmt = $db->query(
            "SELECT id, status_id FROM reservations WHERE session_id = :session AND seat_identifier = :seat AND event_id = :event AND event_date = :date FOR UPDATE",
            ['session' => $sessionId, 'seat' => $seatIdentifier, 'event' => $eventId, 'date' => $eventDate]
        );
        $existingSameSession = $stmt->fetch();
        
        if ($existingSameSession) {
            $db->query("ROLLBACK");
            // Seat already in cart - count as already in cart
            $alreadyInCart++;
            continue;
        }
        
        // Check if seat is already reserved by someone else (not status 5 = canceled)
        $stmt = $db->query(
            "SELECT id, status_id, session_id FROM reservations WHERE seat_identifier = :seat AND event_date = :date FOR UPDATE",
            ['seat' => $seatIdentifier, 'date' => $eventDate]
        );
        $existing = $stmt->fetch();
        
        if ($existing && (int)$existing['status_id'] !== 5) {
            $db->query("ROLLBACK");
            $errors[] = "Platz {$seatIdentifier} ist bereits reserviert.";
            continue;
        }
        
        // Create reservation
        $sql = "INSERT INTO reservations (session_id, customer_id, event_id, event_date, seat_identifier, category_id, status_id, is_reduced, is_fn_card, is_honor_card, created_at, updated_at) 
                VALUES (:session_id, :customer_id, :event_id, :event_date, :seat_identifier, :category_id, :status_id, :is_reduced, :is_fn_card, :is_honor_card, NOW(), NOW())";
        
        $stmt = $db->query($sql, [
            'session_id' => $sessionId,
            'customer_id' => $_SESSION['customer_id'] ?? null,
            'event_id' => $eventId,
            'event_date' => $eventDate,
            'seat_identifier' => $seatIdentifier,
            'category_id' => $seatCategoryId,
            'status_id' => 1,
            'is_reduced' => $seat['is_reduced'] ?? 0,
            'is_fn_card' => $seat['is_fn_card'] ?? 0,
            'is_honor_card' => $seat['is_honor_card'] ?? 0
        ]);
        
        $reservationId = (int)$db->lastInsertId();
        
        $db->query("COMMIT");
        
        // Try to add to cart atomically
        try {
            $added++;

            try {
                $userId = $_SESSION['user_id'] ?? null;
                logSeatsReserved($sessionId, $eventId, $eventDate, [$seatIdentifier], $userId);
                logSeatsAddedToCart($sessionId, $eventId, $eventDate, [$seatIdentifier], $userId);
            } catch (\Throwable $e) {
                error_log("Logging error: " . $e->getMessage());
            }
        } catch (\Exception $e) {
            // Seat was already reserved by another user
            $errors[] = "Platz " . ($seat['row'] ?? '') . ", " . ($seat['seat'] ?? $seatIdentifier) . " ist bereits reserviert";
        }
    }
    
    $totalProcessed = $added + $alreadyInCart;
    
    // Reset countdown for ALL cart items if any seat was processed
    if ($totalProcessed > 0) {
        // Reset session-based reservation timer
        $reservationExpiresAt = Session::resetReservationTimeout();

        $allCartItems = $reservationModel->getBySession($sessionId);
        $connection = $reservationModel->getConnection();
        foreach ($allCartItems as $item) {
            $sql = "UPDATE reservations SET created_at = NOW() WHERE id = :id";
            $stmt = $connection->prepare($sql);
            $stmt->execute([':id' => $item['id']]);
        }

        $messageParts = [];
        if ($added > 0) {
            $messageParts[] = "$added neu hinzugefügt";
        }
        if ($alreadyInCart > 0) {
            $messageParts[] = "$alreadyInCart bereits im Warenkorb";
        }
        
        echo json_encode([
            'success' => true, 
            'message' => implode(", ", $messageParts) . " - zum Warenkorb hinzugefügt",
            'added' => $added,
            'already_in_cart' => $alreadyInCart,
            'errors' => $errors
        ]);
    } else {
        echo json_encode([
            'success' => false, 
            'error' => 'Keine Plätze konnten hinzugefügt werden',
            'errors' => $errors
        ]);
    }
    
} catch (\Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
} catch (\Error $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}

