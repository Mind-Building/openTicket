<?php
/**
 * Order Details API
 * Returns all reservations for an order group (identified by reservation_ids or group key)
 * Auth: admin or presales
 */

require_once __DIR__ . '/../../app/bootstrap.php';

use App\Core\Auth;
use App\Core\Session;
use App\Models\Reservation;
use App\Models\SeatPlan;

Session::start();

header('Content-Type: application/json');

if (!Auth::check() || !in_array(Auth::role(), ['admin', 'presales'])) {
    echo json_encode(['success' => false, 'error' => 'Nicht autorisiert']);
    exit;
}

try {
    $reservationModel = new Reservation();
    $seatPlanModel = new SeatPlan();

    // Accept comma-separated reservation_ids
    $idsParam = $_GET['reservation_ids'] ?? '';
    if (empty($idsParam)) {
        throw new \Exception('Keine Reservierungs-IDs angegeben');
    }

    $ids = array_filter(array_map('intval', explode(',', $idsParam)));
    if (empty($ids)) {
        throw new \Exception('Ungültige Reservierungs-IDs');
    }

    $reservations = [];
    $orderTotal = 0.0;
    foreach ($ids as $id) {
        $res = $reservationModel->getById($id);
        if ($res) {
            $res['seat_display_name'] = $seatPlanModel->getSeatDisplayName((int)$res['event_id'], $res['seat_identifier'] ?? '');

            $priceInfo = \App\Helpers\TicketPrice::forReservation($res);
            $ticketPrice = $priceInfo['price'];
            $res['ticket_price'] = $ticketPrice;
            // Normal / Ermäßigt / FN-Card / Generalprobe / Begleitperson
            $res['price_type'] = \App\Helpers\TicketPrice::typeLabel($priceInfo['type']) ?: 'Normal';
            $orderTotal += $ticketPrice;

            $reservations[] = $res;
        }
    }

    if (empty($reservations)) {
        throw new \Exception('Keine Reservierungen gefunden');
    }

    // Sort by row then seat number
    usort($reservations, function (array $a, array $b): int {
        preg_match('/(\d+)[_\-](\d+)$/', $a['seat_identifier'] ?? '', $mA);
        preg_match('/(\d+)[_\-](\d+)$/', $b['seat_identifier'] ?? '', $mB);
        $rowA  = isset($mA[1]) ? (int)$mA[1] : 0;
        $seatA = isset($mA[2]) ? (int)$mA[2] : 0;
        $rowB  = isset($mB[1]) ? (int)$mB[1] : 0;
        $seatB = isset($mB[2]) ? (int)$mB[2] : 0;
        return $rowA !== $rowB ? $rowA - $rowB : $seatA - $seatB;
    });

    // Build status summary
    $statuses = array_column($reservations, 'status_id');
    $uniqueStatuses = array_unique(array_map('intval', $statuses));
    // Completed-purchase statuses that allow (re-)printing. Must mirror
    // $paidStatuses in api/ticket_download.php so VIP (11), FN-Karte (10),
    // eingelöste Gutscheine (9) und für die Abendkasse gedruckte Karten (7)
    // auch einen Reprint-Button erhalten.
    $printableStatuses = [2, 4, 5, 7, 8, 9, 10, 11];
    $allPaid   = count(array_filter($uniqueStatuses, fn($s) => in_array($s, $printableStatuses, true))) === count($uniqueStatuses);
    $anyReserved = in_array(3, $uniqueStatuses) || in_array(6, $uniqueStatuses);

    // Collect unique voucher codes used in this order
    $voucherCodes = array_values(array_unique(array_filter(array_column($reservations, 'voucher_code'))));

    echo json_encode([
        'success'      => true,
        'reservations' => $reservations,
        'summary'      => [
            'ticket_count'    => count($reservations),
            'all_paid'        => $allPaid,
            'any_reserved'    => $anyReserved,
            'unique_statuses' => $uniqueStatuses,
            'voucher_codes'   => $voucherCodes,
            'total'           => round($orderTotal, 2),
        ],
    ]);

} catch (\Exception $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
