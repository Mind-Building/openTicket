<?php
/**
 * API Endpoint to update category for all seats in a row
 */

if (ob_get_level()) {
    ob_clean();
} else {
    ob_start();
}

require_once __DIR__ . '/../../app/bootstrap.php';

use App\Core\Auth;
use App\Core\Session;
use App\Models\SeatPlan;

Session::start();

try {
    Auth::requireAdmin();
} catch (\Exception $e) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Nicht autorisiert.']);
    exit;
}

header('Content-Type: application/json; charset=utf-8');

try {
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new \Exception('Ungültige JSON-Daten: ' . json_last_error_msg());
    }
    
    $rowId = (int)($input['row_id'] ?? 0);
    $categoryId = isset($input['category_id']) && $input['category_id'] !== '' && $input['category_id'] !== null ? (int)$input['category_id'] : null;

    if ($rowId === 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Ungültige Reihen-ID.']);
        exit;
    }

    $seatPlanModel = new SeatPlan();
    $updatedCount = $seatPlanModel->updateRowSeatsCategory($rowId, $categoryId);
    
    echo json_encode([
        'success' => true,
        'message' => $updatedCount . ' Sitzplatz(e) erfolgreich aktualisiert.',
        'updated_count' => $updatedCount
    ]);
} catch (\Exception $e) {
    error_log("Error updating row seats category: " . $e->getMessage());
    error_log("Stack trace: " . $e->getTraceAsString());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Fehler: ' . $e->getMessage()]);
}
exit;

