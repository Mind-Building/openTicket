<?php
/**
 * Seed default configurations
 * Run this after creating the database schema
 */

require_once __DIR__ . '/../app/bootstrap.php';

use App\Core\Database;
use App\Core\Config;

$db = Database::getInstance();

$defaultConfigs = [
    ['company_name', 'Ticket System', 'string', 'Company name'],
    ['company_url', '', 'string', 'Company website URL'],
    ['ticket_url_start', '', 'string', 'Base URL for ticket system (leave empty for auto-detection)'],
    ['paypal_return_url', '', 'string', 'PayPal Return URL (leave empty to use ticket_url_start + /api/paypal_success.php)'],
    ['timezone', 'Europe/Berlin', 'string', 'Timezone'],
    ['max_reservation_time', '12:00', 'string', 'Maximum time for same-day reservations'],
    ['show_start_time', '20:30', 'string', 'Default show start time'],
    ['reservation_phone', '', 'string', 'Reservation phone number'],
    ['paypal_active', '0', 'boolean', 'Enable PayPal payments'],
    ['paypal_mode', 'sandbox', 'string', 'PayPal mode (live/sandbox)'],
    ['paypal_live_client_id', '', 'string', 'PayPal Live Client ID'],
    ['paypal_live_client_secret', '', 'string', 'PayPal Live Client Secret'],
    ['paypal_sandbox_client_id', '', 'string', 'PayPal Sandbox Client ID'],
    ['paypal_sandbox_client_secret', '', 'string', 'PayPal Sandbox Client Secret'],
    ['voucher_active', '0', 'boolean', 'Enable voucher system'],
    ['reduced_prices_active', '0', 'boolean', 'Enable reduced prices'],
    ['fn_card_active', '0', 'boolean', 'Enable FN Card'],
    ['fn_card_factor', '0.1', 'string', 'FN Card discount factor (0.1 = 10% discount)'],
    ['max_fn_card', '2', 'integer', 'Maximum number of tickets per FN Card per event'],
    ['sender_email', 'noreply@example.com', 'string', 'Sender email address'],
    ['sender_name', 'Ticket System', 'string', 'Sender name'],
    ['support_email', 'support@example.com', 'string', 'Support email address'],
    ['email_enabled', '1', 'boolean', 'Enable email sending'],
    ['email_use_smtp', '0', 'boolean', 'Use SMTP for email'],
    ['email_smtp_host', '', 'string', 'SMTP host'],
    ['email_smtp_port', '587', 'string', 'SMTP port'],
    ['email_smtp_user', '', 'string', 'SMTP username'],
    ['email_smtp_password', '', 'string', 'SMTP password'],
    ['email_smtp_secure', '1', 'boolean', 'SMTP secure (TLS/SSL)'],
    ['email_send_on_reservation', '0', 'boolean', 'Send email when seat is reserved'],
    ['email_reservation_subject', 'Ihre Reservierung', 'string', 'Reservation email subject'],
    ['email_reservation_body', '', 'string', 'Reservation email body template'],
    ['email_ticket_subject', 'Ihre Tickets', 'string', 'Ticket email subject'],
    ['email_ticket_body', '', 'string', 'Ticket email body template'],
    ['email_voucher_subject', 'Ihr Gutschein', 'string', 'Voucher email subject'],
    ['email_voucher_body', '', 'string', 'Voucher email body template'],
    ['checkout_success_message', 'Ihre Bestellung wurde erfolgreich aufgenommen!', 'string', 'Success message after checkout'],
];

try {
    foreach ($defaultConfigs as $config) {
        Config::set($config[0], $config[1], $config[2]);
    }
    echo "Default configurations seeded successfully!\n";
} catch (Exception $e) {
    echo "Error seeding configurations: " . $e->getMessage() . "\n";
    exit(1);
}

