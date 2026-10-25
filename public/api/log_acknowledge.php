<?php
/**
 * Toggle log entry acknowledgement (admin only)
 * POST: hash, action (acknowledge|unacknowledge)
 */
require_once __DIR__ . '/../../app/bootstrap.php';

use App\Core\Auth;
use App\Core\Session;

Session::start();
header('Content-Type: application/json');

if (!Auth::check() || Auth::role() !== 'admin') {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Nicht autorisiert']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    echo json_encode(['success' => false, 'error' => 'Method Not Allowed']);
    exit;
}

$hash   = trim($_POST['hash']   ?? '');
$action = trim($_POST['action'] ?? '');

if (!preg_match('/^[a-f0-9]{32}$/', $hash) || !in_array($action, ['acknowledge', 'unacknowledge'], true)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Ungültige Parameter']);
    exit;
}

$file = __DIR__ . '/../../logging/acknowledged.json';
$data = [];
if (is_readable($file)) {
    $data = json_decode(file_get_contents($file), true) ?: [];
}

if ($action === 'acknowledge') {
    $data[$hash] = ['by' => Auth::username() ?? 'admin', 'at' => date('Y-m-d H:i:s')];
} else {
    unset($data[$hash]);
}

file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT), LOCK_EX);

echo json_encode(['success' => true, 'acknowledged' => isset($data[$hash])]);
exit;
