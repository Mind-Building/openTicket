<?php
/**
 * API Endpoint to log PayPal client-side errors (declined cards, cancellations, SDK errors)
 */

if (ob_get_level()) {
    ob_clean();
} else {
    ob_start();
}

require_once __DIR__ . '/../../app/bootstrap.php';

use App\Core\Session;
use App\Core\ApiGuard;
use App\Services\LoggerService;

Session::start();

if (ob_get_level()) {
    ob_clean();
}

header('Content-Type: application/json; charset=utf-8');

ApiGuard::publicApi('payment', 60, 60);

try {
    $input = json_decode(file_get_contents('php://input'), true) ?? [];

    $sessionId = session_id();
    $type = $input['type'] ?? 'unknown'; // 'error', 'cancel', 'capture_failed', 'create_failed'
    $orderId = $input['order_id'] ?? null;
    $errorMessage = $input['error'] ?? null;
    $details = $input['details'] ?? null;

    LoggerService::logCustomer('paypal_client_error', [
        'session_id' => substr($sessionId, 0, 32),
        'type'       => $type,
        'order_id'   => $orderId,
        'error'      => $errorMessage,
        'details'    => $details,
    ], 'warning');

    echo json_encode(['success' => true]);
    exit;
} catch (\Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false]);
    exit;
}
