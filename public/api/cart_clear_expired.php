<?php
/**
 * API Endpoint to clear expired cart items
 */

if (ob_get_level()) {
    ob_clean();
} else {
    ob_start();
}

require_once __DIR__ . '/../../app/bootstrap.php';

use App\Core\Session;
use App\Models\Reservation;
use App\Models\Voucher;
use App\Services\LoggerService;

Session::start();

if (ob_get_level()) {
    ob_clean();
}

header('Content-Type: application/json; charset=utf-8');

try {
    $sessionId = session_id();
    $reservationModel = new Reservation();
    $voucherModel = new Voucher();

    // Check session-based reservation timer
    $isExpired = Session::isReservationExpired();

    // Get all cart items (status_id = 1) for this session
    $cartItems = $reservationModel->getBySession($sessionId);

    $deletedCount = 0;
    foreach ($cartItems as $item) {
        if ((int)$item['status_id'] === 1) {
            // Delete if session timer expired OR individual item is older than 15 minutes (fallback)
            $itemExpired = $isExpired;
            if (!$itemExpired) {
                $createdAt = strtotime($item['created_at']);
                $itemExpired = time() >= ($createdAt + (15 * 60));
            }

            if ($itemExpired) {
                if (function_exists('logSeatsReleased')) {
                    $userId = $_SESSION['user_id'] ?? null;
                    logSeatsReleased($sessionId, (int)$item['event_id'], $item['event_date'], [$item['seat_identifier']], $userId, 'timeout');
                }
                $reservationModel->delete($item['id']);
                $deletedCount++;
            }
        }
    }

    // Clear reservation timer if items were deleted
    if ($deletedCount > 0) {
        Session::clearReservationTimeout();
        LoggerService::logCartCleared($sessionId, $deletedCount, $_SESSION['user_id'] ?? null, 'timeout');
    }

    // Reset ALL vouchers in cart (both buy and redeem)
    $cartVouchersToBuy = $voucherModel->getBySession($sessionId, 'buy');
    $cartVouchersToRedeem = $voucherModel->getBySession($sessionId, 'redeem');
    $allCartVouchers = array_merge($cartVouchersToBuy, $cartVouchersToRedeem);
    $voucherCount = 0;
    $voucherCodes = [];

    foreach ($allCartVouchers as $voucher) {
        $code = $voucher['voucher_code'] ?? $voucher['code'] ?? (string)$voucher['id'];
        // Delete vouchers that were being purchased, reset vouchers being redeemed
        if (($voucher['purchase_intent'] ?? 'buy') === 'buy') {
            // Delete vouchers being purchased
            $voucherModel->delete($voucher['id']);
            logVoucherStatusChange('customer', $code, 1, null, 'cart_expired', [
                'voucher_id' => $voucher['id'],
                'session_id' => substr($sessionId, 0, 32),
            ]);
        } else {
            // Reset vouchers being redeemed
            $voucherModel->update($voucher['id'], [
                'session_id' => '',
                'status_id' => 0, // Back to "not redeemed"
                'purchase_intent' => 'buy' // Reset to default
            ]);
            logVoucherStatusChange('customer', $code, 1, 0, 'cart_expired', [
                'voucher_id' => $voucher['id'],
                'session_id' => substr($sessionId, 0, 32),
            ]);
        }
        $voucherCodes[] = $voucher['code'] ?? (string)$voucher['id'];
        $voucherCount++;
    }

    if ($voucherCount > 0) {
        LoggerService::logCustomer('cart_vouchers_released', [
            'session_id'   => substr($sessionId, 0, 32),
            'voucher_count' => $voucherCount,
            'voucher_codes' => $voucherCodes,
            'reason'       => 'timeout',
        ]);
    }

    // --- Global cleanup: all sessions (same logic as app/scripts/cleanup_expired_reservations.php) ---
    $db = \App\Core\Database::getInstance()->getConnection();

    // Global: expired reservations across all sessions
    $stmtSelect = $db->prepare(
        "SELECT id, session_id, event_id, event_date, seat_identifier
         FROM reservations
         WHERE status_id = 1
         AND created_at < DATE_SUB(NOW(), INTERVAL 20 MINUTE)"
    );
    $stmtSelect->execute();
    $expiredReservations = $stmtSelect->fetchAll(\PDO::FETCH_ASSOC);

    $stmtDelete = $db->prepare(
        "DELETE FROM reservations
         WHERE status_id = 1
         AND created_at < DATE_SUB(NOW(), INTERVAL 20 MINUTE)"
    );
    $stmtDelete->execute();
    $globalDeletedReservations = $stmtDelete->rowCount();

    if ($globalDeletedReservations > 0) {
        $groups = [];
        foreach ($expiredReservations as $r) {
            $key = $r['session_id'] . '|' . $r['event_id'] . '|' . $r['event_date'];
            $groups[$key][] = $r['seat_identifier'];
        }
        foreach ($groups as $key => $seats) {
            [$gSessionId, $gEventId, $gEventDate] = explode('|', $key, 3);
            LoggerService::logSeatsReleased($gSessionId, (int)$gEventId, $gEventDate, $seats, null, 'cron_cleanup');
        }
        LoggerService::logAdmin('cron_cart_cleanup', [
            'deleted_reservations' => $globalDeletedReservations,
            'source'               => 'cart_clear_expired.php',
        ]);
    }

    // Global: expired vouchers across all sessions (use updated_at for redeem transfers)
    $stmtVouchers = $db->prepare(
        "SELECT id, voucher_code, purchase_intent, session_id FROM vouchers
         WHERE status_id = 1
         AND updated_at < DATE_SUB(NOW(), INTERVAL 20 MINUTE)"
    );
    $stmtVouchers->execute();
    $expiredVouchers = $stmtVouchers->fetchAll(\PDO::FETCH_ASSOC);

    $globalDeletedVouchers = 0;
    $globalResetVouchers = 0;

    foreach ($expiredVouchers as $ev) {
        $evCode = $ev['voucher_code'] ?? (string)$ev['id'];
        if (($ev['purchase_intent'] ?? 'buy') === 'buy') {
            $voucherModel->delete((int)$ev['id']);
            logVoucherStatusChange('admin', $evCode, 1, null, 'cron_cleanup', [
                'voucher_id' => $ev['id'],
            ]);
            $globalDeletedVouchers++;
        } else {
            $voucherModel->update((int)$ev['id'], [
                'session_id'      => '',
                'status_id'       => 0,
                'purchase_intent' => 'buy',
            ]);
            logVoucherStatusChange('admin', $evCode, 1, 0, 'cron_cleanup', [
                'voucher_id' => $ev['id'],
            ]);
            $globalResetVouchers++;
        }
    }

    if ($globalDeletedVouchers + $globalResetVouchers > 0) {
        LoggerService::logAdmin('cron_voucher_cleanup', [
            'deleted_vouchers' => $globalDeletedVouchers,
            'reset_vouchers'   => $globalResetVouchers,
            'source'           => 'cart_clear_expired.php',
        ]);
    }

    // Heartbeat: log once per hour so it's visible the cron is running
    if ($globalDeletedReservations === 0 && $globalDeletedVouchers === 0 && $globalResetVouchers === 0) {
        $heartbeatFile = __DIR__ . '/../../logging/.cron_heartbeat';
        $lastHeartbeat = file_exists($heartbeatFile) ? (int)file_get_contents($heartbeatFile) : 0;
        if (time() - $lastHeartbeat >= 3600) {
            LoggerService::logAdmin('cron_heartbeat', [
                'message' => 'Cleanup läuft, keine abgelaufenen Einträge',
                'source'  => 'cart_clear_expired.php',
            ]);
            @file_put_contents($heartbeatFile, (string)time());
        }
    }

    echo json_encode([
        'success' => true,
        'message' => $deletedCount . ' abgelaufene Reservierung(en) wurden entfernt',
        'deleted_count' => $deletedCount,
        'released_vouchers' => $voucherCount,
        'global_deleted_reservations' => $globalDeletedReservations,
        'global_deleted_vouchers' => $globalDeletedVouchers,
        'global_reset_vouchers' => $globalResetVouchers,
    ]);
    exit;
    
} catch (\Exception $e) {
    if (ob_get_level()) {
        ob_clean();
    }
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
    exit;
}

