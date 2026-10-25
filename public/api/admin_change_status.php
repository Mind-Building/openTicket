<?php
/**
 * Admin: Change reservation status
 * Auth: admin only
 */

require_once __DIR__ . '/../../app/bootstrap.php';

use App\Core\Auth;
use App\Core\Session;
use App\Core\Database;
use App\Services\LoggerService;

Session::start();
header('Content-Type: application/json');

if (!Auth::check() || Auth::role() !== 'admin') {
    echo json_encode(['success' => false, 'error' => 'Nicht autorisiert']);
    exit;
}

try {
    $input = json_decode(file_get_contents('php://input'), true);

    $reservationId = (int)($input['reservation_id'] ?? 0);
    $newStatusId   = isset($input['new_status_id']) ? (int)$input['new_status_id'] : -1;

    $allowedStatuses = [1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 20, 91];

    if ($reservationId <= 0) {
        throw new \Exception('Ungültige Reservierungs-ID');
    }
    if (!in_array($newStatusId, $allowedStatuses, true)) {
        throw new \Exception('Ungültiger Status');
    }

    $db = Database::getInstance();

    // Fetch existing reservation to get old status
    $stmt = $db->query("SELECT id, status_id FROM reservations WHERE id = :id", [':id' => $reservationId]);
    $existing = $stmt->fetch();
    if (!$existing) {
        throw new \Exception('Reservierung nicht gefunden');
    }
    $oldStatusId = (int)$existing['status_id'];

    $db->query(
        "UPDATE reservations SET status_id = :status_id WHERE id = :id",
        [':status_id' => $newStatusId, ':id' => $reservationId]
    );

    LoggerService::logAdminReservationStatusChange(
        (string)Auth::id(),
        Auth::username() ?? '',
        $reservationId,
        $oldStatusId,
        $newStatusId
    );

    echo json_encode(['success' => true, 'message' => 'Status erfolgreich geändert']);

} catch (\Exception $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
