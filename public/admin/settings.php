<?php
require_once __DIR__ . '/../../app/bootstrap.php';

use App\Core\Auth;
use App\Core\Database;
use App\Core\Config;
use App\Core\FileUpload;

Auth::requireAdmin();

use App\Models\User;

$db = Database::getInstance();
$message = '';
$messageType = '';

// Handle form submission
// CSRF-Schutz für alle POST-Aktionen dieser Seite
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !\App\Core\Csrf::validatePost()) {
    http_response_code(403);
    die('Ungültige Anfrage: Sicherheits-Token fehlt oder ist abgelaufen. Bitte die Seite neu laden und erneut versuchen.');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $logoUploadDir = __DIR__ . '/../../public/uploads/logos/';

        // Handle logo deletion
        if (isset($_POST['delete_logo'])) {
            $existingLogo = Config::get('company_logo', '');
            if ($existingLogo) {
                $existingPath = __DIR__ . '/../../public/' . $existingLogo;
                if (file_exists($existingPath)) {
                    unlink($existingPath);
                }
            }
            Config::set('company_logo', '', 'string');
        }

        // Handle logo upload
        if (isset($_FILES['company_logo']) && $_FILES['company_logo']['error'] !== UPLOAD_ERR_NO_FILE) {
            $result = FileUpload::upload($_FILES['company_logo'], $logoUploadDir, 'image');
            if ($result['success']) {
                // Delete old logo if exists
                $existingLogo = Config::get('company_logo', '');
                if ($existingLogo) {
                    $existingPath = __DIR__ . '/../../public/' . $existingLogo;
                    if (file_exists($existingPath)) {
                        unlink($existingPath);
                    }
                }
                // Store relative path from public/
                $relativePath = 'uploads/logos/' . basename($result['path']);
                Config::set('company_logo', $relativePath, 'string');
            } else {
                throw new \Exception('Logo-Upload fehlgeschlagen: ' . $result['error']);
            }
        }

        // List of all boolean config keys (checkboxes)
        $booleanKeys = ['paypal_active', 'voucher_active', 'reduced_prices_active', 'fn_card_active', 
            'email_enabled', 'email_use_smtp', 'email_smtp_secure',
            'customer_allow_paypal', 'customer_allow_reservation', 'customer_allow_cash', 'customer_allow_vouchers',
            'admin_allow_paypal', 'admin_allow_reservation', 'admin_allow_cash', 'admin_allow_vouchers',
            'presales_allow_paypal', 'presales_allow_reservation', 'presales_allow_cash', 'presales_allow_vouchers'];
        
        // First, handle all boolean checkboxes (even if not in POST)
        foreach ($booleanKeys as $configKey) {
            $key = 'config_' . $configKey;
            $value = isset($_POST[$key]) && $_POST[$key] == '1' ? 1 : 0;
            Config::set($configKey, $value, 'boolean');
        }
        
        // Then handle all other POST values
        foreach ($_POST as $key => $value) {
            if (strpos($key, 'config_') === 0) {
                $configKey = substr($key, 7); // Remove 'config_' prefix
                
                // Skip boolean keys (already handled above)
                if (in_array($configKey, $booleanKeys)) {
                    continue;
                }
                
                $type = 'string';
                
                // Determine type based on key
                if (in_array($configKey, ['max_reservation_time', 'show_start_time', 'email_reservation_body', 
                    'email_ticket_body', 'email_voucher_body', 'email_combined_purchase_body'])) {
                    $type = 'string';
                } elseif (in_array($configKey, ['email_smtp_port'])) {
                    $type = 'integer';
                } elseif ($configKey === 'max_fn_card') {
                    $type = 'integer';
                    $value = (string)max(1, (int)$value);
                }
                
                Config::set($configKey, $value, $type);
            }
        }
        
        // FN card discount is entered in percent, stored as factor (10 % -> 0.1)
        if (isset($_POST['fn_card_discount_percent']) && $_POST['fn_card_discount_percent'] !== '') {
            $percent = (float)str_replace(',', '.', (string)$_POST['fn_card_discount_percent']);
            $percent = max(0, min(100, $percent));
            Config::set('fn_card_factor', (string)round($percent / 100, 4), 'string');
        }

        $message = 'Einstellungen erfolgreich gespeichert.';
        $messageType = 'success';
    } catch (\Exception $e) {
        $message = 'Fehler beim Speichern: ' . $e->getMessage();
        $messageType = 'danger';
    }
}

// Get all configurations
$stmt = $db->query("SELECT * FROM configurations ORDER BY `key`");
$configs = $stmt->fetchAll();

// Create array for easier access
$configArray = [];
foreach ($configs as $config) {
    $configArray[$config['key']] = $config;
}

// Admin email for pre-filling test recipient
$adminUser = (new User())->getById((int)Auth::id());
$adminEmail = $adminUser['email'] ?? '';

// Cron-Report-URL (Tagesbericht via HTTP). Nutzt ticket_url_start falls gesetzt,
// sonst die aktuelle absolute URL. Der Secret-Token wird aus $_ENV['REPORT_SECRET']
// gelesen (gesetzt in .env). Ist er leer, ist der HTTP-Aufruf deaktiviert.
$reportBase = rtrim((string)Config::get('ticket_url_start', ''), '/');
if ($reportBase === '') {
    $reportBase = rtrim(absoluteUrl(''), '/');
}
$reportSecret = (string)($_ENV['REPORT_SECRET'] ?? '');
$cronReportUrl = $reportSecret !== ''
    ? $reportBase . '/cron_report.php?secret=' . urlencode($reportSecret)
    : '';

// Aktiver Tab (nach Save zurück springen — Tab wird via Hidden-Input
// im POST mitgeschickt, sonst Fallback auf ?tab=... und schließlich 'grund')
$allowedTabs = ['grund', 'email', 'paypal', 'mailtexte', 'zahlung', 'funktionen'];
$activeTab = $_POST['active_tab'] ?? $_GET['tab'] ?? 'grund';
if (!in_array($activeTab, $allowedTabs, true)) {
    $activeTab = 'grund';
}
$tabActive = fn(string $id) => $id === $activeTab ? 'active' : '';
$paneActive = fn(string $id) => $id === $activeTab ? 'show active' : '';

