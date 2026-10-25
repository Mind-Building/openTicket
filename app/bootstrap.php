<?php
/**
 * Bootstrap File
 * Initializes the application
 */

// Prevent multiple includes
if (defined('APP_BOOTSTRAPPED')) {
    return;
}
define('APP_BOOTSTRAPPED', true);

// Load environment variables FIRST – the display_errors decision below depends
// on APP_ENV, so .env must already be parsed when we hit it.
if (file_exists(__DIR__ . '/../.env')) {
    $lines = file(__DIR__ . '/../.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || strpos($line, '#') === 0 || strpos($line, '=') === false) {
            continue;
        }
        [$name, $value] = explode('=', $line, 2);
        $name  = trim($name);
        $value = trim($value);
        if ($name !== '') {
            $_ENV[$name] = $value;
            // Also expose via putenv() so getenv() lookups work the same.
            putenv($name . '=' . $value);
        }
    }
}

// Always log all errors – display behaviour is decided below.
error_reporting(E_ALL);
ini_set('log_errors', '1');

$isApiRequest = strpos($_SERVER['REQUEST_URI'] ?? '', '/api/') !== false;
$appEnv       = $_ENV['APP_ENV'] ?? 'production';
$isDev        = ($appEnv === 'development');

// Show PHP errors ONLY for non-API requests in development. APIs always return
// JSON, and production must never leak stacktraces to the browser.
ini_set('display_errors', ($isDev && !$isApiRequest) ? '1' : '0');

// Prevent caching during development (only if not API request)
if (!headers_sent() && !$isApiRequest) {
    header('Cache-Control: no-cache, no-store, must-revalidate');
    header('Pragma: no-cache');
    header('Expires: 0');
}

// Composer handles everything from here on:
//   • PSR-4 autoloading for App\* (configured in composer.json)
//   • classmap autoloading for app/core, app/models, app/services, app/helpers
//   • on-demand loading of TCPDF / FPDI / PHPMailer / Monolog
//   • global helper functions (app/helpers/functions.php via "files" entry)
$vendorAutoload = __DIR__ . '/../vendor/autoload.php';
if (!file_exists($vendorAutoload)) {
    http_response_code(500);
    die("Composer dependencies are missing. Run 'composer install' in the project root.\n");
}
require_once $vendorAutoload;

// Apply the configured timezone so ALL date/time calculations (booking
// deadlines, "today", timestamps, …) are consistent regardless of the
// server's php.ini `date.timezone`. Without this, a server running in UTC
// evaluates the same-day booking deadline 1–2 hours "too early", so customers
// can still order after the cutoff. Config::get() falls back to the default on
// any DB problem, so this stays safe even before the DB is set up.
$configuredTimezone = \App\Core\Config::get('timezone', 'Europe/Berlin');
if (!@date_default_timezone_set($configuredTimezone)) {
    date_default_timezone_set('Europe/Berlin');
}

