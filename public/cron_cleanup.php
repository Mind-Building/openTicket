<?php
/**
 * Public entry point for the cart cleanup cron, for hosters that only offer URL cron jobs.
 * Runs app/scripts/cleanup_expired_reservations.php (expired carts/vouchers, finished events,
 * hourly cron_heartbeat). Call every 5 minutes.
 *
 * HTTP: https://domain.de/cron_cleanup.php?secret=<CRON_SECRET>
 * CLI:  php public/cron_cleanup.php   (same as the script itself)
 *
 * Set CRON_SECRET in .env. Leave empty to disable HTTP access entirely.
 * Answers JSON: 200 on success, 403 on a wrong/missing secret, 500 on an error.
 */
require_once __DIR__ . '/../app/bootstrap.php';

if (PHP_SAPI !== 'cli') {
    header('Cache-Control: no-store');
    header('X-Robots-Tag: noindex');

    $expectedSecret = (string)($_ENV['CRON_SECRET'] ?? '');
    $provided = (string)($_GET['secret'] ?? '');
    if ($expectedSecret === '' || !hash_equals($expectedSecret, $provided)) {
        http_response_code(403);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'success' => false,
            'error'   => $expectedSecret === '' ? 'HTTP-Aufruf deaktiviert (CRON_SECRET in .env fehlt).' : 'Forbidden.',
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

require __DIR__ . '/../app/scripts/cleanup_expired_reservations.php';
