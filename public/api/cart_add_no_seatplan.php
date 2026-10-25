<?php
// Suppress any output before JSON
ob_start();

require_once __DIR__ . '/../../app/bootstrap.php';

use App\Core\Session;
use App\Core\Config;
use App\Core\ApiGuard;
use App\Models\Reservation;
use App\Models\Event;
use App\Models\SeatCategory;

// Clear any output
ob_clean();

Session::start();
ApiGuard::publicApi('cart', 90, 60);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . url(''));
    exit;
}

$eventId = (int)($_POST['event_id'] ?? 0);
$eventDate = $_POST['event_date'] ?? '';
$quantity = (int)($_POST['quantity'] ?? 0);
$isReduced = isset($_POST['is_reduced']) && $_POST['is_reduced'] == '1';
$sessionId = session_id();

// Validate input
if ($eventId <= 0 || empty($eventDate) || $quantity <= 0) {
    $_SESSION['error'] = 'Ungültige Eingabe';
    header('Location: ' . url('event.php?id=' . $eventId . '&date=' . urlencode($eventDate)));
    exit;
}

// Check if event exists and is valid
$eventModel = new Event();
$event = $eventModel->getById($eventId);
if (!$event || !$event['active']) {
    $_SESSION['error'] = 'Veranstaltung nicht gefunden';
    header('Location: ' . url(''));
    exit;
}

// Check if event uses seat plan (should not)
if ($event['use_seat_plan']) {
    $_SESSION['error'] = 'Diese Veranstaltung verwendet einen Sitzplan';
    header('Location: ' . url('event.php?id=' . $eventId . '&date=' . urlencode($eventDate)));
    exit;
}

// Check max seats
$maxSeats = $event['max_seats'] ?? null;
if ($maxSeats === null) {
    $_SESSION['error'] = 'Für diese Veranstaltung ist keine maximale Sitzanzahl konfiguriert';
    header('Location: ' . url('event.php?id=' . $eventId . '&date=' . urlencode($eventDate)));
    exit;
}

if ($quantity > $maxSeats) {
    $_SESSION['error'] = "Die maximale Anzahl von $maxSeats Tickets wurde überschritten";
    header('Location: ' . url('event.php?id=' . $eventId . '&date=' . urlencode($eventDate)));
    exit;
}

// Check if event date is still bookable
$maxReservationTime = $event['max_reservation_time'] ?? '12:00:00';
$bookableCheck = isEventDateBookable($eventDate, $maxReservationTime);
if (!$bookableCheck['bookable']) {
    $_SESSION['error'] = $bookableCheck['reason'];
    header('Location: ' . url('event.php?id=' . $eventId . '&date=' . urlencode($eventDate)));
    exit;
}

// Get category if set
$categoryId = $event['seat_category_id'] ?? null;
if ($categoryId) {
    $categoryModel = new SeatCategory();
    $category = $categoryModel->getById($categoryId);
    if (!$category) {
        $categoryId = null;
    }
}

// Check how many tickets are already reserved for this event/date
$reservationModel = new Reservation();
// Get all reservations for this event/date (excluding expired cart items)
$existingReservations = $reservationModel->getAll([
    'event_id' => $eventId,
    'event_date' => $eventDate
]);

// Count reserved tickets (for events without seat plan, we count reservations)
// Only count reservations that are:
// - Paid/reserved/box-office-printed (status_id >= 2)
// - In cart and less than 15 minutes old (status_id = 1)
$reservedCount = 0;
$currentUserCartCount = 0;
foreach ($existingReservations as $reservation) {
    // Count tickets that are definitely reserved
    if ($reservation['status_id'] >= 2) {
        $reservedCount++;
    } elseif ($reservation['status_id'] == 1) {
        // Check if reservation is less than 15 minutes old
        $createdAt = strtotime($reservation['created_at']);
        $now = time();
        if (($now - $createdAt) < 900) { // 15 minutes = 900 seconds
            $reservedCount++;
            // Count tickets in current user's cart separately
            if ($reservation['session_id'] === $sessionId) {
                $currentUserCartCount++;
            }
        }
    }
}

// Available seats = max seats - reserved seats + current user's cart items
// (because we can replace items in the current user's cart)
$availableSeats = $maxSeats - $reservedCount + $currentUserCartCount;

if ($quantity > $availableSeats) {
    $_SESSION['error'] = "Es sind nur noch $availableSeats Tickets verfügbar";
    header('Location: ' . url('event.php?id=' . $eventId . '&date=' . urlencode($eventDate)));
    exit;
}

try {
    // Add tickets to cart (create reservations without specific seat identifiers)
    $added = 0;
    $errors = [];
    
    // First, remove existing no_seatplan tickets for this event/date from current user's cart
    $cartItems = $reservationModel->getBySession($sessionId);
    foreach ($cartItems as $item) {
        if (strpos($item['seat_identifier'], 'no_seatplan_') === 0 && 
            $item['event_id'] == $eventId && 
            $item['event_date'] === $eventDate) {
            // Remove old reservation
            $reservationModel->delete($item['id']);
        }
    }
    
    // Now add new tickets
    for ($i = 0; $i < $quantity; $i++) {
        // Create a unique identifier for tickets without seat plan
        // Format: no_seatplan_TIMESTAMP_INDEX_RANDOM
        $seatIdentifier = 'no_seatplan_' . time() . '_' . $i . '_' . rand(1000, 9999);
        
        try {
            $reservationId = $reservationModel->create([
                'session_id' => $sessionId,
                'event_id' => $eventId,
                'event_date' => $eventDate,
                'seat_identifier' => $seatIdentifier,
                'category_id' => $categoryId,
                'status_id' => 1, // In cart (temporarily reserved)
                'is_reduced' => $isReduced ? 1 : 0,
            ]);
            
            $added++;
        } catch (\Exception $e) {
            $errors[] = "Fehler beim Hinzufügen von Ticket " . ($i + 1) . ": " . $e->getMessage();
        }
    }
    
    if ($added > 0) {
        // Reset countdown for ALL cart items if any ticket was added
        $allCartItems = $reservationModel->getBySession($sessionId);
        $connection = $reservationModel->getConnection();
        foreach ($allCartItems as $item) {
            $sql = "UPDATE reservations SET created_at = NOW() WHERE id = :id";
            $stmt = $connection->prepare($sql);
            $stmt->execute([':id' => $item['id']]);
        }
        
        $_SESSION['success'] = "$added Ticket(s) zum Warenkorb hinzugefügt";
        header('Location: ' . url('cart.php'));
        exit;
    } else {
        $_SESSION['error'] = 'Keine Tickets konnten hinzugefügt werden. ' . implode(', ', $errors);
        header('Location: ' . url('event.php?id=' . $eventId . '&date=' . urlencode($eventDate)));
        exit;
    }
    
} catch (\Exception $e) {
    $_SESSION['error'] = 'Fehler: ' . $e->getMessage();
    header('Location: ' . url('event.php?id=' . $eventId . '&date=' . urlencode($eventDate)));
    exit;
}

