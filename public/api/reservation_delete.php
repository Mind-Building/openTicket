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

if (ob_get_level()) {
    ob_clean();
}

header('Content-Type: application/json; charset=utf-8');

// Presales, or an external partner with "Eigene Verkäufe stornieren" (own, unsettled sales only)
$isPartner = Auth::isPartner();
if (!Auth::isPresales() && !($isPartner && \App\Core\Permission::can(\App\Core\Permission::CANCEL_OWN))) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Nicht autorisiert']);
    exit;
}

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
    $deletedRows = [];

    foreach ($ids as $rid) {
        $reservation = $reservationModel->getById($rid);
        if (!$reservation) {
            continue;
        }
        if ($isPartner) {
            // Partner: own sales only, reserved (3) or partner cash sale (10), not yet settled
            if ((int)($reservation['sold_by_user_id'] ?? 0) !== (int)Auth::id()
                || !in_array((int)$reservation['status_id'], [3, 10], true)
                || !empty($reservation['partner_settled_at'])) {
                continue;
            }
        } elseif ((int)$reservation['status_id'] !== 3 && (int)$reservation['status_id'] !== 1) {
            // Only delete reserved (3) or cart items (1)
            continue;
        }
        $reservationModel->delete($rid);
        $deletedIds[] = $rid;
        $deletedRows[] = $reservation;
        $deletedCount++;
    }

    if ($deletedCount === 0) {
        throw new \Exception('Keine Reservierungen konnten gelöscht werden');
    }

    $seatIdentifiers = array_values(array_filter(array_column($deletedRows, 'seat_identifier')));
    $cartSessions = array_values(array_unique(array_filter(array_map(
        fn($r) => (int)$r['status_id'] === 1 ? substr((string)($r['session_id'] ?? ''), 0, 32) : '',
        $deletedRows
    ))));

    LoggerService::logPresales('presales_reservation_deleted', [
        'user_id'          => (string)Auth::id(),
        'username'         => Auth::username() ?? '',
        'reservation_ids'  => $deletedIds,
        'count'            => $deletedCount,
        'event_id'         => $deletedRows[0]['event_id'] ?? null,
        'event_date'       => $deletedRows[0]['event_date'] ?? null,
        'seat_identifiers' => $seatIdentifiers,
        'cart_sessions'    => $cartSessions,
    ]);

    // Give the customer session whose cart was deleted a visible end in the customer log.
    LoggerService::logCartRowsDeletedByStaff($deletedRows, 'presales_deleted', (string)Auth::id());

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
