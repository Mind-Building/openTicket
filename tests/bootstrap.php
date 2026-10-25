<?php
/**
 * PHPUnit Bootstrap File
 *
 * Loaded once by PHPUnit before any test runs. We use it to:
 *  1. enforce that the configured DB really is a test database,
 *  2. expose env vars from phpunit.xml's <env> block to $_ENV (PHPUnit sets
 *     them via putenv() reliably, but $_ENV depends on variables_order in
 *     php.ini — copying ensures Database::config reads what we expect),
 *  3. provide minimal $_SERVER values that production code expects,
 *  4. wire up Composer autoloading.
 */

require_once __DIR__ . '/../vendor/autoload.php';

// ── 1. Copy env vars set in phpunit.xml from getenv() to $_ENV ─────────
// The app reads $_ENV (see app/config/database.php). PHPUnit's <env> block
// always sets via putenv(); whether it also lands in $_ENV depends on
// php.ini's variables_order. Be defensive and copy explicitly.
foreach (['APP_ENV', 'DB_HOST', 'DB_NAME', 'DB_USER', 'DB_PASS', 'APP_SECRET'] as $name) {
    $fromShell = getenv($name);
    if ($fromShell !== false) {
        $_ENV[$name] = $fromShell;
    }
}

// ── 2. Safety guardrail ────────────────────────────────────────────────
// Refuse to run if DB_NAME doesn't look like a test database. Tests use
// transactions to roll back changes, but a missing rollback in a single
// test would otherwise corrupt a real ticket database.
$dbName = $_ENV['DB_NAME'] ?? '';
if (stripos($dbName, 'test') === false) {
    fwrite(STDERR, "\n");
    fwrite(STDERR, "  PHPUnit refused to start.\n");
    fwrite(STDERR, "  DB_NAME=\"{$dbName}\" does not contain the word \"test\".\n");
    fwrite(STDERR, "  Set DB_NAME to a test-only database (e.g. ticket_test) in phpunit.xml\n");
    fwrite(STDERR, "  or your shell environment, then re-run.\n\n");
    exit(1);
}

// ── 3. Minimal $_SERVER setup ──────────────────────────────────────────
$_SERVER['REQUEST_URI']     = '/test';
$_SERVER['REMOTE_ADDR']     = '127.0.0.1';
$_SERVER['HTTP_USER_AGENT'] = 'PHPUnit Test';

// ── 4. Ensure logging directory exists ─────────────────────────────────
$testLogPath = __DIR__ . '/../logging';
if (!is_dir($testLogPath)) {
    @mkdir($testLogPath, 0755, true);
}
