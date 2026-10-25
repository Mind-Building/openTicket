<?php
/**
 * API: PayPal-Zahlung einer Zahlungsanforderung bestätigen
 * Wird nach PayPal-Zustimmung des Kunden aufgerufen (JS SDK onApprove)
 */
if (ob_get_level()) ob_clean(); else ob_start();

require_once __DIR__ . '/../../app/bootstrap.php';

use App\Core\Config;
use App\Core\ApiGuard;
use App\Models\PaymentRequest;
use App\Models\User;
use App\Services\PayPalService;
use App\Services\EmailService;

if (ob_get_level()) ob_clean();
header('Content-Type: application/json; charset=utf-8');

ApiGuard::publicApi('payment', 20, 60);

try {
    $input   = json_decode(file_get_contents('php://input'), true) ?? [];
    $token   = trim($input['token']   ?? '');
    $orderId = trim($input['orderID'] ?? '');

    if (empty($token) || empty($orderId)) {
        throw new \Exception('Fehlende Parameter.');
    }

    $model = new PaymentRequest();
    $req   = $model->getByToken($token);

    if (!$req) {
        throw new \Exception('Zahlungsanforderung nicht gefunden.');
    }
    if ($req['status'] !== 'pending') {
        // Idempotenz: bereits bezahlt → trotzdem success zurückgeben
        echo json_encode(['success' => true]);
        exit;
    }

    // PayPal Capture
    $paypalService = new PayPalService();
    $capture = $paypalService->captureOrder($orderId);

    if (!$capture || ($capture['status'] ?? '') !== 'COMPLETED') {
        $status = $capture['status'] ?? 'unknown';
        throw new \Exception("PayPal Capture fehlgeschlagen (Status: $status).");
    }

    // Betrag prüfen
    $capturedAmount = (float)($capture['purchase_units'][0]['payments']['captures'][0]['amount']['value'] ?? 0);
    $expectedAmount = (float)$req['amount'];
    if ($capturedAmount < ($expectedAmount - 0.01)) {
        throw new \Exception("Bezahlter Betrag ($capturedAmount €) stimmt nicht mit angefordertem Betrag ($expectedAmount €) überein.");
    }

    // Status auf bezahlt setzen
    $model->markPaid($req['id'], $orderId);

    // Admin-Benachrichtigung an den Ersteller der Anforderung senden
    $notifyEmail = '';
    if (!empty($req['created_by'])) {
        $userModel = new User();
        $creator   = $userModel->getById((int)$req['created_by']);
        $notifyEmail = $creator['email'] ?? '';
    }
    // Fallback: globale Konfiguration
    if (empty($notifyEmail)) {
        $notifyEmail = Config::get('admin_notification_email', '')
            ?: Config::get('sender_email', '');
    }

    if (!empty($notifyEmail) && Config::get('email_enabled', true)) {
        try {
            $emailService = new EmailService();
            $emailService->sendPaymentReceivedAdminEmail(
                $notifyEmail,
                $req['customer_name'],
                $req['customer_email'],
                (float)$req['amount'],
                $req['description'],
                $req['id']
            );
        } catch (\Exception $emailEx) {
            error_log('PaymentRequest admin notification error: ' . $emailEx->getMessage());
        }
    }

    if (function_exists('logPaymentCompleted')) {
        logPaymentCompleted(
            session_id() ?: 'payment_request_' . $req['id'],
            $orderId,
            $capturedAmount,
            'EUR',
            null,
            ['payment_request_id' => $req['id'], 'customer' => $req['customer_name']]
        );
    }

    echo json_encode(['success' => true]);
} catch (\Exception $e) {
    error_log('payment_request_capture error: ' . $e->getMessage());
    if (ob_get_level()) ob_clean();
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
exit;
