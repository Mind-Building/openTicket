<?php
/**
 * Reservation Add Seats API
 * Adds new seats to an existing reservation/VIP order.
 *
 * Allowed only when the anchor reservation has status:
 *   - 3  (Reserviert Vorverkauf) or 6 (Reserviert Online) — for both admin and presales
 *   - 11 (VIP)                                            — admin only
 * Bezahlte Tickets können nicht erweitert werden.
 *
 * POST JSON:
 *   {
 *     "anchor_reservation_id": int,
 *     "seats": [seat_identifier, ...],
 *     "send_email": bool
 *   }
 *
 * The new seats inherit the anchor's customer_id, event_id, event_date, and status_id.
 * The category is derived from the seatplan structure for each seat.
 */

require_once __DIR__ . '/../../app/bootstrap.php';

use App\Core\Auth;
use App\Core\Session;
use App\Models\Reservation;
use App\Models\SeatPlan;
use App\Services\EmailService;
use App\Services\LoggerService;

Session::start();
header('Content-Type: application/json');

if (!Auth::check() || !in_array(Auth::role(), ['admin', 'presales'], true)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Nicht autorisiert']);
    exit;
}

$ALLOWED_RESERVATION_STATUSES = [3, 6];
$VIP_STATUS = 11;

try {
    $input = json_decode(file_get_contents('php://input'), true);
    $anchorId  = (int)($input['anchor_reservation_id'] ?? 0);
    $rawSeats  = $input['seats'] ?? [];
    $sendEmail = !empty($input['send_email']);

    if ($anchorId <= 0 || !is_array($rawSeats) || count($rawSeats) === 0) {
        throw new \RuntimeException('Ungültige Eingabe.');
    }

    $seats = array_values(array_unique(array_filter(array_map('strval', $rawSeats), fn($s) => $s !== '')));
    if (count($seats) === 0) {
        throw new \RuntimeException('Keine Plätze ausgewählt.');
    }

    $reservationModel = new Reservation();
    $anchor = $reservationModel->getById($anchorId);
    if (!$anchor) {
        throw new \RuntimeException('Anker-Reservierung nicht gefunden.');
    }

    $anchorStatus  = (int)$anchor['status_id'];
    $isVip         = $anchorStatus === $VIP_STATUS;
    $isReservation = in_array($anchorStatus, $ALLOWED_RESERVATION_STATUSES, true);

    if (!$isVip && !$isReservation) {
        throw new \RuntimeException('Plätze können nur zu offenen Reservierungen oder VIP-Bestellungen hinzugefügt werden — bezahlte Tickets können nicht erweitert werden.');
    }
    if (Auth::role() === 'presales' && $isVip) {
        throw new \RuntimeException('VIP-Bestellungen können nur im Admin-Bereich erweitert werden.');
    }

    $eventId    = (int)$anchor['event_id'];
    $eventDate  = $anchor['event_date'];
    $customerId = $anchor['customer_id'] !== null ? (int)$anchor['customer_id'] : null;
    $statusId   = $anchorStatus;
    // Group new rows into the SAME order: orders are aggregated via
    // (session_id, customer_id, event_id, event_date) — Reservation::getGrouped().
    $anchorSessionId = $anchor['session_id'] ?? '';
    $anchorOrderDate = $anchor['order_date'] ?? null;

    // Build seat → category map from the seatplan
    $seatPlanModel = new SeatPlan();
    $structure = $seatPlanModel->getSeatPlanStructure($eventId);
    $categoryMap = [];
    foreach ($structure as $row) {
        if (($row['type'] ?? null) !== 'row') continue;
        $rowCat = $row['category'] ?? null;
        foreach (($row['content'] ?? []) as $el) {
            $type = $el['type'] ?? null;
            if ($type === 'seat') {
                $sid = 'seat_' . $row['no'] . '_' . $el['no'];
                $categoryMap[$sid] = $el['category'] ?? $rowCat;
            } elseif ($type === 'table') {
                foreach (['front', 'back', 'left', 'right'] as $side) {
                    foreach (($el['seats'][$side] ?? []) as $tseat) {
                        $sid = 'tableseat_' . $el['no'] . '_' . $tseat['number'];
                        $categoryMap[$sid] = $tseat['category'] ?? $rowCat;
                    }
                }
            }
        }
    }

    $reservedSeats = $reservationModel->getReservedSeats($eventId, $eventDate);
    foreach ($seats as $seatId) {
        if (!isset($categoryMap[$seatId])) {
            throw new \RuntimeException("Platz \"$seatId\" nicht im Sitzplan gefunden.");
        }
        if (isset($reservedSeats[$seatId])) {
            $displayName = $seatPlanModel->getSeatDisplayName($eventId, $seatId);
            throw new \RuntimeException("Platz \"$displayName\" ist bereits belegt.");
        }
    }

    // Create the new reservation rows. Reservation::create() locks the seat row
    // and throws on a race, so atomicity is preserved per row.
    $createdIds = [];
    foreach ($seats as $seatId) {
        $resId = $reservationModel->create([
            'session_id'      => $anchorSessionId !== '' ? $anchorSessionId : (session_id() ?: 'admin-add-seats'),
            'customer_id'     => $customerId,
            'event_id'        => $eventId,
            'event_date'      => $eventDate,
            'seat_identifier' => $seatId,
            'category_id'     => $categoryMap[$seatId],
            'status_id'       => $statusId,
            'is_reduced'      => 0,
            'is_fn_card'      => 0,
            'is_honor_card'   => 0,
            'voucher_id'      => null,
        ]);
        $createdIds[] = (int)$resId;
    }

    // Align order_date with the anchor so the grouped view shows one Bestellung.
    // Reservation::create() does not accept order_date; the column defaults to NOW().
    if ($anchorOrderDate !== null && count($createdIds) > 0) {
        $placeholders = implode(',', array_fill(0, count($createdIds), '?'));
        $stmt = $reservationModel->getConnection()->prepare(
            "UPDATE reservations SET order_date = ? WHERE id IN ($placeholders)"
        );
        $stmt->execute(array_merge([$anchorOrderDate], $createdIds));
    }

    // Optional: notify customer
    $emailSent = false;
    $email = $anchor['email'] ?? '';
    if ($sendEmail && $email) {
        try {
            $emailService = new EmailService();
            $name = trim(($anchor['first_name'] ?? '') . ' ' . ($anchor['last_name'] ?? ''));
            $createdRes = [];
            foreach ($createdIds as $rid) {
                $r = $reservationModel->getById($rid);
                if ($r) $createdRes[] = $r;
            }
            $token = generateTicketDownloadToken($createdIds);
            $url = absoluteUrl('api/ticket_download.php?reservation_ids=' . implode(',', $createdIds) . '&token=' . $token);

            if ($isVip) {
                $emailSent = $emailService->sendVipEmail($email, $name, $createdRes, $url);
            } else {
                $emailSent = $emailService->sendReservationEmail($email, $name, $createdRes, $url);
            }
        } catch (\Throwable $e) {
            error_log('Add-seats email error: ' . $e->getMessage());
        }
    }

    // Log
    $action = $isVip ? 'vip_seats_added' : 'reservation_seats_added';
    $logCtx = [
        'anchor_reservation_id'   => $anchorId,
        'created_reservation_ids' => $createdIds,
        'event_id'                => $eventId,
        'event_date'              => $eventDate,
        'count'                   => count($createdIds),
        'email_sent'              => $emailSent,
    ];
    if (Auth::role() === 'admin') {
        if (function_exists('logAdminAction')) {
            logAdminAction((string)Auth::id(), $action, $logCtx);
        }
    } else {
        LoggerService::logPresales($action, ['user_id' => (string)Auth::id()] + $logCtx);
    }

    $count = count($createdIds);
    echo json_encode([
        'success' => true,
        'message' => $count === 1 ? '1 Platz hinzugefügt.' : "$count Plätze hinzugefügt.",
        'created_reservation_ids' => $createdIds,
        'email_sent' => $emailSent,
    ]);

} catch (\Throwable $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
