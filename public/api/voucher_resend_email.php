<?php
/**
 * API Endpoint to resend voucher email
 * Auth: admin or presales
 */

require_once __DIR__ . '/../../app/bootstrap.php';

use App\Core\Auth;
use App\Core\Config;
use App\Models\Voucher;
use App\Models\Customer;
use App\Services\EmailService;

Auth::requireAuth();
if (!Auth::isAdmin() && !Auth::isPresales()) {
    http_response_code(403);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'error' => 'Keine Berechtigung']);
    exit;
}

header('Content-Type: application/json; charset=utf-8');

try {
    $data = json_decode(file_get_contents('php://input'), true);
    $voucherId = (int)($data['voucher_id'] ?? 0);

    if ($voucherId <= 0) {
        throw new \Exception('Ungültige Gutschein-ID');
    }

    $voucherModel = new Voucher();
    $voucher = $voucherModel->getById($voucherId);

    if (!$voucher) {
        throw new \Exception('Gutschein nicht gefunden');
    }

    if (empty($voucher['customer_id'])) {
        throw new \Exception('Diesem Gutschein ist kein Kunde zugeordnet');
    }

    $customerModel = new Customer();
    $customer = $customerModel->getById((int)$voucher['customer_id']);

    if (!$customer || empty($customer['email'])) {
        throw new \Exception('Kein Kunde mit E-Mail-Adresse gefunden');
    }

    if (!Config::get('email_enabled', true)) {
        throw new \Exception('E-Mail-Versand ist in den Einstellungen deaktiviert');
    }

    $baseUrl = Config::get('ticket_url_start', absoluteUrl(''));
    $baseUrl = ensurePublicInUrl($baseUrl);
    $voucherLink = $baseUrl . '/vouchers.php';

    $emailService = new EmailService();
    $name = trim(($customer['first_name'] ?? '') . ' ' . ($customer['last_name'] ?? ''));
    $sent = $emailService->sendVoucherEmail($customer['email'], $name ?: $customer['email'], $voucher, $voucherLink);

    if (!$sent) {
        throw new \Exception('E-Mail konnte nicht gesendet werden');
    }

    $voucherModel->update($voucherId, [
        'mail_sent'      => 1,
        'mail_sent_date' => date('Y-m-d'),
    ]);

    echo json_encode([
        'success' => true,
        'message' => 'E-Mail wurde erneut an ' . htmlspecialchars($customer['email']) . ' gesendet',
    ]);

} catch (\Exception $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
