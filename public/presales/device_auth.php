<?php
/**
 * Device Token Activation
 * Visited once per terminal to set the presales device token cookie.
 * After this the terminal can access presales pages without a password.
 */

require_once __DIR__ . '/../../app/bootstrap.php';

use App\Core\Session;
use App\Models\DeviceToken;

Session::start();

$token = $_GET['token'] ?? '';

if (empty($token)) {
    http_response_code(400);
    echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Fehler</title></head><body><p>Kein Token angegeben.</p></body></html>';
    exit;
}

$deviceTokenModel = new DeviceToken();
$record = $deviceTokenModel->findByToken($token);

if (!$record) {
    http_response_code(403);
    echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Fehler</title></head><body><p>Token ungültig, abgelaufen oder widerrufen.</p></body></html>';
    exit;
}

// Cookie lifetime = token expiry, or 1 year if no expiry set
$cookieExpiry = $record['expires_at']
    ? strtotime($record['expires_at'])
    : time() + (365 * 24 * 3600);

$secure = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';

setcookie('presales_device_token', $token, [
    'expires'  => $cookieExpiry,
    'path'     => '/',
    'secure'   => $secure,
    'httponly' => true,
    'samesite' => 'Lax',
]);

// Log activation
if (function_exists('logPresalesLogin')) {
    logPresalesLogin((string)$record['user_id'], $record['username'], true, 'device_token_activated');
}

$deviceTokenModel->updateLastUsed((int)$record['id']);

// Redirect to presales dashboard
header('Location: ' . url('presales/'));
exit;
