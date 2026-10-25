<?php
/**
 * PayPal Cancel Callback
 * Handles cancelled PayPal payment
 */

require_once __DIR__ . '/../../app/bootstrap.php';

use App\Core\Session;
use App\Core\Config;
use App\Models\Reservation;
use App\Models\Voucher;

Session::start();

$sessionId = session_id();
$paypalOrderId = $_SESSION['paypal_order_id'] ?? null;

// Log payment cancelled
if (function_exists('logPaymentCancelled') && $paypalOrderId) {
    $userId = $_SESSION['user_id'] ?? null;
    $reason = $_GET['canceled'] ?? 'user_cancelled';
    logPaymentCancelled($sessionId, $paypalOrderId, $userId, $reason);
}

// Clear PayPal session data
unset($_SESSION['paypal_order_id']);
unset($_SESSION['paypal_order_total']);

// Redirect back to checkout with cancelled message
header('Location: ' . url('checkout.php?canceled=1'));
exit;
