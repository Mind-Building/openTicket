<?php
// Start output buffering early
ob_start();

// Suppress any warnings/notices
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

require_once __DIR__ . '/../../app/bootstrap.php';

use App\Core\Session;
use App\Core\ApiGuard;
use App\Models\Reservation;
use App\Models\Voucher;

// Clear any output that might have been generated
ob_clean();

// Set JSON header
header('Content-Type: application/json; charset=utf-8');

try {
    Session::start();
    ApiGuard::publicApi('cart_read', 180, 60);
    $sessionId = session_id();

    $reservationModel = new Reservation();
    $voucherModel = new Voucher();
    $cartItems = $reservationModel->getBySession($sessionId);
    // Count ALL vouchers in cart (both buy and redeem)
    $cartVouchersToBuy = $voucherModel->getBySession($sessionId, 'buy');
    $cartVouchersToRedeem = $voucherModel->getBySession($sessionId, 'redeem');
    
    $response = ['count' => count($cartItems) + count($cartVouchersToBuy) + count($cartVouchersToRedeem)];
    ob_clean();
    echo json_encode($response);
    exit;
} catch (\Exception $e) {
    ob_clean();
    http_response_code(500);
    echo json_encode(['count' => 0, 'error' => $e->getMessage()]);
    exit;
} catch (\Error $e) {
    ob_clean();
    http_response_code(500);
    echo json_encode(['count' => 0, 'error' => $e->getMessage()]);
    exit;
} catch (\Throwable $e) {
    ob_clean();
    http_response_code(500);
    echo json_encode(['count' => 0, 'error' => $e->getMessage()]);
    exit;
}

