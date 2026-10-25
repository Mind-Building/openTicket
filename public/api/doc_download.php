<?php
/**
 * Liefert ein Handbuch-PDF aus docs/manuals/ aus.
 * Auth-Check je nach Datei: Vorverkauf-Handbuch ist für presales+admin,
 * alle anderen nur für admin.
 */

if (ob_get_level()) {
    ob_clean();
} else {
    ob_start();
}

require_once __DIR__ . '/../../app/bootstrap.php';

use App\Core\Auth;
use App\Core\Session;

Session::start();

if (!Auth::check()) {
    http_response_code(403);
    echo 'Nicht autorisiert';
    exit;
}

$file = basename((string)($_GET['file'] ?? ''));
$inline = !empty($_GET['inline']);

// Allowlist: Datei => erlaubte Rollen
$allowed = [
    'ADMIN_HANDBUCH.pdf'      => ['admin'],
    'VORVERKAUF_HANDBUCH.pdf' => ['admin', 'presales'],
];

if (!isset($allowed[$file])) {
    http_response_code(404);
    echo 'Dokument nicht gefunden';
    exit;
}

if (!in_array(Auth::role(), $allowed[$file], true)) {
    http_response_code(403);
    echo 'Keine Berechtigung für dieses Dokument';
    exit;
}

$path = __DIR__ . '/../../docs/manuals/' . $file;
if (!is_readable($path)) {
    http_response_code(404);
    echo 'Datei nicht verfügbar';
    exit;
}

if (ob_get_level()) {
    ob_end_clean();
}

header('Content-Type: application/pdf');
header('Content-Length: ' . filesize($path));
header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . '; filename="' . $file . '"');
header('Cache-Control: private, max-age=0');
readfile($path);
exit;
