<?php
/**
 * API Endpoint to redeem a voucher code
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
use App\Models\Reservation;

// Clear any output that might have been generated
if (ob_get_level()) {
    ob_clean();
}

// Set JSON header
header('Content-Type: application/json; charset=utf-8');

try {
    Session::start();
    ApiGuard::publicApi('voucher_redeem', 20, 60);
    $sessionId = session_id();

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new \Exception('Invalid request method');
    }

    $rawInput = file_get_contents('php://input');
    $input = json_decode($rawInput, true);
    
    // Check for JSON decode errors
    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new \Exception('Ungültige JSON-Daten: ' . json_last_error_msg());
    }
    
    $voucherCode = isset($input['voucher_code']) ? trim(strtoupper($input['voucher_code'])) : '';

    if (empty($voucherCode)) {
        throw new \Exception('Bitte geben Sie einen Gutscheincode ein.');
    }

    // Log redemption attempt
    $userId = $_SESSION['user_id'] ?? null;
    if (function_exists('logVoucherRedeemAttempt')) {
        logVoucherRedeemAttempt($sessionId, $voucherCode, $userId);
    }

    // Find voucher by code
    $voucherModel = new Voucher();
    $voucher = $voucherModel->getByCode($voucherCode);

    if (!$voucher) {
        error_log("Voucher redeem - Voucher not found for code: " . $voucherCode);
        throw new \Exception('Gutscheincode nicht gefunden.');
    }

    error_log("Voucher redeem - Found voucher ID: " . $voucher['id'] . ", status_id: " . $voucher['status_id'] . ", session_id: " . ($voucher['session_id'] ?? 'NULL'));

    // Check voucher status:
    // Status 0 = issued by presales (not yet redeemed, can be redeemed)
    // Status 1 = in cart
    // Status 2 = paid via checkout (can be redeemed)
    // Status 3 = redeemed / used (cannot be redeemed again)
    $redeemableStatuses = [0, 2]; // 0 = presales-issued, 2 = paid via checkout
    if (!in_array((int)$voucher['status_id'], $redeemableStatuses)) {
        if ((int)$voucher['status_id'] === 1) {
            throw new \Exception('Dieser Gutschein befindet sich noch im Warenkorb und wurde noch nicht bezahlt.');
        }
        if ((int)$voucher['status_id'] === 3) {
            throw new \Exception('Dieser Gutschein wurde bereits eingelöst und kann nicht erneut verwendet werden.');
        }
        throw new \Exception('Dieser Gutschein ist nicht gültig.');
    }

    // Check if voucher is already in current session's cart for redemption
    $existingRedeemVouchers = $voucherModel->getBySession($sessionId, 'redeem');
    foreach ($existingRedeemVouchers as $existingVoucher) {
        if ($existingVoucher['id'] == $voucher['id']) {
            throw new \Exception('Dieser Gutschein befindet sich bereits in Ihrem Warenkorb.');
        }
    }

    // Save original status and session before transferring
    // This allows tracking which voucher was used for which purchase
    $updateData = [
        'transformed_session_id' => $voucher['session_id'] ?? null, // Original session
        'transformed_status_id' => $voucher['status_id'], // Original status (2 = paid)
        'session_id' => $sessionId, // New session (current customer)
        'purchase_intent' => 'redeem', // Customer wants to REDEEM this voucher
        'status_id' => 1, // In cart status (temporarily, will be used when paying)
    ];
    
    // Transfer voucher to current session (add to cart for redemption)
    $updateResult = $voucherModel->update($voucher['id'], $updateData);

    if (!$updateResult) {
        throw new \Exception('Fehler beim Aktualisieren des Gutscheins.');
    }

    logVoucherStatusChange('customer', $voucherCode, (int)$voucher['status_id'], 1, 'redeem_to_cart', [
        'session_id' => substr($sessionId, 0, 32),
        'voucher_id' => $voucher['id'],
    ]);
    
    // Log voucher applied
    if (function_exists('logVoucherApplied')) {
        $userId = $_SESSION['user_id'] ?? null;
        $discount = (float)($voucher['category_price'] ?? 0);
        logVoucherApplied($sessionId, $voucherCode, $discount, $userId);
    }
    
    // Reset countdown for all cart items when voucher is added
    $reservationModel = new Reservation();
    $allCartItems = $reservationModel->getBySession($sessionId);
    if (!empty($allCartItems)) {
        $connection = $reservationModel->getConnection();
        foreach ($allCartItems as $item) {
            $sql = "UPDATE reservations SET created_at = NOW() WHERE id = :id";
            $stmt = $connection->prepare($sql);
            $stmt->execute([':id' => $item['id']]);
        }
    }

    echo json_encode([
        'success' => true,
        'message' => 'Gutschein wurde erfolgreich zum Warenkorb hinzugefügt.',
        'voucher' => [
            'id' => $voucher['id'],
            'code' => $voucher['voucher_code'],
            'category_name' => $voucher['category_name'] ?? '',
            'event_name' => $voucher['event_name'] ?? '',
        ]
    ]);
    exit;
} catch (\Exception $e) {
    if (ob_get_level()) {
        ob_clean();
    }
    // Log failed redemption attempt (only if a voucher code was provided)
    if (!empty($voucherCode ?? '') && function_exists('logVoucherRedeemFailed')) {
        logVoucherRedeemFailed($sessionId ?? session_id(), $voucherCode, $e->getMessage(), $_SESSION['user_id'] ?? null);
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

