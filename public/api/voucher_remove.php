<?php
/**
 * API Endpoint to remove voucher from cart
 */

// Suppress any output before JSON
if (ob_get_level()) {
    ob_clean();
} else {
    ob_start();
}

require_once __DIR__ . '/../../app/bootstrap.php';

use App\Core\Session;
use App\Core\ApiGuard;
use App\Models\Voucher;

// Clear any output that might have been generated
if (ob_get_level()) {
    ob_clean();
}

// Set JSON header
header('Content-Type: application/json; charset=utf-8');

try {
    Session::start();
    ApiGuard::publicApi('voucher', 60, 60);
    $sessionId = session_id();

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new \Exception('Invalid request method');
    }

    $input = json_decode(file_get_contents('php://input'), true);
    $voucherId = isset($input['voucher_id']) ? (int)$input['voucher_id'] : 0;

    if ($voucherId <= 0) {
        throw new \Exception('Invalid voucher ID');
    }

    // Verify voucher belongs to current session
    $voucherModel = new Voucher();
    $voucher = $voucherModel->getById($voucherId);
    
    if (!$voucher || $voucher['session_id'] !== $sessionId || $voucher['status_id'] != 0) {
        throw new \Exception('Voucher not found or not in cart');
    }

    // Delete voucher
    $voucherModel->delete($voucherId);

    logVoucherStatusChange('customer', $voucher['voucher_code'] ?? '', (int)$voucher['status_id'], null, 'cart_remove', [
        'session_id' => substr($sessionId, 0, 32),
        'voucher_id' => $voucherId,
    ]);

    echo json_encode([
        'success' => true,
        'message' => 'Voucher removed from cart'
    ]);
    exit;
} catch (\Exception $e) {
    if (ob_get_level()) {
        ob_clean();
    }
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
    exit;
} catch (\Error $e) {
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

