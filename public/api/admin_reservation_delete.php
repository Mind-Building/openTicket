<?php
/**
 * API Endpoint to delete a reservation (Admin)
 */

require_once __DIR__ . '/../../app/bootstrap.php';

use App\Core\Auth;
use App\Core\Session;
use App\Models\Reservation;
use App\Services\LoggerService;

Auth::requireAdmin();

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);

// Accept single ID or array of IDs
if (!empty($input['reservation_ids']) && is_array($input['reservation_ids'])) {
    $ids = array_filter(array_map('intval', $input['reservation_ids']));
} elseif (!empty($input['reservation_id'])) {
    $ids = [(int)$input['reservation_id']];
} else {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Missing reservation_id']);
    exit;
}

if (empty($ids)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Ungültige Reservierungs-IDs']);
    exit;
}

$reservationModel = new Reservation();
$deletedCount = 0;
$deleted = [];

try {
    // Fetch all reservation data before deleting (for complete log entry)
    $reservationsToDelete = [];
    foreach ($ids as $reservationId) {
        $reservation = $reservationModel->getById($reservationId);
        if ($reservation) {
            $reservationsToDelete[$reservationId] = $reservation;
        }
    }

    if (empty($reservationsToDelete)) {
        echo json_encode(['success' => false, 'error' => 'Keine Reservierungen gefunden']);
        exit;
    }

    foreach ($reservationsToDelete as $reservationId => $reservation) {
        $reservationModel->delete($reservationId);
        $deleted[] = $reservationId;
        $deletedCount++;
    }

    // Log once for the entire group with all seats
    $firstReservation = reset($reservationsToDelete);
    $seatIdentifiers  = array_values(array_filter(array_column($reservationsToDelete, 'seat_identifier')));
    LoggerService::logAdmin('reservation_deleted', [
        'reservation_ids'  => $deleted,
        'event_id'         => $firstReservation['event_id'],
        'event_date'       => $firstReservation['event_date'],
        'seat_identifiers' => $seatIdentifiers,
        'seat_count'       => count($seatIdentifiers),
        'admin_user_id'    => $_SESSION['user_id'] ?? null,
    ]);

    echo json_encode([
        'success'       => true,
        'message'       => $deletedCount . ' Reservierung(en) wurde(n) gelöscht',
        'deleted_count' => $deletedCount,
        'deleted_ids'   => $deleted,
    ]);

} catch (\Exception $e) {
    error_log("Admin reservation delete error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
