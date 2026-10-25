<?php
/**
 * Test script for email functionality
 * Usage: php test_email.php
 */

require_once __DIR__ . '/../app/bootstrap.php';

use App\Services\EmailService;

// Check if running in CLI or browser
$isCli = php_sapi_name() === 'cli';

if (!$isCli) {
    echo '<!DOCTYPE html><html><head><title>Email Test</title><style>body{font-family:monospace;padding:20px;}</style></head><body>';
}

echo "=== Email Service Test ===\n\n";
if (!$isCli) echo '<br><br>';

// Check if PHPMailer is available
if (class_exists('\PHPMailer\PHPMailer\PHPMailer')) {
    echo "✅ PHPMailer ist installiert und verfügbar\n";
} else {
    echo "⚠️  PHPMailer ist NICHT installiert (Fallback auf PHP mail())\n";
}

// Display current configuration
echo "\nAktuelle E-Mail-Konfiguration:\n";
if (!$isCli) echo '<br>';
echo "- E-Mail aktiviert: " . (\App\Core\Config::get('email_enabled', true) ? 'JA' : 'NEIN') . "\n";
if (!$isCli) echo '<br>';
echo "- SMTP verwenden: " . (\App\Core\Config::get('email_use_smtp', false) ? 'JA' : 'NEIN') . "\n";
if (!$isCli) echo '<br>';
echo "- SMTP Host: " . (\App\Core\Config::get('email_smtp_host', '') ?: 'nicht konfiguriert') . "\n";
if (!$isCli) echo '<br>';
echo "- SMTP Port: " . \App\Core\Config::get('email_smtp_port', 587) . "\n";
if (!$isCli) echo '<br>';
echo "- SMTP User: " . (\App\Core\Config::get('email_smtp_user', '') ?: 'nicht konfiguriert') . "\n";
if (!$isCli) echo '<br>';
echo "- SMTP Secure: " . (\App\Core\Config::get('email_smtp_secure', true) ? 'JA' : 'NEIN') . "\n";
if (!$isCli) echo '<br>';

// Test email sending
echo "\n=== Test-E-Mail senden ===\n";

// Check if running in CLI or browser
$isCli = php_sapi_name() === 'cli';

if ($isCli) {
    echo "Bitte geben Sie eine Test-E-Mail-Adresse ein: ";
    $testEmail = trim(fgets(STDIN));
} else {
    // Running in browser - use GET parameter or show form
    if (isset($_GET['email'])) {
        $testEmail = $_GET['email'];
    } else {
        echo '<form method="GET">';
        echo '<input type="email" name="email" placeholder="test@example.com" required>';
        echo '<button type="submit">E-Mail senden</button>';
        echo '</form>';
        exit;
    }
}

if (empty($testEmail) || !filter_var($testEmail, FILTER_VALIDATE_EMAIL)) {
    echo "❌ Ungültige E-Mail-Adresse.\n";
    if (!$isCli) {
        echo '<br><a href="test_email.php">Zurück</a>';
    }
    exit(1);
}

try {
    $emailService = new EmailService();
    
    $subject = 'Test E-Mail vom Ticket-System';
    $body = '<html><body>';
    $body .= '<h1>Test E-Mail</h1>';
    $body .= '<p>Dies ist eine Test-E-Mail vom Ticket-System.</p>';
    $body .= '<p>Wenn Sie diese E-Mail erhalten, funktioniert der E-Mail-Versand korrekt!</p>';
    $body .= '<hr>';
    $body .= '<p><small>Gesendet am: ' . date('d.m.Y H:i:s') . '</small></p>';
    $body .= '</body></html>';
    
    echo "Sende E-Mail an: " . $testEmail . "\n";
    
    $result = $emailService->sendEmail(
        $testEmail,
        'Test Empfänger',
        $subject,
        $body,
        true
    );
    
    if ($result) {
        echo "✅ E-Mail wurde erfolgreich gesendet!\n";
        if (!$isCli) echo '<br>';
        echo "Bitte überprüfen Sie Ihr Postfach (auch Spam-Ordner).\n";
        if (!$isCli) echo '<br>';
        echo "\n⚠️  Hinweis: 'Erfolgreich gesendet' bedeutet, dass PHP die E-Mail akzeptiert hat.\n";
        if (!$isCli) echo '<br>';
        echo "Die tatsächliche Zustellung hängt vom Mail-Server ab.\n";
        if (!$isCli) echo '<br>';
    } else {
        echo "❌ E-Mail konnte nicht gesendet werden.\n";
        if (!$isCli) echo '<br>';
        echo "Bitte überprüfen Sie die SMTP-Einstellungen und die Error-Logs.\n";
        if (!$isCli) echo '<br>';
        echo "\n📋 Error-Log-Pfad (Windows/XAMPP): C:\\xampp\\apache\\logs\\error.log\n";
        if (!$isCli) echo '<br>';
    }
} catch (\Exception $e) {
    echo "❌ Fehler beim Senden: " . $e->getMessage() . "\n";
    echo "\nStack Trace:\n" . $e->getTraceAsString() . "\n";
}

echo "\n=== Test abgeschlossen ===\n";

if (!$isCli) {
    echo '<br><br><a href="test_email.php">Neuer Test</a> | <a href="' . (function_exists('url') ? url('') : '/') . '">Zurück zur Startseite</a>';
    echo '</body></html>';
}

