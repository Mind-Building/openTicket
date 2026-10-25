<?php
/**
 * PayPal Success Callback
 * Handles successful PayPal payment
 */

require_once __DIR__ . '/../../app/bootstrap.php';

use App\Core\Session;
use App\Core\Config;
use App\Models\Reservation;
use App\Models\Customer;
use App\Models\Voucher;
use App\Models\Event;
use App\Services\PayPalService;
use App\Services\EmailService;
use App\Services\LoggerService;

Session::start();

$sessionId = session_id();
$orderId = $_GET['token'] ?? $_GET['PayerID'] ?? null;
$paypalOrderId = $_SESSION['paypal_order_id'] ?? null;

if (!$orderId || !$paypalOrderId) {
    LoggerService::logCustomer('paypal_error', [
        'session_id' => $sessionId,
        'error' => 'Missing order ID or PayPal order ID',
    ], 'error');
    header('Location: ' . url('checkout.php?error=invalid'));
    exit;
}

try {
    // Capture PayPal order
    $paypalService = new PayPalService();
    $captureResult = $paypalService->captureOrder($paypalOrderId);

    if (!$captureResult || $captureResult['status'] !== 'COMPLETED') {
        $errorMessage = 'Payment not completed';
        if (isset($captureResult['message'])) {
            $errorMessage .= ': ' . $captureResult['message'];
        }
        LoggerService::logCustomer('paypal_capture_failed', [
            'session_id' => $sessionId,
            'paypal_order_id' => $paypalOrderId,
            'status' => $captureResult['status'] ?? 'unknown',
            'error' => $errorMessage,
        ], 'error');
        header('Location: ' . url('checkout.php?error=' . urlencode($errorMessage)));
        exit;
    }

    $reservationModel = new Reservation();
    $customerModel = new Customer();
    $voucherModel = new Voucher();

    // Get cart items (status_id = 1, in cart) BEFORE updating status
    $cartItems = $reservationModel->getBySession($sessionId);
    $cartVouchersToBuy = $voucherModel->getBySession($sessionId, 'buy');
    $cartVouchersToRedeem = $voucherModel->getBySession($sessionId, 'redeem');

    // Check if cart is empty (no reservations AND no vouchers to buy)
    if (empty($cartItems) && empty($cartVouchersToBuy)) {
        // Try to get paid reservations if cart is empty (maybe already paid - double submit)
        $allReservations = $reservationModel->getAllBySession($sessionId);
        $paidReservations = array_filter($allReservations, function($r) {
            return (int)$r['status_id'] === 2; // Paid
        });
        if (!empty($paidReservations)) {
            // Already paid, redirect to success page
            $reservationIds = array_column($paidReservations, 'id');
            $reservationIdsParam = '&reservation_ids=' . implode(',', $reservationIds);
            header('Location: ' . url('checkout_success.php?order_id=' . $paypalOrderId . $reservationIdsParam));
            exit;
        }
        throw new \Exception('No items in cart');
    }

    // Find customer via multiple lookup methods
    $customer = null;

    // Method 1: Check if voucher already has customer_id
    if (!$customer && !empty($cartVouchersToBuy)) {
        $firstVoucher = reset($cartVouchersToBuy);
        if (!empty($firstVoucher['customer_id'])) {
            $customer = $customerModel->getById((int)$firstVoucher['customer_id']);
        }
    }

    // Method 2: Check if reservation already has customer_id
    if (!$customer && !empty($cartItems)) {
        $firstItem = reset($cartItems);
        if (!empty($firstItem['customer_id'])) {
            $customer = $customerModel->getById((int)$firstItem['customer_id']);
        }
    }

    // Method 3: Try to find customer by session
    if (!$customer) {
        $customer = $customerModel->getBySession($sessionId, 'ticket');
        if (!$customer) {
            $customer = $customerModel->getBySession($sessionId, 'voucher');
        }
    }

    // Method 4: Try alternative session lookup
    if (!$customer) {
        $customer = $customerModel->getBySessionId($sessionId);
    }

    // Method 5: Try to find customer from any reservation in cart
    if (!$customer && !empty($cartItems)) {
        foreach ($cartItems as $item) {
            if (!empty($item['customer_id'])) {
                $customer = $customerModel->getById((int)$item['customer_id']);
                if ($customer) break;
            }
        }
    }

    // Method 6: Try to find customer from any voucher in cart
    if (!$customer && !empty($cartVouchersToBuy)) {
        foreach ($cartVouchersToBuy as $voucher) {
            if (!empty($voucher['customer_id'])) {
                $customer = $customerModel->getById((int)$voucher['customer_id']);
                if ($customer) break;
            }
        }
    }

    if (!$customer) {
        LoggerService::logCustomer('paypal_customer_not_found', [
            'session_id' => $sessionId,
            'paypal_order_id' => $paypalOrderId,
            'cart_items_count' => count($cartItems),
            'vouchers_count' => count($cartVouchersToBuy),
        ], 'error');
        header('Location: ' . url('checkout.php?error=' . urlencode('Kundendaten nicht gefunden. Bitte versuchen Sie es erneut.')));
        exit;
    }

    // Assign REDEEMED vouchers to reservations (distribute evenly or to first reservations)
    $voucherIds = array_column($cartVouchersToRedeem, 'id');
    $voucherIndex = 0;

    // Update reservations to paid status and assign vouchers
    $reservationIds = [];
    foreach ($cartItems as $item) {
        $updateData = [
            'customer_id' => $customer['id'],
            'status_id' => 2 // Paid via PayPal
        ];

        // Assign voucher if available (one voucher per reservation, round-robin)
        if (!empty($voucherIds) && $voucherIndex < count($voucherIds)) {
            $updateData['voucher_id'] = $voucherIds[$voucherIndex];
            $voucherIndex++;
        }

        $reservationModel->update($item['id'], $updateData);
        $reservationIds[] = $item['id'];
    }

    // Mark REDEEMED vouchers as used (status_id = 3 = redeemed/used)
    foreach ($cartVouchersToRedeem as $voucher) {
        $voucherModel->update($voucher['id'], [
            'status_id' => 3,
            'customer_id' => $customer['id']
        ]);
        logVoucherStatusChange('customer', $voucher['voucher_code'] ?? '', 1, 3, 'paypal_success', [
            'voucher_id' => $voucher['id'],
            'session_id' => substr($sessionId ?? '', 0, 32),
        ]);
    }

    // Mark PURCHASED vouchers as paid (status_id = 2 = paid)
    foreach ($cartVouchersToBuy as $voucher) {
        $voucherModel->update($voucher['id'], [
            'status_id' => 2,
            'customer_id' => $customer['id']
        ]);
        logVoucherStatusChange('customer', $voucher['voucher_code'] ?? '', 1, 2, 'paypal_success', [
            'voucher_id' => $voucher['id'],
            'session_id' => substr($sessionId ?? '', 0, 32),
        ]);
    }

    // Log successful payment
    LoggerService::logCustomer('paypal_payment_completed', [
        'session_id' => $sessionId,
        'paypal_order_id' => $paypalOrderId,
        'customer_id' => $customer['id'],
        'reservation_count' => count($reservationIds),
        'voucher_buy_count' => count($cartVouchersToBuy),
        'voucher_redeem_count' => count($cartVouchersToRedeem),
    ]);

    // Generate ticket link with token (only if there are reservations)
    $ticketLink = null;
    if (!empty($reservationIds)) {
        $baseUrl = Config::get('ticket_url_start', absoluteUrl(''));
        $baseUrl = ensurePublicInUrl($baseUrl);
        $token = generateTicketDownloadToken($reservationIds);
        $reservationIdsParam = implode(',', $reservationIds);
        $ticketLink = $baseUrl . '/api/ticket_download.php?reservation_ids=' . $reservationIdsParam . '&token=' . $token;
    }

    // Send email
    $emailEnabled = Config::get('email_enabled', true);
    $customerEmail = $customer['email'] ?? '';

    if ($emailEnabled && !empty($customerEmail)) {
        try {
            $emailService = new EmailService();
            $eventModel = new Event();
            $customerName = trim(($customer['first_name'] ?? '') . ' ' . ($customer['last_name'] ?? ''));

            $hasTickets = !empty($cartItems) && $ticketLink;
            $hasVouchers = !empty($cartVouchersToBuy);

            // Determine voucher link and template usage
            $voucherLink = null;
            $voucherUseTemplate = false;
            if ($hasVouchers) {
                $firstVoucher = reset($cartVouchersToBuy);
                if (!empty($firstVoucher['event_id'])) {
                    $event = $eventModel->getById((int)$firstVoucher['event_id']);
                    if ($event && !empty($event['voucher_use_template'])) {
                        $voucherUseTemplate = true;
                        $baseUrl = Config::get('ticket_url_start', absoluteUrl(''));
                        $baseUrl = ensurePublicInUrl($baseUrl);
                        $voucherIds = array_column($cartVouchersToBuy, 'id');
                        $secret = 'voucher_download_secret_' . date('Y-m-d');
                        $token = hash('sha256', $voucherIds[0] . $secret);
                        $voucherIdsParam = implode(',', $voucherIds);
                        $voucherLink = $baseUrl . '/api/voucher_download.php?voucher_ids=' . $voucherIdsParam . '&token=' . $token;
                    } else {
                        $baseUrl = Config::get('ticket_url_start', absoluteUrl(''));
                        $baseUrl = ensurePublicInUrl($baseUrl);
                        $voucherLink = $baseUrl . '/vouchers.php?code=' . urlencode($firstVoucher['voucher_code']);
                    }
                }
            }

            // Send combined email if both tickets and vouchers, or multiple vouchers
            if (($hasTickets && $hasVouchers) || ($hasVouchers && count($cartVouchersToBuy) > 1)) {
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
            LoggerService::logCustomer('paypal_email_sent', [
                'session_id' => $sessionId,
                'paypal_order_id' => $paypalOrderId,
                'customer_email' => $customerEmail,
                'has_tickets' => $hasTickets,
                'has_vouchers' => $hasVouchers,
            ]);
        } catch (\Exception $emailException) {
            // Don't fail the payment if email fails
            LoggerService::logCustomer('paypal_email_error', [
                'session_id' => $sessionId,
                'paypal_order_id' => $paypalOrderId,
                'error' => $emailException->getMessage(),
            ], 'error');
        }
    } else {
        LoggerService::logCustomer('paypal_email_skipped', [
            'session_id' => $sessionId,
            'paypal_order_id' => $paypalOrderId,
            'reason' => !$emailEnabled ? 'email_disabled' : 'no_customer_email',
        ], 'warning');
    }

    // Clear PayPal session data
    unset($_SESSION['paypal_order_id']);
    unset($_SESSION['paypal_order_total']);

    // Clear any cart session data
    if (isset($_SESSION['cart'])) {
        unset($_SESSION['cart']);
    }

    // Clear reservation timer
    Session::clearReservationTimeout();

    // Redirect to success page with reservation IDs for ticket download
    $reservationIdsParam = !empty($reservationIds) ? '&reservation_ids=' . implode(',', $reservationIds) : '';
    $voucherIdsParam = !empty($cartVouchersToBuy) ? '&voucher_ids=' . implode(',', array_column($cartVouchersToBuy, 'id')) : '';
    header('Location: ' . url('checkout_success.php?order_id=' . $paypalOrderId . $reservationIdsParam . $voucherIdsParam));
    exit;
} catch (\Exception $e) {
    LoggerService::logCustomer('paypal_success_error', [
        'session_id' => $sessionId,
        'paypal_order_id' => $paypalOrderId ?? 'unknown',
        'error' => $e->getMessage(),
    ], 'error');
    header('Location: ' . url('checkout.php?error=' . urlencode($e->getMessage())));
    exit;
}
