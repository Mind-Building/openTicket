<?php
/**
 * API: PayPal Order für eine Zahlungsanforderung erstellen
 */
if (ob_get_level()) ob_clean(); else ob_start();

require_once __DIR__ . '/../../app/bootstrap.php';

use App\Core\Config;
use App\Core\ApiGuard;
use App\Models\PaymentRequest;
use App\Services\PayPalService;

if (ob_get_level()) ob_clean();
header('Content-Type: application/json; charset=utf-8');

ApiGuard::publicApi('payment', 20, 60);

try {
    if (!Config::get('paypal_active', false)) {
        throw new \Exception('PayPal ist nicht aktiviert.');
    }

    $input = json_decode(file_get_contents('php://input'), true) ?? [];
    $token = trim($input['token'] ?? '');

    if (empty($token)) {
        throw new \Exception('Kein Token angegeben.');
    }

    $model = new PaymentRequest();
    $req   = $model->getByToken($token);

    if (!$req) {
        throw new \Exception('Zahlungsanforderung nicht gefunden.');
    }
    if ($req['status'] !== 'pending') {
        throw new \Exception('Diese Zahlung wurde bereits abgeschlossen oder storniert.');
    }

    $amount = (float)$req['amount'];
    if ($amount <= 0) {
        throw new \Exception('Ungültiger Betrag.');
    }

    $baseUrl   = Config::get('ticket_url_start', absoluteUrl(''));
    $returnUrl = $baseUrl . '/api/payment_request_capture.php';
    $cancelUrl = $baseUrl . '/payment_request.php?token=' . urlencode($token);

    $paypalService = new PayPalService();
    $order = $paypalService->createOrder(
        [],                    // keine Einzelartikel – Betrag ist fix
        $amount,
        $returnUrl,
        $cancelUrl,
        'EUR'
    );

    if (!$order || empty($order['id'])) {
        throw new \Exception('PayPal-Bestellung konnte nicht erstellt werden.');
    }

    echo json_encode(['success' => true, 'orderID' => $order['id']]);
} catch (\Exception $e) {
    if (ob_get_level()) ob_clean();
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
exit;
