<?php
/**
 * API Endpoint to clear the entire cart
 */

if (ob_get_level()) {
    ob_clean();
} else {
    ob_start();
}

require_once __DIR__ . '/../../app/bootstrap.php';

use App\Core\Session;
use App\Core\ApiGuard;
use App\Models\Reservation;
use App\Models\Voucher;

Session::start();

header('Content-Type: application/json; charset=utf-8');

ApiGuard::publicApi('cart', 90, 60);

try {
    $sessionId = session_id();
    $reservationModel = new Reservation();
    $voucherModel = new Voucher();
    
    // Delete all cart reservations (status_id = 1)
    $cartItems = $reservationModel->getBySession($sessionId);
    $deletedCount = 0;
    
    foreach ($cartItems as $item) {
        if ($reservationModel->delete($item['id'])) {
            $deletedCount++;
        }
    }
    
    // Log cart cleared
    if (function_exists('logCartCleared')) {
        $userId = $_SESSION['user_id'] ?? null;
        logCartCleared($sessionId, $deletedCount, $userId, 'user_action');
    }
    
    // Remove ALL vouchers from cart (both buy and redeem)
    $cartVouchersToBuy = $voucherModel->getBySession($sessionId, 'buy');
    $cartVouchersToRedeem = $voucherModel->getBySession($sessionId, 'redeem');
    $allCartVouchers = array_merge($cartVouchersToBuy, $cartVouchersToRedeem);
    $voucherCount = 0;
    
    foreach ($allCartVouchers as $voucher) {
        // Delete vouchers being purchased, reset vouchers being redeemed
        if (($voucher['purchase_intent'] ?? 'buy') === 'buy') {
            $voucherModel->delete($voucher['id']);
            logVoucherStatusChange('customer', $voucher['voucher_code'] ?? '', 1, null, 'cart_clear', [
                'voucher_id' => $voucher['id'],
                'session_id' => substr($sessionId, 0, 32),
            ]);
        } else {
            $voucherModel->update($voucher['id'], [
                'session_id' => '',
                'status_id' => 0,
                'purchase_intent' => 'buy'
            ]);
            logVoucherStatusChange('customer', $voucher['voucher_code'] ?? '', 1, 0, 'cart_clear', [
                'voucher_id' => $voucher['id'],
                'session_id' => substr($sessionId, 0, 32),
            ]);
        }
        $voucherCount++;
    }
    
    echo json_encode([
        'success' => true,
        'message' => 'Warenkorb erfolgreich geleert.',
        'deleted_reservations' => $deletedCount,
        'removed_vouchers' => $voucherCount
    ]);
} catch (\Exception $e) {
    error_log("Cart clear error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Fehler beim Leeren des Warenkorbs: ' . $e->getMessage()
    ]);
}
exit;

