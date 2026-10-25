<?php
/**
 * API Endpoint to generate ticket preview
 */

// Start output buffering early and clean any existing output
while (ob_get_level()) {
    ob_end_clean();
}
ob_start();

// Suppress any warnings/notices that might cause output
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

require_once __DIR__ . '/../../app/bootstrap.php';

use App\Core\Auth;
use App\Models\Event;
use App\Services\TicketPdfGenerator;

try {
    // Clean output buffer before authentication
    if (ob_get_level()) {
        ob_clean();
    }
    
    Auth::requireAuth();
    
    // Clean again after auth (in case auth generated output)
    if (ob_get_level()) {
        ob_clean();
    }
    
    $eventId = isset($_GET['event_id']) ? (int)$_GET['event_id'] : 0;
    $configJson = $_GET['config'] ?? null;
    $scope = (isset($_GET['scope']) && $_GET['scope'] === 'presales') ? 'presales' : 'customer';

    if ($eventId <= 0) {
        throw new \Exception('Invalid event ID');
    }

    $eventModel = new Event();
    $event = $eventModel->getById($eventId);

    if (!$event) {
        throw new \Exception('Event not found or no template configured');
    }

    // Parse config: live preview from editor (URL) wins; otherwise load stored config.
    // Stored-config fallback chain for presales scope: presales config -> customer config.
    $config = [];
    if ($configJson) {
        $config = json_decode(urldecode($configJson), true) ?: [];
    } else {
        $storedConfigJson = '';
        if ($scope === 'presales' && !empty($event['presales_ticket_template_config'])) {
            $storedConfigJson = $event['presales_ticket_template_config'];
        } else {
            $storedConfigJson = $event['ticket_template_config'] ?? '';
        }
        if (!empty($storedConfigJson)) {
            $config = json_decode($storedConfigJson, true) ?: [];
        }
    }

    // Clean all output buffers before generating PDF
    while (ob_get_level()) {
        ob_end_clean();
    }

    // Create preview with test data. Template path + mode are resolved inside outputPreview().
    $previewGenerator = new TicketPdfGenerator();
    $previewGenerator->outputPreview($eventId, $config, $scope);
    
    exit;
} catch (\Exception $e) {
    // Clean all output buffers
    while (ob_get_level()) {
        ob_end_clean();
    }
    
    // Log error for debugging
    error_log("Ticket Preview Error: " . $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine());
    
    http_response_code(500);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Error</title></head><body>';
    echo '<h1>Fehler bei der PDF-Generierung</h1>';
    echo '<p>' . htmlspecialchars($e->getMessage()) . '</p>';
    echo '</body></html>';
    exit;
} catch (\Error $e) {
    // Clean all output buffers
    while (ob_get_level()) {
        ob_end_clean();
    }

    error_log("Ticket Preview Fatal Error: " . $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine());

    http_response_code(500);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Error</title></head><body>';
    echo '<h1>Fehler bei der PDF-Generierung</h1>';
    echo '<p>' . htmlspecialchars($e->getMessage()) . '</p>';
    echo '</body></html>';
    exit;
}
