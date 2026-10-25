<?php
/**
 * API Endpoint to get seat categories for an event
 */

if (ob_get_level()) {
    ob_clean();
} else {
    ob_start();
}

require_once __DIR__ . '/../../app/bootstrap.php';

use App\Models\SeatCategory;

header('Content-Type: application/json; charset=utf-8');

try {
    $eventId = isset($_GET['event_id']) ? (int)$_GET['event_id'] : 0;
    
    if ($eventId <= 0) {
        throw new \Exception('Ungültige Event-ID');
    }
    
    $categoryModel = new SeatCategory();
    $categories = $categoryModel->getByEvent($eventId);
    
    echo json_encode([
        'success' => true,
        'categories' => $categories
    ]);
} catch (\Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
exit;
