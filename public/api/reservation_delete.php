<?php
/**
 * API Endpoint to delete reservation(s)
 * Accepts single reservation_id or array reservation_ids for group deletion.
 */

if (ob_get_level()) {
    ob_clean();
} else {
    ob_start();
}

require_once __DIR__ . '/../../app/bootstrap.php';

use App\Core\Auth;
use App\Models\Reservation;
use App\Services\LoggerService;

Auth::requireRole('presales');

if (ob_get_level()) {
    ob_clean();
}

header('Content-Type: application/json; charset=utf-8');

try {
    $data = json_decode(file_get_contents('php://input'), true);

    // Accept single ID or array of IDs
    if (!empty($data['reservation_ids']) && is_array($data['reservation_ids'])) {
        $ids = array_filter(array_map('intval', $data['reservation_ids']));
    } elseif (!empty($data['reservation_id'])) {
        $ids = [(int)$data['reservation_id']];
    } else {
        throw new \Exception('Keine Reservierungs-ID angegeben');
    }

    if (empty($ids)) {
        throw new \Exception('Ungültige Reservierungs-IDs');
    }

    $reservationModel = new Reservation();
    $deletedCount = 0;
    $deletedIds = [];

    foreach ($ids as $rid) {
        $reservation = $reservationModel->getById($rid);
        if (!$reservation) {
            continue;
        }
        // Only delete reserved (3) or cart items (1)
        if ((int)$reservation['status_id'] !== 3 && (int)$reservation['status_id'] !== 1) {
            continue;
        }
        $reservationModel->delete($rid);
        $deletedIds[] = $rid;
        $deletedCount++;
    }

    if ($deletedCount === 0) {
        throw new \Exception('Keine Reservierungen konnten gelöscht werden');
    }

    LoggerService::logPresales('presales_reservation_deleted', [
        'user_id'         => (string)Auth::id(),
        'username'        => Auth::username() ?? '',
        'reservation_ids' => $deletedIds,
        'count'           => $deletedCount,
    ]);

    echo json_encode([
        'success'       => true,
        'message'       => $deletedCount . ' Reservierung(en) wurden gelöscht',
        'deleted_count' => $deletedCount,
    ]);
    exit;

} catch (\Exception $e) {
    if (ob_get_level()) {
        ob_clean();
    }
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error'   => $e->getMessage()
    ]);
    exit;
}