// Default email templates
function getDefaultReservationEmailBody() {
    return '<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <style>
        body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
        .container { max-width: 600px; margin: 0 auto; padding: 20px; }
        .header { background-color: #28a745; color: white; padding: 20px; text-align: center; }
        .content { padding: 20px; background-color: #f9f9f9; }
        .button { display: inline-block; padding: 12px 24px; background-color: #28a745; color: white; text-decoration: none; border-radius: 4px; margin: 20px 0; }
        .footer { text-align: center; padding: 20px; font-size: 12px; color: #666; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>Ihre Reservierung</h1>
        </div>
        <div class="content">
            <p>Hallo {customer_name},</p>
            <p>vielen Dank für Ihre Reservierung!</p>
            <p>Sie haben einen Platz für "{event_name}" am {event_date} um {event_time} reserviert.</p>
            <p><strong>Platz:</strong> {seat_identifier}</p>
            <p><strong>Kategorie:</strong> {category_name}</p>
            <p><strong>Preis:</strong> {price}</p>
            <p>Bitte schließen Sie Ihre Bestellung innerhalb von 15 Minuten ab:</p>
            <p style="text-align: center;">
                <a href="{reservation_link}" class="button">Zur Bestellung</a>
            </p>
            <p>Mit freundlichen Grüßen<br>Ihr Ticket-Team</p>
        </div>
        <div class="footer">
            <p>Diese E-Mail wurde automatisch generiert. Bitte antworten Sie nicht auf diese E-Mail.</p>
        </div>
    </div>
</body>
</html>';
}

function getDefaultTicketEmailBody() {
    return '<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <style>
        body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
        .container { max-width: 600px; margin: 0 auto; padding: 20px; }
        .header { background-color: #007bff; color: white; padding: 20px; text-align: center; }
        .content { padding: 20px; background-color: #f9f9f9; }
        .button { display: inline-block; padding: 12px 24px; background-color: #007bff; color: white; text-decoration: none; border-radius: 4px; margin: 20px 0; }
        .footer { text-align: center; padding: 20px; font-size: 12px; color: #666; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>Ihre Tickets</h1>
        </div>
        <div class="content">
            <p>Hallo {customer_name},</p>
            <p>vielen Dank für Ihre Bestellung!</p>
            <p>Sie haben {reservation_count} Ticket(s) für "{event_name}" am {event_date} um {event_time} bestellt.</p>
            <p><strong>Kategorie:</strong> {category_name}</p>
            <p><strong>Gesamtpreis:</strong> {price}</p>
            {seat_list}
            <p>Sie können Ihre Tickets über den folgenden Link herunterladen:</p>
            <p style="text-align: center;">
                {ticket_link_html}
            </p>
            <p>Mit freundlichen Grüßen<br>Ihr Ticket-Team</p>
        </div>
        <div class="footer">
            <p>Diese E-Mail wurde automatisch generiert. Bitte antworten Sie nicht auf diese E-Mail.</p>
        </div>
    </div>
</body>
</html>';
}

function getDefaultVoucherEmailBody() {
    return '<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <style>
        body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
        .container { max-width: 600px; margin: 0 auto; padding: 20px; }
        .header { background-color: #ffc107; color: #333; padding: 20px; text-align: center; }
        .content { padding: 20px; background-color: #f9f9f9; }
        .voucher-code { font-size: 24px; font-weight: bold; text-align: center; padding: 20px; background-color: #fff; border: 2px dashed #ffc107; margin: 20px 0; }
        .button { display: inline-block; padding: 12px 24px; background-color: #ffc107; color: #333; text-decoration: none; border-radius: 4px; margin: 20px 0; font-weight: bold; }
        .footer { text-align: center; padding: 20px; font-size: 12px; color: #666; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>Ihr Gutschein</h1>
        </div>
        <div class="content">
            <p>Hallo {customer_name},</p>
            <p>vielen Dank für Ihren Gutscheinkauf!</p>
            <p>Sie haben {quantity} Gutschein(e) erhalten:</p>
            {voucher_list}
            <p><strong>Uhrzeit:</strong> {event_time}</p>
            <p><strong>Gesamtpreis:</strong> {price}</p>
            <p>Mit freundlichen Grüßen<br>Ihr Ticket-Team</p>
        </div>
        <div class="footer">
            <p>Diese E-Mail wurde automatisch generiert. Bitte antworten Sie nicht auf diese E-Mail.</p>
        </div>
    </div>
</body>
</html>';
}

function getDefaultPresalesEmailBody() {
    return '<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <style>
        body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
        .container { max-width: 600px; margin: 0 auto; padding: 20px; }
        .header { background-color: #198754; color: white; padding: 20px; text-align: center; }
        .content { padding: 20px; background-color: #f9f9f9; }
        .seat-list { background-color: #fff; border: 1px solid #dee2e6; border-radius: 4px; padding: 12px 16px; margin: 12px 0; }
        .footer { text-align: center; padding: 20px; font-size: 12px; color: #666; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>Ihre Tickets – Vorverkauf</h1>
        </div>
        <div class="content">
            <p>Hallo {customer_name},</p>
            <p>vielen Dank für Ihren Kartenkauf im Vorverkauf!</p>
            <p>Sie haben <strong>{reservation_count} Karte(n)</strong> für "<strong>{event_name}</strong>" am {event_date} um {event_time} erworben.</p>
            <div class="seat-list">{seat_list}</div>
            <p><strong>Gesamtpreis:</strong> {price}</p>
            <p>Ihre Karten wurden Ihnen direkt ausgehändigt.</p>
            <p>Wir freuen uns auf Ihren Besuch!</p>
            <p>Mit freundlichen Grüßen<br>Ihr Ticket-Team</p>
        </div>
        <div class="footer">
            <p>Diese E-Mail wurde automatisch generiert. Bitte antworten Sie nicht auf diese E-Mail.</p>
        </div>
    </div>
</body>
</html>';
}

function getDefaultPickupEmailBody() {
    return '<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <style>
        body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
        .container { max-width: 600px; margin: 0 auto; padding: 20px; }
        .header { background-color: #0dcaf0; color: #fff; padding: 20px; text-align: center; }
        .content { padding: 20px; background-color: #f9f9f9; }
        .seat-list { background-color: #fff; border: 1px solid #dee2e6; border-radius: 4px; padding: 12px 16px; margin: 12px 0; }
        .footer { text-align: center; padding: 20px; font-size: 12px; color: #666; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>Abholung bestätigt</h1>
        </div>
        <div class="content">
            <p>Hallo {customer_name},</p>
            <p>Ihre Reservierung für <strong>{event_name}</strong> am {event_date} um {event_time} Uhr wurde als bezahlt und abgeholt markiert.</p>
            <p><strong>Anzahl Karten:</strong> {reservation_count}</p>
            <div class="seat-list">{seat_list}</div>
            <p>Vielen Dank und viel Spaß bei der Veranstaltung!</p>
            <p>Mit freundlichen Grüßen<br>Ihr Ticket-Team</p>
        </div>
        <div class="footer">
            <p>Diese E-Mail wurde automatisch generiert. Bitte antworten Sie nicht auf diese E-Mail.</p>
        </div>
    </div>
</body>
</html>';
}

function getDefaultCombinedPurchaseEmailBody() {
    return '<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <style>
        body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
        .container { max-width: 600px; margin: 0 auto; padding: 20px; }
        .header { background-color: #007bff; color: white; padding: 20px; text-align: center; }
        .content { padding: 20px; background-color: #f9f9f9; }
        .footer { text-align: center; padding: 20px; font-size: 12px; color: #666; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>Ihre Bestellung</h1>
        </div>
        <div class="content">
            <p>Hallo {customer_name},</p>
            <p>vielen Dank für Ihre Bestellung!</p>
            {ticket_section}
            {voucher_section}
            <p><strong>Uhrzeit:</strong> {event_time}</p>
            <p><strong>Kategorie:</strong> {category_name}</p>
            <p><strong>Gesamtpreis:</strong> {price}</p>
            <p>Mit freundlichen Grüßen<br>Ihr Ticket-Team</p>
        </div>
        <div class="footer">
            <p>Diese E-Mail wurde automatisch generiert. Bitte antworten Sie nicht auf diese E-Mail.</p>
        </div>
    </div>
</body>
</html>';
}

$pageTitle = 'Einstellungen';
$showSidebar = true;
$showHeader = true;

$content = '
<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <h5 class="card-title mb-0"><i class="bi bi-gear"></i> Systemeinstellungen</h5>
        <a href="' . url('admin/database_update.php') . '" class="btn btn-sm btn-outline-secondary"><i class="bi bi-database-up"></i> Datenbank-Update</a>
    </div>
    <div class="card-body">
        ' . ($message ? '<div class="alert alert-' . $messageType . ' alert-dismissible fade show" role="alert">' . htmlspecialchars($message) . '<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>' : '') . '
        
        <ul class="nav nav-tabs mb-3" id="settingsTabs" role="tablist">
            <li class="nav-item" role="presentation">
                <button class="nav-link ' . $tabActive('grund') . '" data-bs-target="#tab-grund" data-bs-toggle="tab" type="button" role="tab"><i class="bi bi-sliders"></i> Grundeinstellung</button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link ' . $tabActive('email') . '" data-bs-target="#tab-email" data-bs-toggle="tab" type="button" role="tab"><i class="bi bi-envelope"></i> Email</button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link ' . $tabActive('paypal') . '" data-bs-target="#tab-paypal" data-bs-toggle="tab" type="button" role="tab"><i class="bi bi-credit-card-2-front"></i> Paypal</button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link ' . $tabActive('mailtexte') . '" data-bs-target="#tab-mailtexte" data-bs-toggle="tab" type="button" role="tab"><i class="bi bi-card-text"></i> Mail-Texte</button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link ' . $tabActive('zahlung') . '" data-bs-target="#tab-zahlung" data-bs-toggle="tab" type="button" role="tab"><i class="bi bi-cash-coin"></i> Zahlungsmethoden</button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link ' . $tabActive('funktionen') . '" data-bs-target="#tab-funktionen" data-bs-toggle="tab" type="button" role="tab"><i class="bi bi-toggles"></i> Funktionen</button>
            </li>
        </ul>

        <form method="POST" action="" enctype="multipart/form-data">
            ' . \App\Core\Csrf::field() . '
            <input type="hidden" name="active_tab" id="active_tab_input" value="' . htmlspecialchars($activeTab) . '">

            <div class="tab-content">

                <!-- TAB: Grundeinstellung -->
                <div class="tab-pane fade ' . $paneActive('grund') . '" id="tab-grund" role="tabpanel">
                    <div class="row">
                        <div class="col-md-6">
                            <h6 class="mb-3">Allgemeine Einstellungen</h6>

                            <div class="mb-3">
                                <label for="config_company_name" class="form-label">Firmenname</label>
                                <input type="text" class="form-control" id="config_company_name" name="config_company_name"
                                       value="' . htmlspecialchars($configArray['company_name']['value'] ?? '') . '">
                            </div>

                            <div class="mb-3">
                                <label class="form-label">Organisations-Logo</label>
                                ' . (Config::get('company_logo') ? '
                                <div class="mb-2">
                                    <img src="' . url(Config::get('company_logo')) . '" alt="Logo" style="max-height: 80px; max-width: 200px; object-fit: contain; border: 1px solid #dee2e6; border-radius: 4px; padding: 4px; background: #fff;">
                                </div>
                                <div class="d-flex gap-2 align-items-center mb-2">
                                    <input type="file" class="form-control" name="company_logo" accept="image/jpeg,image/png,image/gif">
                                    <button type="submit" name="delete_logo" value="1" class="btn btn-outline-danger btn-sm" onclick="return confirm(\'Logo wirklich löschen?\')">
                                        <i class="bi bi-trash"></i> Logo löschen
                                    </button>
                                </div>
                                ' : '
                                <input type="file" class="form-control" name="company_logo" accept="image/jpeg,image/png,image/gif">
                                ') . '
                                <small class="form-text text-muted">JPG, PNG oder GIF, max. 10 MB. Wird oben rechts in der Navbar angezeigt.</small>
                            </div>

                            <div class="mb-3">
                                <label for="config_company_url" class="form-label">Firmen-URL</label>
                                <input type="url" class="form-control" id="config_company_url" name="config_company_url"
                                       value="' . htmlspecialchars($configArray['company_url']['value'] ?? '') . '">
                            </div>

                            <div class="mb-3">
                                <label for="config_ticket_url_start" class="form-label">URL Ticketsystem</label>
                                <input type="url" class="form-control" id="config_ticket_url_start" name="config_ticket_url_start"
                                       value="' . htmlspecialchars($configArray['ticket_url_start']['value'] ?? '') . '"
                                       placeholder="https://ticket.example.com">
                                <small class="form-text text-muted">Basis-URL des Ticketsystems (ohne /public am Ende)</small>
                            </div>

                            <div class="mb-3">
                                <label for="config_timezone" class="form-label">Zeitzone</label>
                                <input type="text" class="form-control" id="config_timezone" name="config_timezone"
                                       value="' . htmlspecialchars($configArray['timezone']['value'] ?? 'Europe/Berlin') . '">
                            </div>

                            <div class="mb-3">
                                <label for="config_reservation_phone" class="form-label">Reservierungs-Telefon</label>
                                <input type="text" class="form-control" id="config_reservation_phone" name="config_reservation_phone"
                                       value="' . htmlspecialchars($configArray['reservation_phone']['value'] ?? '') . '">
                            </div>
                        </div>

                        <div class="col-md-6">
                            <h6 class="mb-3">Zeiteinstellungen</h6>

                            <div class="mb-3">
                                <label for="config_show_start_time" class="form-label">Standard Vorstellungsbeginn</label>
                                <input type="time" class="form-control" id="config_show_start_time" name="config_show_start_time"
                                       value="' . htmlspecialchars($configArray['show_start_time']['value'] ?? '20:30') . '">
                            </div>

                            <div class="mb-3">
                                <label for="config_max_reservation_time" class="form-label">Maximale Reservierungszeit</label>
                                <input type="time" class="form-control" id="config_max_reservation_time" name="config_max_reservation_time"
                                       value="' . htmlspecialchars($configArray['max_reservation_time']['value'] ?? '12:00') . '">
                            </div>

                            <hr class="my-4">
                            <h6 class="mb-3">Abendkasse</h6>

                            <div class="mb-3">
                                <label for="config_box_office_pin" class="form-label">Abendkasse PIN</label>
                                <input type="password" class="form-control" id="config_box_office_pin" name="config_box_office_pin"
                                       value="' . htmlspecialchars($configArray['box_office_pin']['value'] ?? '') . '"
                                       placeholder="PIN zum Schutz der Abendkasse-Funktion" autocomplete="new-password">
                                <small class="form-text text-muted">Wird im Vorverkauf bei der Abendkasse-Funktion abgefragt. Leer = Funktion gesperrt.</small>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- TAB: Email -->
                <div class="tab-pane fade ' . $paneActive('email') . '" id="tab-email" role="tabpanel">
                    <h6 class="mb-3">E-Mail-Einstellungen</h6>

                    <div class="mb-3 form-check form-switch">
                        <input class="form-check-input" type="checkbox" id="config_email_enabled" name="config_email_enabled" value="1"
                               ' . (($configArray['email_enabled']['value'] ?? '1') == '1' ? 'checked' : '') . '>
                        <label class="form-check-label" for="config_email_enabled">E-Mail-Versand aktivieren</label>
                    </div>

                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label for="config_sender_email" class="form-label">Absender-E-Mail</label>
                            <input type="email" class="form-control" id="config_sender_email" name="config_sender_email"
                                   value="' . htmlspecialchars($configArray['sender_email']['value'] ?? '') . '"
                                   placeholder="noreply@example.com">
                        </div>
                        <div class="col-md-6">
                            <label for="config_sender_name" class="form-label">Absender-Name</label>
                            <input type="text" class="form-control" id="config_sender_name" name="config_sender_name"
                                   value="' . htmlspecialchars($configArray['sender_name']['value'] ?? '') . '"
                                   placeholder="Ticket System">
                        </div>
                    </div>

                    <div class="mb-3">
                        <label for="config_support_email" class="form-label">Support-E-Mail</label>
                        <input type="email" class="form-control" id="config_support_email" name="config_support_email"
                               value="' . htmlspecialchars($configArray['support_email']['value'] ?? '') . '"
                               placeholder="support@example.com">
                        <small class="form-text text-muted">Wird für Kunden-Rückfragen angezeigt.</small>
                    </div>

                    <div class="mb-3">
                        <label for="config_cron_report_recipients" class="form-label">Tagesbericht-Empfänger</label>
                        <textarea class="form-control" id="config_cron_report_recipients" name="config_cron_report_recipients"
                                  rows="3" placeholder="eine@adresse.de&#10;zweite@adresse.de">' . htmlspecialchars($configArray['cron_report_recipients']['value'] ?? '') . '</textarea>
                        <small class="form-text text-muted">Empfänger des täglichen Cron-Berichts – eine E-Mail-Adresse pro Zeile.</small>

                        <div class="alert ' . ($cronReportUrl !== '' ? 'alert-info' : 'alert-warning') . ' mt-2 py-2 small mb-0">
                            <i class="bi bi-clock-history"></i> <strong>CRON-Aufruf-URL:</strong>
                            ' . ($cronReportUrl !== ''
                                ? '<div class="input-group input-group-sm mt-1">
                                <input type="text" class="form-control form-control-sm" id="cron-report-url" readonly value="' . htmlspecialchars($cronReportUrl) . '">
                                <button type="button" class="btn btn-outline-secondary btn-sm" id="cron-copy-btn" title="In Zwischenablage kopieren"><i class="bi bi-clipboard"></i></button>
                            </div>
                            <small class="text-muted d-block mt-1">Diese URL muss vom CRON-Job einmal täglich aufgerufen werden (z.B. mit <code>wget</code> oder <code>curl</code>). Der <code>secret</code>-Parameter ist erforderlich.</small>'
                                : '<div class="mt-1">HTTP-Aufruf ist deaktiviert. Setze <code>REPORT_SECRET</code> in der <code>.env</code>-Datei, um die URL freizuschalten. Der CLI-/CRON-Aufruf via <code>php app/scripts/daily_report.php</code> funktioniert weiterhin.</div>'
                            ) . '
                        </div>
                    </div>

                    <div class="mb-3 form-check form-switch">
                        <input class="form-check-input" type="checkbox" id="config_email_use_smtp" name="config_email_use_smtp" value="1"
                               ' . (($configArray['email_use_smtp']['value'] ?? '0') == '1' ? 'checked' : '') . '>
                        <label class="form-check-label" for="config_email_use_smtp">SMTP verwenden</label>
                    </div>

                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="config_email_smtp_host" class="form-label">SMTP Host</label>
                                <input type="text" class="form-control" id="config_email_smtp_host" name="config_email_smtp_host"
                                       value="' . htmlspecialchars($configArray['email_smtp_host']['value'] ?? '') . '"
                                       placeholder="smtp.example.com">
                            </div>

                            <div class="mb-3">
                                <label for="config_email_smtp_port" class="form-label">SMTP Port</label>
                                <input type="number" class="form-control" id="config_email_smtp_port" name="config_email_smtp_port"
                                       value="' . htmlspecialchars($configArray['email_smtp_port']['value'] ?? '587') . '">
                            </div>

                            <div class="mb-3">
                                <label for="config_email_smtp_user" class="form-label">SMTP Benutzername</label>
                                <input type="text" class="form-control" id="config_email_smtp_user" name="config_email_smtp_user"
                                       value="' . htmlspecialchars($configArray['email_smtp_user']['value'] ?? '') . '">
                            </div>

                            <div class="mb-3">
                                <label for="config_email_smtp_password" class="form-label">SMTP Passwort</label>
                                <input type="password" class="form-control" id="config_email_smtp_password" name="config_email_smtp_password"
                                       value="' . htmlspecialchars($configArray['email_smtp_password']['value'] ?? '') . '">
                            </div>

                            <div class="mb-3 form-check form-switch">
                                <input class="form-check-input" type="checkbox" id="config_email_smtp_secure" name="config_email_smtp_secure" value="1"
                                       ' . (($configArray['email_smtp_secure']['value'] ?? '1') == '1' ? 'checked' : '') . '>
                                <label class="form-check-label" for="config_email_smtp_secure">Verschlüsselung (TLS/SSL)</label>
                            </div>
                        </div>
                    </div>

                    <hr class="my-4">
                    <h6 class="mb-3"><i class="bi bi-send"></i> Test-E-Mail senden</h6>
                    <p class="small text-muted mb-2">Sendet eine Beispiel-E-Mail mit Platzhalterdaten an die angegebene Adresse – nützlich um Darstellung und Logo zu prüfen.</p>
                    <div class="row g-2 align-items-end">
                        <div class="col-md-4">
                            <label class="form-label small mb-1">Empfänger</label>
                            <input type="email" class="form-control form-control-sm" id="test-email-recipient"
                                   autocomplete="off" placeholder="email@example.com" value="' . htmlspecialchars($adminEmail) . '"
                                   onkeydown="if(event.key===\'Enter\'){event.preventDefault();}">
                        </div>
                        <div class="col-md-8">
                            <label class="form-label small mb-1">Template</label>
                            <div class="d-flex flex-wrap gap-1">
                                <button type="button" class="btn btn-sm btn-outline-secondary send-test-email-btn" data-type="reservation">Reservierung</button>
                                <button type="button" class="btn btn-sm btn-outline-secondary send-test-email-btn" data-type="ticket">Ticket</button>
                                <button type="button" class="btn btn-sm btn-outline-secondary send-test-email-btn" data-type="voucher">Gutschein</button>
                                <button type="button" class="btn btn-sm btn-outline-secondary send-test-email-btn" data-type="pickup">Abholung</button>
                                <button type="button" class="btn btn-sm btn-outline-secondary send-test-email-btn" data-type="presales">Vorverkauf</button>
                                <button type="button" class="btn btn-sm btn-outline-secondary send-test-email-btn" data-type="combined">Kombiniert</button>
                                <button type="button" class="btn btn-sm btn-outline-primary send-test-email-btn" data-type="vip"><i class="bi bi-star"></i> VIP</button>
                            </div>
                        </div>
                    </div>
                    <div id="test-email-result" class="mt-2"></div>
                </div>

                <!-- TAB: Paypal -->
                <div class="tab-pane fade ' . $paneActive('paypal') . '" id="tab-paypal" role="tabpanel">
                    <h6 class="mb-3">PayPal Einstellungen</h6>

                    <div class="row">
                        <div class="col-md-8">
                            <div class="mb-3 form-check form-switch">
                                <input class="form-check-input" type="checkbox" id="config_paypal_active" name="config_paypal_active" value="1"
                                       ' . (($configArray['paypal_active']['value'] ?? '0') == '1' ? 'checked' : '') . '>
                                <label class="form-check-label" for="config_paypal_active">PayPal aktivieren</label>
                            </div>

                            <div class="mb-3">
                                <label for="config_paypal_mode" class="form-label">PayPal Modus</label>
                                <select class="form-select" id="config_paypal_mode" name="config_paypal_mode">
                                    <option value="sandbox" ' . (($configArray['paypal_mode']['value'] ?? 'live') == 'sandbox' ? 'selected' : '') . '>Sandbox (Test)</option>
                                    <option value="live" ' . (($configArray['paypal_mode']['value'] ?? 'live') == 'live' ? 'selected' : '') . '>Live</option>
                                </select>
                                <small class="form-text text-muted">Sandbox für Tests, Live für echte Zahlungen</small>
                            </div>

                            <div class="card mb-3 bg-light">
                                <div class="card-body">
                                    <h6 class="card-title">Live Zugangsdaten</h6>
                                    <div class="mb-3">
                                        <label for="config_paypal_live_client_id" class="form-label">Client ID (Live)</label>
                                        <input type="text" class="form-control" id="config_paypal_live_client_id" name="config_paypal_live_client_id"
                                               value="' . htmlspecialchars($configArray['paypal_live_client_id']['value'] ?? '') . '">
                                    </div>
                                    <div class="mb-3">
                                        <label for="config_paypal_live_client_secret" class="form-label">Client Secret (Live)</label>
                                        <input type="password" class="form-control" id="config_paypal_live_client_secret" name="config_paypal_live_client_secret"
                                               value="' . htmlspecialchars($configArray['paypal_live_client_secret']['value'] ?? '') . '">
                                    </div>
                                </div>
                            </div>

                            <div class="card mb-3 bg-light">
                                <div class="card-body">
                                    <h6 class="card-title">Sandbox Zugangsdaten (Test)</h6>
                                    <div class="mb-3">
                                        <label for="config_paypal_sandbox_client_id" class="form-label">Client ID (Sandbox)</label>
                                        <input type="text" class="form-control" id="config_paypal_sandbox_client_id" name="config_paypal_sandbox_client_id"
                                               value="' . htmlspecialchars($configArray['paypal_sandbox_client_id']['value'] ?? '') . '">
                                    </div>
                                    <div class="mb-3">
                                        <label for="config_paypal_sandbox_client_secret" class="form-label">Client Secret (Sandbox)</label>
                                        <input type="password" class="form-control" id="config_paypal_sandbox_client_secret" name="config_paypal_sandbox_client_secret"
                                               value="' . htmlspecialchars($configArray['paypal_sandbox_client_secret']['value'] ?? '') . '">
                                    </div>
                                </div>
                            </div>

                            <div class="mb-3">
                                <label for="config_paypal_return_url" class="form-label">Return URL PayPal</label>
                                <input type="url" class="form-control" id="config_paypal_return_url" name="config_paypal_return_url"
                                       value="' . htmlspecialchars($configArray['paypal_return_url']['value'] ?? '') . '"
                                       placeholder="https://ticket.example.com/api/paypal_success.php">
                                <small class="form-text text-muted">URL, zu der PayPal nach erfolgreicher Zahlung weiterleitet. Leer lassen für automatische URL (ticket_url_start + /api/paypal_success.php)</small>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- TAB: Zahlungsmethoden -->
                <div class="tab-pane fade ' . $paneActive('zahlung') . '" id="tab-zahlung" role="tabpanel">
                    <h6 class="mb-3">Zahlungs-/Abschlussmethoden</h6>

                    <div class="row">
                        <div class="col-md-4">
                            <h6 class="text-muted mb-3">Kunde (Online)</h6>
                            <div class="mb-2 form-check">
                                <input class="form-check-input" type="checkbox" id="config_customer_allow_paypal" name="config_customer_allow_paypal" value="1"
                                       ' . (($configArray['customer_allow_paypal']['value'] ?? '1') == '1' ? 'checked' : '') . '>
                                <label class="form-check-label" for="config_customer_allow_paypal">PayPal-Zahlung</label>
                            </div>
                            <div class="mb-2 form-check">
                                <input class="form-check-input" type="checkbox" id="config_customer_allow_reservation" name="config_customer_allow_reservation" value="1"
                                       ' . (($configArray['customer_allow_reservation']['value'] ?? '0') == '1' ? 'checked' : '') . '>
                                <label class="form-check-label" for="config_customer_allow_reservation">Reservierung (ohne Zahlung)</label>
                            </div>
                            <div class="mb-2 form-check">
                                <input class="form-check-input" type="checkbox" id="config_customer_allow_cash" name="config_customer_allow_cash" value="1"
                                       ' . (($configArray['customer_allow_cash']['value'] ?? '0') == '1' ? 'checked' : '') . '>
                                <label class="form-check-label" for="config_customer_allow_cash">Barzahlung (Abholung)</label>
                            </div>
                            <div class="mb-2 form-check">
                                <input class="form-check-input" type="checkbox" id="config_customer_allow_vouchers" name="config_customer_allow_vouchers" value="1"
                                       ' . (($configArray['customer_allow_vouchers']['value'] ?? '0') == '1' ? 'checked' : '') . '>
                                <label class="form-check-label" for="config_customer_allow_vouchers">Gutschein-Kauf</label>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <h6 class="text-muted mb-3">Admin</h6>
                            <div class="mb-2 form-check">
                                <input class="form-check-input" type="checkbox" id="config_admin_allow_paypal" name="config_admin_allow_paypal" value="1"
                                       ' . (($configArray['admin_allow_paypal']['value'] ?? '1') == '1' ? 'checked' : '') . '>
                                <label class="form-check-label" for="config_admin_allow_paypal">PayPal-Zahlung</label>
                            </div>
                            <div class="mb-2 form-check">
                                <input class="form-check-input" type="checkbox" id="config_admin_allow_reservation" name="config_admin_allow_reservation" value="1"
                                       ' . (($configArray['admin_allow_reservation']['value'] ?? '1') == '1' ? 'checked' : '') . '>
                                <label class="form-check-label" for="config_admin_allow_reservation">Reservierung</label>
                            </div>
                            <div class="mb-2 form-check">
                                <input class="form-check-input" type="checkbox" id="config_admin_allow_cash" name="config_admin_allow_cash" value="1"
                                       ' . (($configArray['admin_allow_cash']['value'] ?? '1') == '1' ? 'checked' : '') . '>
                                <label class="form-check-label" for="config_admin_allow_cash">Barzahlung</label>
                            </div>
                            <div class="mb-2 form-check">
                                <input class="form-check-input" type="checkbox" id="config_admin_allow_vouchers" name="config_admin_allow_vouchers" value="1"
                                       ' . (($configArray['admin_allow_vouchers']['value'] ?? '1') == '1' ? 'checked' : '') . '>
                                <label class="form-check-label" for="config_admin_allow_vouchers">Gutschein-Erstellung</label>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <h6 class="text-muted mb-3">Vorverkauf</h6>
                            <div class="mb-2 form-check">
                                <input class="form-check-input" type="checkbox" id="config_presales_allow_paypal" name="config_presales_allow_paypal" value="1"
                                       ' . (($configArray['presales_allow_paypal']['value'] ?? '0') == '1' ? 'checked' : '') . '>
                                <label class="form-check-label" for="config_presales_allow_paypal">PayPal-Zahlung</label>
                            </div>
                            <div class="mb-2 form-check">
                                <input class="form-check-input" type="checkbox" id="config_presales_allow_reservation" name="config_presales_allow_reservation" value="1"
                                       ' . (($configArray['presales_allow_reservation']['value'] ?? '1') == '1' ? 'checked' : '') . '>
                                <label class="form-check-label" for="config_presales_allow_reservation">Reservierung</label>
                            </div>
                            <div class="mb-2 form-check">
                                <input class="form-check-input" type="checkbox" id="config_presales_allow_cash" name="config_presales_allow_cash" value="1"
                                       ' . (($configArray['presales_allow_cash']['value'] ?? '1') == '1' ? 'checked' : '') . '>
                                <label class="form-check-label" for="config_presales_allow_cash">Barzahlung</label>
                            </div>
                            <div class="mb-2 form-check">
                                <input class="form-check-input" type="checkbox" id="config_presales_allow_vouchers" name="config_presales_allow_vouchers" value="1"
                                       ' . (($configArray['presales_allow_vouchers']['value'] ?? '1') == '1' ? 'checked' : '') . '>
                                <label class="form-check-label" for="config_presales_allow_vouchers">Gutschein-Erstellung</label>
                            </div>
                        </div>
                    </div>
                    <div class="alert alert-info mt-3">
                        <i class="bi bi-info-circle"></i> <strong>Hinweis:</strong> Mindestens eine Methode pro Rolle sollte aktiviert sein.
                    </div>
                </div>

                <!-- TAB: Funktionen -->
                <div class="tab-pane fade ' . $paneActive('funktionen') . '" id="tab-funktionen" role="tabpanel">
                    <h6 class="mb-3">Funktionen</h6>

                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3 form-check form-switch">
                                <input class="form-check-input" type="checkbox" id="config_voucher_active" name="config_voucher_active" value="1"
                                       ' . (($configArray['voucher_active']['value'] ?? '0') == '1' ? 'checked' : '') . '>
                                <label class="form-check-label" for="config_voucher_active">Gutscheine aktivieren</label>
                            </div>

                            <div class="mb-3 form-check form-switch">
                                <input class="form-check-input" type="checkbox" id="config_reduced_prices_active" name="config_reduced_prices_active" value="1"
                                       ' . (($configArray['reduced_prices_active']['value'] ?? '0') == '1' ? 'checked' : '') . '>
                                <label class="form-check-label" for="config_reduced_prices_active">Ermäßigte Preise aktivieren</label>
                            </div>

                            <div class="mb-3 form-check form-switch">
                                <input class="form-check-input" type="checkbox" id="config_fn_card_active" name="config_fn_card_active" value="1"
                                       ' . (($configArray['fn_card_active']['value'] ?? '0') == '1' ? 'checked' : '') . '>
                                <label class="form-check-label" for="config_fn_card_active">FN-Karte aktivieren</label>
                            </div>
                            <div class="row g-2 mb-3 ms-4">
                                <div class="col-sm-6 col-lg-4">
                                    <label for="fn_card_discount_percent" class="form-label small mb-0">FN-Karte: Rabatt in %</label>
                                    <input type="number" class="form-control form-control-sm" id="fn_card_discount_percent" name="fn_card_discount_percent" min="0" max="100" step="0.5"
                                           value="' . htmlspecialchars((string)round((float)($configArray['fn_card_factor']['value'] ?? 0.1) * 100, 2)) . '">
                                </div>
                                <div class="col-sm-6 col-lg-4">
                                    <label for="config_max_fn_card" class="form-label small mb-0">FN-Karte: max. Tickets pro Karte und Veranstaltung</label>
                                    <input type="number" class="form-control form-control-sm" id="config_max_fn_card" name="config_max_fn_card" min="1" step="1"
                                           value="' . htmlspecialchars((string)(int)($configArray['max_fn_card']['value'] ?? 2)) . '">
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- TAB: Mail-Texte -->
                <div class="tab-pane fade ' . $paneActive('mailtexte') . '" id="tab-mailtexte" role="tabpanel">
                    <h6 class="mb-3">E-Mail-Texte</h6>
                    
                    <ul class="nav nav-tabs" role="tablist">
                        <li class="nav-item" role="presentation">
                            <button class="nav-link active" id="reservation-tab" data-bs-toggle="tab" data-bs-target="#reservation" type="button" role="tab">
                                Reservierung
                            </button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link" id="ticket-tab" data-bs-toggle="tab" data-bs-target="#ticket" type="button" role="tab">
                                Kauf/Ticket
                            </button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link" id="voucher-tab" data-bs-toggle="tab" data-bs-target="#voucher" type="button" role="tab">
                                Gutschein
                            </button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link" id="pickup-tab" data-bs-toggle="tab" data-bs-target="#pickup" type="button" role="tab">
                                Abholung
                            </button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link" id="presales-tab" data-bs-toggle="tab" data-bs-target="#presales" type="button" role="tab">
                                Vorverkauf
                            </button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link" id="combined-tab" data-bs-toggle="tab" data-bs-target="#combined" type="button" role="tab">
                                Kombinierte Bestellung
                            </button>
                        </li>
                    </ul>
                    
                    <div class="tab-content mt-3">
                        <div class="tab-pane fade show active" id="reservation" role="tabpanel">
                            <div class="mb-3">
                                <label for="config_email_reservation_subject" class="form-label">Betreff</label>
                                <input type="text" class="form-control" id="config_email_reservation_subject" name="config_email_reservation_subject" 
                                       value="' . htmlspecialchars($configArray['email_reservation_subject']['value'] ?? 'Ihre Reservierung') . '">
                            </div>
                            <div class="mb-3">
                                <label for="config_email_reservation_body" class="form-label">E-Mail-Text (HTML)</label>
                                <textarea class="form-control" id="config_email_reservation_body" name="config_email_reservation_body" rows="15">' . htmlspecialchars($configArray['email_reservation_body']['value'] ?? getDefaultReservationEmailBody()) . '</textarea>
                                <div class="alert alert-info mt-2 py-2 small">
                                    <i class="bi bi-braces"></i> <strong>Verfügbare Platzhalter:</strong><br>
                                    <code>{customer_name}</code>, <code>{event_name}</code>, <code>{event_date}</code>, <code>{event_time}</code>, <code>{category_name}</code>, <code>{price}</code>, <code>{seat_list}</code> (Sitzplatzliste), <code>{seat_identifier}</code> (erster Sitzplatz), <code>{reservation_count}</code>, <code>{reservation_link}</code>, <code>{seatplan_link}</code> (URL Sitzplan), <code>{seatplan_link_html}</code> (Sitzplan-Button)
                                </div>
                            </div>
                        </div>

                        <div class="tab-pane fade" id="ticket" role="tabpanel">
                            <div class="mb-3">
                                <label for="config_email_ticket_subject" class="form-label">Betreff</label>
                                <input type="text" class="form-control" id="config_email_ticket_subject" name="config_email_ticket_subject"
                                       value="' . htmlspecialchars($configArray['email_ticket_subject']['value'] ?? 'Ihre Tickets') . '">
                            </div>
                            <div class="mb-3">
                                <label for="config_email_ticket_body" class="form-label">E-Mail-Text (HTML)</label>
                                <textarea class="form-control" id="config_email_ticket_body" name="config_email_ticket_body" rows="15">' . htmlspecialchars($configArray['email_ticket_body']['value'] ?? getDefaultTicketEmailBody()) . '</textarea>
                                <div class="alert alert-info mt-2 py-2 small">
                                    <i class="bi bi-braces"></i> <strong>Verfügbare Platzhalter:</strong><br>
                                    <code>{customer_name}</code>, <code>{event_name}</code>, <code>{event_date}</code>, <code>{event_time}</code>, <code>{category_name}</code>, <code>{price}</code>, <code>{ticket_link}</code> (URL), <code>{ticket_link_html}</code> (Download-Button), <code>{seat_list}</code> / <code>{ticket_list}</code> (Sitzplatzliste), <code>{reservation_count}</code>, <code>{voucher_codes}</code>, <code>{seatplan_link}</code> (URL Sitzplan), <code>{seatplan_link_html}</code> (Sitzplan-Button)
                                </div>
                            </div>
                        </div>

                        <div class="tab-pane fade" id="voucher" role="tabpanel">
                            <div class="mb-3">
                                <label for="config_email_voucher_subject" class="form-label">Betreff</label>
                                <input type="text" class="form-control" id="config_email_voucher_subject" name="config_email_voucher_subject"
                                       value="' . htmlspecialchars($configArray['email_voucher_subject']['value'] ?? 'Ihr Gutschein') . '">
                            </div>
                            <div class="mb-3">
                                <label for="config_email_voucher_body" class="form-label">E-Mail-Text (HTML)</label>
                                <textarea class="form-control" id="config_email_voucher_body" name="config_email_voucher_body" rows="15">' . htmlspecialchars($configArray['email_voucher_body']['value'] ?? getDefaultVoucherEmailBody()) . '</textarea>
                                <div class="alert alert-info mt-2 py-2 small">
                                    <i class="bi bi-braces"></i> <strong>Verfügbare Platzhalter:</strong><br>
                                    <code>{customer_name}</code>, <code>{voucher_codes}</code> (alle Codes kommagetrennt), <code>{voucher_list}</code> (HTML-Liste mit Details), <code>{event_name}</code>, <code>{category_name}</code>, <code>{quantity}</code> (Anzahl), <code>{voucher_link}</code>, <code>{ticket_url}</code> (Basis-URL des Systems)
                                </div>
                            </div>
                        </div>

                        <div class="tab-pane fade" id="pickup" role="tabpanel">
                            <div class="mb-3">
                                <label for="config_email_pickup_subject" class="form-label">Betreff</label>
                                <input type="text" class="form-control" id="config_email_pickup_subject" name="config_email_pickup_subject"
                                       value="' . htmlspecialchars($configArray['email_pickup_subject']['value'] ?? 'Abholung bestätigt') . '">
                            </div>
                            <div class="mb-3">
                                <label for="config_email_pickup_body" class="form-label">E-Mail-Text (HTML)</label>
                                <textarea class="form-control" id="config_email_pickup_body" name="config_email_pickup_body" rows="15">' . htmlspecialchars($configArray['email_pickup_body']['value'] ?? getDefaultPickupEmailBody()) . '</textarea>
                                <div class="alert alert-info mt-2 py-2 small">
                                    <i class="bi bi-braces"></i> <strong>Verfügbare Platzhalter:</strong><br>
                                    <code>{customer_name}</code>, <code>{event_name}</code>, <code>{event_date}</code>, <code>{event_time}</code>, <code>{category_name}</code>, <code>{price}</code>, <code>{seat_list}</code> (Sitzplatzliste), <code>{reservation_count}</code>, <code>{seatplan_link}</code> (URL Sitzplan), <code>{seatplan_link_html}</code> (Sitzplan-Button)
                                </div>
                            </div>
                        </div>

                        <div class="tab-pane fade" id="presales" role="tabpanel">
                            <div class="mb-3">
                                <label for="config_email_presales_subject" class="form-label">Betreff</label>
                                <input type="text" class="form-control" id="config_email_presales_subject" name="config_email_presales_subject"
                                       value="' . htmlspecialchars($configArray['email_presales_subject']['value'] ?? 'Ihre Tickets – Vorverkauf') . '">
                            </div>
                            <div class="mb-3">
                                <label for="config_email_presales_body" class="form-label">E-Mail-Text (HTML)</label>
                                <textarea class="form-control" id="config_email_presales_body" name="config_email_presales_body" rows="15">' . htmlspecialchars($configArray['email_presales_body']['value'] ?? getDefaultPresalesEmailBody()) . '</textarea>
                                <div class="alert alert-info mt-2 py-2 small">
                                    <i class="bi bi-braces"></i> <strong>Verfügbare Platzhalter:</strong><br>
                                    <code>{customer_name}</code>, <code>{event_name}</code>, <code>{event_date}</code>, <code>{event_time}</code>, <code>{category_name}</code>, <code>{price}</code>, <code>{seat_list}</code> (Sitzplatzliste), <code>{reservation_count}</code>, <code>{seatplan_link}</code> (URL Sitzplan), <code>{seatplan_link_html}</code> (Sitzplan-Button)
                                </div>
                                <div class="alert alert-warning mt-2 py-2 small">
                                    <i class="bi bi-info-circle"></i> Diese E-Mail wird beim Kartenverkauf im Vorverkauf gesendet und enthält <strong>keinen</strong> Download-Link – die Karten werden direkt ausgehändigt.
                                </div>
                            </div>
                        </div>

                        <div class="tab-pane fade" id="combined" role="tabpanel">
                            <div class="mb-3">
                                <label for="config_email_combined_purchase_subject" class="form-label">Betreff</label>
                                <input type="text" class="form-control" id="config_email_combined_purchase_subject" name="config_email_combined_purchase_subject"
                                       value="' . htmlspecialchars($configArray['email_combined_purchase_subject']['value'] ?? 'Ihre Bestellung') . '">
                            </div>
                            <div class="mb-3">
                                <label for="config_email_combined_purchase_body" class="form-label">E-Mail-Text (HTML)</label>
                                <textarea class="form-control" id="config_email_combined_purchase_body" name="config_email_combined_purchase_body" rows="15">' . htmlspecialchars($configArray['email_combined_purchase_body']['value'] ?? getDefaultCombinedPurchaseEmailBody()) . '</textarea>
                                <div class="alert alert-info mt-2 py-2 small">
                                    <i class="bi bi-braces"></i> <strong>Verfügbare Platzhalter:</strong><br>
                                    <code>{customer_name}</code>, <code>{event_name}</code>, <code>{event_date}</code>, <code>{event_time}</code>, <code>{category_name}</code>, <code>{price}</code>, <code>{seat_list}</code>, <code>{reservation_count}</code>, <code>{ticket_link}</code> (URL), <code>{ticket_link_html}</code> (Download-Button), <code>{ticket_section}</code> (fertiger Tickets-Block), <code>{voucher_section}</code> (fertiger Gutscheine-Block), <code>{voucher_list}</code> (HTML-Liste), <code>{voucher_codes}</code>, <code>{quantity}</code> (Anzahl Gutscheine), <code>{ticket_url}</code> (Basis-URL), <code>{seatplan_link}</code> (URL Sitzplan), <code>{seatplan_link_html}</code> (Sitzplan-Button)
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="mt-4">
                <button type="submit" class="btn btn-primary">
                    <i class="bi bi-save"></i> Einstellungen speichern
                </button>
            </div>
        </form>
    </div>
</div>
';

$additionalJs = '<script>
// Tab-Persistenz: aktiven Tab in URL (?tab=...) und Hidden-Input merken,
// damit Reload und Speichern auf demselben Tab landen.
(function() {
    var tabEls = document.querySelectorAll("#settingsTabs button[data-bs-toggle=\"tab\"]");
    var hiddenInput = document.getElementById("active_tab_input");
    tabEls.forEach(function(tabEl) {
        tabEl.addEventListener("shown.bs.tab", function(e) {
            var target = (e.target.getAttribute("data-bs-target") || "").replace("#tab-", "");
            if (!target) return;
            if (hiddenInput) hiddenInput.value = target;
            try {
                var url = new URL(window.location.href);
                url.searchParams.set("tab", target);
                window.history.replaceState({}, "", url);
            } catch (err) { /* ignore (z.B. file:// URLs) */ }
        });
    });
})();

// CRON-URL in die Zwischenablage kopieren
(function() {
    var btn = document.getElementById("cron-copy-btn");
    var input = document.getElementById("cron-report-url");
    if (!btn || !input) return;
    btn.addEventListener("click", function() {
        input.select();
        input.setSelectionRange(0, 99999);
        try {
            navigator.clipboard.writeText(input.value).then(function() {
                var prev = btn.innerHTML;
                btn.innerHTML = "<i class=\"bi bi-check\"></i>";
                setTimeout(function() { btn.innerHTML = prev; }, 1500);
            });
        } catch (err) {
            document.execCommand("copy");
        }
    });
})();

document.querySelectorAll(".send-test-email-btn").forEach(function(btn) {
    btn.addEventListener("click", function() {
        var type = this.dataset.type;
        var email = document.getElementById("test-email-recipient").value.trim();
        var resultDiv = document.getElementById("test-email-result");
        if (!email) {
            resultDiv.innerHTML = "<div class=\"alert alert-warning py-1 small\">Bitte eine E-Mail-Adresse eingeben.</div>";
            return;
        }
        btn.disabled = true;
        resultDiv.innerHTML = "<span class=\"text-muted small\"><i class=\"bi bi-hourglass-split\"></i> Wird gesendet\u2026</span>";
        fetch(' . json_encode(url('api/admin_send_test_email.php')) . ', {
            method: "POST",
            headers: {"Content-Type": "application/json"},
            body: JSON.stringify({type: type, email: email})
        })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.success) {
                resultDiv.innerHTML = "<div class=\"alert alert-success py-1 small\"><i class=\"bi bi-check-circle\"></i> " + data.message + "</div>";
            } else {
                resultDiv.innerHTML = "<div class=\"alert alert-danger py-1 small\"><i class=\"bi bi-x-circle\"></i> " + data.error + "</div>";
            }
            btn.disabled = false;
        })
        .catch(function() {
            resultDiv.innerHTML = "<div class=\"alert alert-danger py-1 small\">Netzwerkfehler</div>";
            btn.disabled = false;
        });
    });
});
</script>';

require __DIR__ . '/../../app/views/layouts/main.php';
