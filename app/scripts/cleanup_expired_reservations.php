<?php
/**
 * Cleanup Script for Expired Cart Reservations
 *
 * This script should be run periodically (e.g., via cron every 5 minutes)
 * to free up seats that were added to cart but not purchased within 15 minutes.
 *
 * Usage: php app/scripts/cleanup_expired_reservations.php
 */

require_once __DIR__ . '/../bootstrap.php';

use App\Core\Database;
use App\Models\Event;
use App\Models\Reservation;
use App\Models\Voucher;
use App\Services\LoggerService;

try {
    $db = Database::getInstance()->getConnection();
    $reservationModel = new Reservation();
    $voucherModel = new Voucher();

    // Fetch expired cart reservations before deleting so we can log them
    $stmtSelect = $db->prepare(
        "SELECT id, session_id, event_id, event_date, seat_identifier
         FROM reservations
         WHERE status_id = 1
         AND created_at < DATE_SUB(NOW(), INTERVAL 20 MINUTE)"
    );
    $stmtSelect->execute();
    $expiredReservations = $stmtSelect->fetchAll(\PDO::FETCH_ASSOC);

    // Delete them
    $stmtDelete = $db->prepare(
        "DELETE FROM reservations
         WHERE status_id = 1
         AND created_at < DATE_SUB(NOW(), INTERVAL 20 MINUTE)"
    );
    $stmtDelete->execute();
    $deletedReservations = $stmtDelete->rowCount();

    // Log each released seat grouped by session+event
    if ($deletedReservations > 0) {
        $groups = [];
        foreach ($expiredReservations as $r) {
            $key = $r['session_id'] . '|' . $r['event_id'] . '|' . $r['event_date'];
            $groups[$key][] = $r['seat_identifier'];
        }
        foreach ($groups as $key => $seats) {
            [$sessionId, $eventId, $eventDate] = explode('|', $key, 3);
            LoggerService::logSeatsReleased($sessionId, (int)$eventId, $eventDate, $seats, null, 'cron_cleanup');
        }

        LoggerService::logAdmin('cron_cart_cleanup', [
            'deleted_reservations' => $deletedReservations,
            'source'               => 'cleanup_expired_reservations.php',
        ]);
    }

    // Expired vouchers
    $sqlVouchers = "SELECT id, voucher_code, purchase_intent, code, session_id FROM vouchers
                    WHERE status_id = 1
                    AND updated_at < DATE_SUB(NOW(), INTERVAL 20 MINUTE)";
    $stmtVouchers = $db->prepare($sqlVouchers);
    $stmtVouchers->execute();
    $expiredVouchers = $stmtVouchers->fetchAll(\PDO::FETCH_ASSOC);

    $deletedVouchers = 0;
    $resetVouchers = 0;

    foreach ($expiredVouchers as $voucher) {
        $code = $voucher['voucher_code'] ?? $voucher['code'] ?? (string)$voucher['id'];
        if (($voucher['purchase_intent'] ?? 'buy') === 'buy') {
            $voucherModel->delete((int)$voucher['id']);
            LoggerService::logVoucherStatusChange('admin', $code, 1, null, 'cron_cleanup', [
                'voucher_id' => $voucher['id'],
            ]);
            $deletedVouchers++;
        } else {
            $voucherModel->update((int)$voucher['id'], [
                'session_id'      => '',
                'status_id'       => 0,
                'purchase_intent' => 'buy',
            ]);
            LoggerService::logVoucherStatusChange('admin', $code, 1, 0, 'cron_cleanup', [
                'voucher_id' => $voucher['id'],
            ]);
            $resetVouchers++;
        }
    }

    if ($deletedVouchers + $resetVouchers > 0) {
        LoggerService::logAdmin('cron_voucher_cleanup', [
            'deleted_vouchers' => $deletedVouchers,
            'reset_vouchers'   => $resetVouchers,
            'source'           => 'cleanup_expired_reservations.php',
        ]);
    }

    // Vorüber gegangene Veranstaltungen automatisch deaktivieren.
    // Läuft hier mit, damit dafür kein zusätzlicher Cron-Eintrag nötig ist.
    $deactivatedEvents = (new Event())->deactivateFinishedEvents();
    foreach ($deactivatedEvents as $event) {
        LoggerService::logAdmin('event_auto_deactivated', [
            'event_id'  => $event['id'],
            'name'      => $event['name'],
            'last_date' => $event['last_date'],
            'reason'    => 'Alle Termine liegen in der Vergangenheit',
            'source'    => 'cleanup_expired_reservations.php',
        ]);
    }

    // Heartbeat: einmal pro Stunde loggen, damit sichtbar ist, dass der Cron läuft
    if ($deletedReservations === 0 && $deletedVouchers === 0 && $resetVouchers === 0 && $deactivatedEvents === []) {
        $heartbeatFile = __DIR__ . '/../../logging/.cron_heartbeat';
        $lastHeartbeat = file_exists($heartbeatFile) ? (int)file_get_contents($heartbeatFile) : 0;
        if (time() - $lastHeartbeat >= 3600) {
            LoggerService::logAdmin('cron_heartbeat', [
                'message' => 'Cleanup läuft, keine abgelaufenen Einträge',
                'source'  => 'cleanup_expired_reservations.php',
            ]);
            file_put_contents($heartbeatFile, (string)time());
        }
    }

    exit(0);
} catch (\Exception $e) {
    LoggerService::logAdmin('cron_cleanup_error', [
        'error'  => $e->getMessage(),
        'source' => 'cleanup_expired_reservations.php',
    ]);
    exit(1);
}
