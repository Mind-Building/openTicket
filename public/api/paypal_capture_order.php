<?php
/**
 * API Endpoint to capture PayPal order (called by JavaScript SDK)
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
use App\Models\Customer;
use App\Models\Voucher;
use App\Models\Event;
use App\Services\PayPalService;
use App\Services\EmailService;
use App\Services\LoggerService;

Session::start();
ApiGuard::publicApi('payment', 30, 60);

// Clear any output
if (ob_get_level()) {
    ob_clean();
}

header('Content-Type: application/json; charset=utf-8');

try {
    // Get order ID from POST
    $input = json_decode(file_get_contents('php://input'), true);
    $orderId = $input['orderID'] ?? null;

    if (!$orderId) {
        throw new \Exception('No order ID provided');
    }

    $sessionId = session_id();

    // Debug logging
    error_log("PayPal Capture - Session ID: " . $sessionId);
    error_log("PayPal Capture - Order ID: " . $orderId);

    // Capture PayPal order
    $paypalService = new PayPalService();
    $captureResult = $paypalService->captureOrder($orderId);

    if (!$captureResult || $captureResult['status'] !== 'COMPLETED') {
        $errorMessage = 'Payment not completed';
        if (isset($captureResult['message'])) {
            $errorMessage .= ': ' . $captureResult['message'];
        }
        throw new \Exception($errorMessage);
    }

    // Verify captured amount matches the amount we created the order for
    $expectedTotal = $_SESSION['paypal_order_total'] ?? null;
    if ($expectedTotal !== null) {
        $capturedAmount = (float)($captureResult['purchase_units'][0]['payments']['captures'][0]['amount']['value'] ?? 0);
        if (abs($capturedAmount - (float)$expectedTotal) > 0.01) {
            error_log("PayPal amount mismatch: expected {$expectedTotal}, captured {$capturedAmount}, orderID={$orderId}");
            throw new \Exception('Zahlungsbetrag stimmt nicht überein. Bitte Support kontaktieren.');
        }
    }

    $reservationModel = new Reservation();
    $customerModel = new Customer();
    $voucherModel = new Voucher();

    // Get cart items
    $cartItems = $reservationModel->getBySession($sessionId);
    $cartVouchersToBuy = $voucherModel->getBySession($sessionId, 'buy');
    $cartVouchersToRedeem = $voucherModel->getBySession($sessionId, 'redeem');

    // Check if cart is empty
    if (empty($cartItems) && empty($cartVouchersToBuy)) {
        throw new \Exception('No items in cart');
    }

    // Get customer
    $customer = null;

    // Try multiple methods to find customer
    if (!$customer && !empty($cartVouchersToBuy)) {
        $firstVoucher = reset($cartVouchersToBuy);
        if (!empty($firstVoucher['customer_id'])) {
            $customer = $customerModel->getById((int)$firstVoucher['customer_id']);
        }
    }

    if (!$customer && !empty($cartItems)) {
        $firstItem = reset($cartItems);
        if (!empty($firstItem['customer_id'])) {
            $customer = $customerModel->getById((int)$firstItem['customer_id']);
        }
    }

    if (!$customer) {
        $customer = $customerModel->getBySession($sessionId, 'ticket');
        if (!$customer) {
            $customer = $customerModel->getBySession($sessionId, 'voucher');
        }
    }

    if (!$customer) {
        throw new \Exception('Customer not found');
    }

    // Pre-generate ticket and voucher links BEFORE any DB updates.
    // If token generation fails (e.g. APP_SECRET missing), an exception is thrown here
    // and the cart stays intact – no reservations are marked as paid yet.
    $reservationIds = array_column($cartItems, 'id');
    $voucherIdsList = array_column($cartVouchersToBuy, 'id');

    $ticketLink = null;
    if (!empty($reservationIds)) {
        $baseUrl = Config::get('ticket_url_start', absoluteUrl(''));
        $baseUrl = ensurePublicInUrl($baseUrl);
        $token = generateTicketDownloadToken($reservationIds);
        $reservationIdsParam = implode(',', $reservationIds);
        $ticketLink = $baseUrl . '/api/ticket_download.php?reservation_ids=' . $reservationIdsParam . '&token=' . $token;
    }

    $emailEnabled = Config::get('email_enabled', true);
    $customerEmail = $customer['email'] ?? '';
    $customerName = trim(($customer['first_name'] ?? '') . ' ' . ($customer['last_name'] ?? ''));
    $hasTickets = !empty($cartItems) && $ticketLink;
    $hasVouchers = !empty($cartVouchersToBuy);

    $voucherLink = null;
    $voucherUseTemplate = false;
    if ($hasVouchers) {
        $firstVoucher = reset($cartVouchersToBuy);
        if (!empty($firstVoucher['event_id'])) {
            $eventModel = new Event();
            $event = $eventModel->getById((int)$firstVoucher['event_id']);
            if ($event && !empty($event['voucher_use_template'])) {
                $voucherUseTemplate = true;
                $baseUrl = Config::get('ticket_url_start', absoluteUrl(''));
                $baseUrl = ensurePublicInUrl($baseUrl);
                $token = generateVoucherDownloadToken($voucherIdsList);
                $voucherIdsParam = implode(',', $voucherIdsList);
                $voucherLink = $baseUrl . '/api/voucher_download.php?voucher_ids=' . $voucherIdsParam . '&token=' . $token;
            } else {
                $baseUrl = Config::get('ticket_url_start', absoluteUrl(''));
                $baseUrl = ensurePublicInUrl($baseUrl);
                $voucherLink = $baseUrl . '/vouchers.php?code=' . urlencode($firstVoucher['voucher_code']);
            }
        }
    }

    // All pre-checks passed – now update DB
    $redeemVoucherIds = array_column($cartVouchersToRedeem, 'id');
    $voucherIndex = 0;

    // Update reservations to paid status
    foreach ($cartItems as $item) {
        $updateData = [
            'customer_id' => $customer['id'],
            'status_id' => 2 // Paid via PayPal
        ];

        // Assign voucher if available
        if (!empty($redeemVoucherIds) && $voucherIndex < count($redeemVoucherIds)) {
            $updateData['voucher_id'] = $redeemVoucherIds[$voucherIndex];
            $voucherIndex++;
        }

        $reservationModel->update($item['id'], $updateData);
    }

    // Mark REDEEMED vouchers as used
    foreach ($cartVouchersToRedeem as $voucher) {
        $voucherModel->update($voucher['id'], [
            'status_id' => 3, // Redeemed/used as discount
            'customer_id' => $customer['id']
        ]);
        logVoucherStatusChange('customer', $voucher['voucher_code'] ?? '', 1, 3, 'paypal_capture', [
            'voucher_id' => $voucher['id'],
            'session_id' => substr($sessionId ?? '', 0, 32),
        ]);
    }

    // Mark PURCHASED vouchers as paid
    foreach ($cartVouchersToBuy as $voucher) {
        $voucherModel->update($voucher['id'], [
            'status_id' => 2, // Paid
            'customer_id' => $customer['id']
        ]);
        logVoucherStatusChange('customer', $voucher['voucher_code'] ?? '', 1, 2, 'paypal_capture', [
            'voucher_id' => $voucher['id'],
            'session_id' => substr($sessionId ?? '', 0, 32),
        ]);
    }

    LoggerService::logCustomer(LoggerService::ACTION_PAYMENT_COMPLETED, [
        'order_id'        => $orderId,
        'session_id'      => substr($sessionId ?? '', 0, 32),
        'customer_id'     => $customer['id'],
        'reservation_ids' => $reservationIds,
        'amount'          => $capturedAmount ?? (float)($expectedTotal ?? 0),
        'currency'        => 'EUR',
        'payment_method'  => 'paypal',
        'new_status_id'   => 2,
    ]);

    // Send email
    $emailSent = false;
    if ($emailEnabled && !empty($customerEmail)) {
        try {
            $emailService = new EmailService();
            if (!isset($eventModel)) {
                $eventModel = new Event();
            }

            // Send combined email only when BOTH tickets AND vouchers are present
            if ($hasTickets && $hasVouchers) {
                $firstCartItem = reset($cartItems);
                $eventIdForEmail = $firstCartItem['event_id'] ?? null;
                $emailService->sendCombinedPurchaseEmail(
                    $customerEmail,
                    $customerName ?: $customerEmail,
                    $cartItems,
                    $cartVouchersToBuy,
                    $ticketLink,
                    $voucherLink,
                    $voucherUseTemplate,
                    $eventIdForEmail
                );
            } else {
                if ($hasTickets) {
                    $firstCartItem = reset($cartItems);
                    $eventIdForEmail = $firstCartItem['event_id'] ?? null;
                    $emailService->sendTicketEmail(
                        $customerEmail,
                        $customerName ?: $customerEmail,
                        $cartItems,
                        $ticketLink,
                        $eventIdForEmail
                    );
                }

                if ($hasVouchers) {
                    $emailService->sendVoucherEmail(
                        $customerEmail,
                        $customerName ?: $customerEmail,
                        $cartVouchersToBuy,
                        $voucherLink,
                        $voucherUseTemplate
                    );
                }
            }
            $emailSent = true;
        } catch (\Exception $emailException) {
            error_log("PayPal Capture - Email error: " . $emailException->getMessage());
        }
    }

    // Clear PayPal session data
    unset($_SESSION['paypal_order_id']);
    unset($_SESSION['paypal_order_total']);

    // Return success response with redirect URL
    $reservationIdsParam = !empty($reservationIds) ? '&reservation_ids=' . implode(',', $reservationIds) : '';
    $voucherIdsParam = !empty($voucherIdsList) ? '&voucher_ids=' . implode(',', $voucherIdsList) : '';
    $emailParam = $emailEnabled && !empty($customerEmail) ? ($emailSent ? '&email=sent' : '&email=failed') : '&email=disabled';
    $redirectUrl = url('checkout_success.php?order_id=' . $orderId . $reservationIdsParam . $voucherIdsParam . $emailParam);

    echo json_encode([
        'success' => true,
        'redirectUrl' => $redirectUrl
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
