<?php
/**
 * API Endpoint to create PayPal order
 */

// Suppress any output before JSON
if (ob_get_level()) {
    ob_clean();
} else {
    ob_start();
}

require_once __DIR__ . '/../../app/bootstrap.php';

use App\Core\Session;
use App\Core\Config;
use App\Core\ApiGuard;
use App\Models\Reservation;
use App\Models\Voucher;
use App\Services\PayPalService;

Session::start();

// Clear any output
if (ob_get_level()) {
    ob_clean();
}

header('Content-Type: application/json; charset=utf-8');

ApiGuard::publicApi('payment', 30, 60);

try {
    if (!Config::get('paypal_active', false)) {
        throw new \Exception('PayPal is not enabled');
    }

    $sessionId = session_id();

    // Read POST body (customer data is included in PayPal flow since form is not POSTed)
    $input = json_decode(file_get_contents('php://input'), true) ?? [];
    $customerDataInput = $input['customerData'] ?? null;

    // Save customer data before creating order so capture endpoint can find it
    if ($customerDataInput && !empty($customerDataInput['first_name'])) {
        $customerModelForSave = new \App\Models\Customer();
        $existingCustomer = $customerModelForSave->getBySession($sessionId, 'ticket');
        if ($existingCustomer) {
            $customerModelForSave->update($existingCustomer['id'], array_merge($customerDataInput, [
                'session_id' => $sessionId,
                'item_type'  => 'ticket'
            ]));
        } else {
            $customerModelForSave->createOrGet(array_merge($customerDataInput, [
                'session_id' => $sessionId,
                'item_type'  => 'ticket'
            ]));
        }
    }

    $reservationModel = new Reservation();
    $voucherModel = new Voucher();
    
    // Payment is starting -> restart the expiry clock for seats and cart vouchers so
    // the cleanup cannot remove them while the customer is approving the order at
    // PayPal. Must run BEFORE the cart is read: a seat another session has taken
    // over is dropped here, and charging for it would bill a seat we cannot deliver.
    $touchResult = $reservationModel->touchCartItems($sessionId);
    $voucherModel->touchCartItems($sessionId);
    Session::resetReservationTimeout();

    if (!empty($touchResult['conflicts'])) {
        $conflictSeats = implode(', ', array_column($touchResult['conflicts'], 'seat'));
        throw new \Exception(
            'Folgende Plätze wurden inzwischen anderweitig vergeben und aus Ihrem '
            . 'Warenkorb entfernt: ' . $conflictSeats . '. Bitte laden Sie die Seite neu.'
        );
    }

    $cartItems = $reservationModel->getBySession($sessionId);

    // Price type per seat chosen at checkout (normal / reduced / fncard) - must be stored before
    // the total is calculated, otherwise PayPal charges the normal price for a reduced ticket
    // FN card chosen but number not validated: refuse instead of charging the normal price
    if (!empty($input['priceTypes']) && is_array($input['priceTypes'])
        && $reservationModel->unvalidatedFnCardItems($cartItems, $input['priceTypes'])) {
        throw new \Exception('Bitte die FN-Card-Nummer mit „Prüfen“ bestätigen – sonst kann der FN-Card-Preis nicht berechnet werden.');
    }
    if (!empty($input['priceTypes']) && is_array($input['priceTypes'])
        && $reservationModel->applyCustomerPriceTypes($cartItems, $input['priceTypes']) > 0) {
        $cartItems = $reservationModel->getBySession($sessionId);
    }
    // Get vouchers to BUY and to REDEEM
    $cartVouchersToBuy = $voucherModel->getBySession($sessionId, 'buy');
    $cartVouchersToRedeem = $voucherModel->getBySession($sessionId, 'redeem');

    // Cart must have items OR vouchers to buy
    if (empty($cartItems) && empty($cartVouchersToBuy)) {
        throw new \Exception('Cart is empty');
    }

    // Calculate total - single price rule shared with checkout, ticket, mails and order details
    $total = 0;
    foreach ($cartItems as $item) {
        $total += \App\Helpers\TicketPrice::forReservation($item)['price'];
    }
    
    // Add vouchers to BUY to total
    foreach ($cartVouchersToBuy as $voucher) {
        $total += (float)($voucher['category_price'] ?? 0) * (int)($voucher['quantity'] ?? 1);
    }

    // Calculate voucher discount from REDEEMED vouchers (subtract from total)
    $voucherDiscount = 0;
    foreach ($cartVouchersToRedeem as $voucher) {
        $voucherDiscount += (float)($voucher['category_price'] ?? 0) * (int)($voucher['quantity'] ?? 1);
    }
    $total = max(0, $total - $voucherDiscount); // Ensure total doesn't go negative

    if ($total <= 0) {
        // Log for debugging
        error_log("PayPal create order: Invalid total amount. Total: $total, Items: " . count($cartItems));
        error_log("Cart items: " . json_encode($cartItems));
        throw new \Exception('Invalid total amount: ' . $total);
    }

    // Create PayPal order
    $paypalService = new PayPalService();
    
    // Build an absolute base URL. Prefer the admin-configured ticket_url_start,
    // but fall back to a reconstructed absolute URL when it is empty/unset.
    // (Config::get's default only applies for missing/null keys, NOT for an
    // empty-string value, so handle the empty string explicitly.)
    $baseUrl = rtrim((string)Config::get('ticket_url_start', ''), '/');
    if ($baseUrl === '') {
        $baseUrl = rtrim(absoluteUrl(''), '/');
    }

    // PayPal requires absolute URLs (with scheme + host) for return/cancel.
    $paypalReturnUrl = (string)Config::get('paypal_return_url', '');
    $returnUrl = $paypalReturnUrl !== '' ? $paypalReturnUrl : $baseUrl . '/api/paypal_success.php';

    $cancelUrl = $baseUrl . '/api/paypal_cancel.php?canceled=1';

    // Collect REDEEMED voucher codes for PayPal description
    $voucherCodes = array_column($cartVouchersToRedeem, 'voucher_code');
    
    $order = $paypalService->createOrder($cartItems, $total, $returnUrl, $cancelUrl, 'EUR', $voucherCodes);
    
    if (!$order || empty($order['id'])) {
        throw new \Exception('Failed to create PayPal order');
    }

    // Store order ID in session
    $_SESSION['paypal_order_id'] = $order['id'];
    $_SESSION['paypal_order_total'] = $total;

    // Return order ID as JSON for JavaScript SDK
    echo json_encode([
        'success' => true,
        'orderID' => $order['id']
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

