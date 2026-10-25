<?php
/**
 * API Endpoint to validate FN Card number
 */

if (ob_get_level()) {
    ob_clean();
} else {
    ob_start();
}

require_once __DIR__ . '/../../app/bootstrap.php';

use App\Core\Session;
use App\Core\Config;
use App\Core\ApiGuard;
use App\Models\Reservation;
use App\Models\Event;

Session::start();

if (ob_get_level()) {
    ob_clean();
}

header('Content-Type: application/json; charset=utf-8');

ApiGuard::publicApi('fncard', 20, 60);

try {
    if (!Config::get('fn_card_active', false)) {
        throw new \Exception('FN Card is not enabled');
    }

    $action = $_GET['action'] ?? '';
    $reservationId = isset($_GET['reservation_id']) ? (int)$_GET['reservation_id'] : 0;
    $fnCardNumber = $_GET['fncard'] ?? '';

    if ($action === 'set_fncard') {
        if (!$reservationId || !$fnCardNumber) {
            throw new \Exception('Missing parameters');
        }

        // Validate FN Card number format (8 digits)
        $fnCardNumber = trim($fnCardNumber);
        if (!Reservation::isValidFnCardNumber($fnCardNumber)) {
            throw new \Exception('Ungültige FN-Card-Nummer. Die Nummer muss 8 Ziffern lang sein.');
        }

        // Get reservation
        $reservationModel = new Reservation();
        $reservation = $reservationModel->getById($reservationId);
        
        if (!$reservation) {
            throw new \Exception('Reservierung nicht gefunden');
        }

        // Check if reservation belongs to current session
        if ($reservation['session_id'] !== session_id()) {
            throw new \Exception('Unauthorized');
        }

        // Check if reservation is in cart (status_id = 1)
        if ($reservation['status_id'] != 1) {
            throw new \Exception('Reservierung ist nicht im Warenkorb');
        }

        // Check if event allows FN Card
        $eventModel = new Event();
        $event = $eventModel->getById($reservation['event_id']);
        if (!$event || !($event['allow_fn_card'] ?? false)) {
            throw new \Exception('FN-Karte ist für diese Veranstaltung nicht aktiviert');
        }

        // Limit: max tickets per FN card number and event (setting max_fn_card); this seat itself
        // is not counted, so re-entering the same number does not use up the quota
        $maxFnCard = (int)Config::get('max_fn_card', 2);
        $existingCount = $reservationModel->getFnCardCount($fnCardNumber, (int)$reservation['event_id'], $reservationId);
        
        if ($existingCount >= $maxFnCard) {
            throw new \Exception("Das Limit von {$maxFnCard} Karten, die mit dieser FN-Card reserviert wurden, ist bereits erreicht.");
        }

        // FN card replaces a reduced price (as in the checkout radio buttons)
        $reservationModel->update($reservationId, [
            'is_fn_card' => 1,
            'is_reduced' => 0,
            'fn_card_number' => $fnCardNumber,
        ]);

        echo json_encode([
            'success' => true,
            'message' => 'FN-Card-Nummer erfolgreich validiert',
            'responseAction' => 'done'
        ]);
        exit;

    } elseif ($action === 'delete_fncard') {
        if (!$reservationId) {
            throw new \Exception('Missing parameters');
        }

        // Get reservation
        $reservationModel = new Reservation();
        $reservation = $reservationModel->getById($reservationId);
        
        if (!$reservation) {
            throw new \Exception('Reservierung nicht gefunden');
        }

        // Check if reservation belongs to current session
        if ($reservation['session_id'] !== session_id()) {
            throw new \Exception('Unauthorized');
        }

        // Update reservation: remove FN Card
        $reservationModel->update($reservationId, [
            'is_fn_card' => 0,
            'fn_card_number' => null,
        ]);

        echo json_encode([
            'success' => true,
            'message' => 'FN-Card entfernt',
            'responseAction' => 'done'
        ]);
        exit;

    } else {
        throw new \Exception('Invalid action');
    }

} catch (\Exception $e) {
    if (ob_get_level()) {
        ob_clean();
    }
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
        'responseAction' => 'error'
    ]);
    exit;
}

