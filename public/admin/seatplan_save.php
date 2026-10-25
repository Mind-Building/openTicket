<?php
require_once __DIR__ . '/../../app/bootstrap.php';

use App\Core\Auth;
use App\Core\Database;
use App\Models\SeatPlan;

header('Content-Type: application/json');

Auth::requireAdmin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

// CSRF-Schutz: Token kommt bei diesem JSON-Endpoint per Header (gesetzt in seatplan.php)
if (!\App\Core\Csrf::validate($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Sicherheits-Token fehlt oder ist abgelaufen. Bitte die Seite neu laden.']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);

if (!isset($input['event_id']) || !isset($input['rows'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid data']);
    exit;
}

$eventId = (int)$input['event_id'];
$rows = $input['rows'];
$force = !empty($input['force']); // Admin can override with explicit force flag

$db = Database::getInstance();
$connection = $db->getConnection();

try {
    // A missing migration otherwise surfaces only as the generic "Database query failed." in production
    $missingColumns = [];
    $requiredColumns = [
        'alt_row' => 'database/migrate_alternative_seats.sql',
        'alt_seat' => 'database/migrate_alternative_seats.sql',
        'consumes_number' => 'database/migrate_wheelchair.sql',
    ];
    foreach ($requiredColumns as $column => $migration) {
        $exists = $db->query("SHOW COLUMNS FROM seat_plan_elements LIKE " . $connection->quote($column))->fetch();
        if (!$exists) {
            $missingColumns[$migration][] = $column;
        }
    }
    if ($missingColumns) {
        $parts = [];
        foreach ($missingColumns as $migration => $columns) {
            $parts[] = $migration . ' (Spalten: ' . implode(', ', $columns) . ')';
        }
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'error' => 'Die Datenbank ist nicht auf dem aktuellen Stand. Bitte folgende Migration ausführen: ' . implode('; ', $parts),
        ]);
        exit;
    }

    // Block saving if confirmed reservations exist (unless force=true)
    if (!$force) {
        $stmt = $db->query(
            "SELECT COUNT(*) as cnt FROM reservations WHERE event_id = :eid AND status_id >= 2",
            [':eid' => $eventId]
        );
        $soldCount = (int)($stmt->fetch()['cnt'] ?? 0);
        if ($soldCount > 0) {
            http_response_code(409);
            echo json_encode([
                'success' => false,
                'sold_tickets' => $soldCount,
                'error' => "Für diese Veranstaltung gibt es bereits $soldCount verkaufte/reservierte Tickets. Sitzplan-Änderungen könnten bestehende Buchungen beschädigen.",
            ]);
            exit;
        }
    }

    $seatPlanModel = new SeatPlan();

    // All or nothing: a failure halfway must not leave rows without their elements
    $connection->beginTransaction();

    // Get existing rows for this event
    $existingRows = $seatPlanModel->getRows($eventId);
    $existingRowIds = array_column($existingRows, 'id');
    $newRowIds = [];
    
    // Process each row
    foreach ($rows as $rowIndex => $rowData) {
        $rowId = isset($rowData['id']) && $rowData['id'] > 0 ? (int)$rowData['id'] : null;
        $rowNumber = (int)$rowData['no'];
        $categoryId = !empty($rowData['category']) ? (int)$rowData['category'] : null;
        $displayOrder = $rowIndex;
        $rowName = !empty($rowData['row_name']) ? trim($rowData['row_name']) : null;
        
        // Save or create row
        if ($rowId && in_array($rowId, $existingRowIds)) {
            // Update existing row
            $seatPlanModel->saveRow($eventId, $rowNumber, $categoryId, $displayOrder, $rowName);
            $newRowIds[] = $rowId;
        } else {
            // Create new row
            $rowId = $seatPlanModel->saveRow($eventId, $rowNumber, $categoryId, $displayOrder, $rowName);
            $newRowIds[] = $rowId;
        }
        
        // Get existing elements for this row
        $rowWithElements = $seatPlanModel->getRowWithElements($rowId);
        $existingElements = $rowWithElements['elements'] ?? [];
        $existingElementIds = array_column($existingElements, 'id');
        $newElementIds = [];
        
        // Process elements
        if (isset($rowData['content']) && is_array($rowData['content'])) {
            foreach ($rowData['content'] as $position => $elementData) {
                $elementId = isset($elementData['id']) && $elementData['id'] > 0 ? (int)$elementData['id'] : null;
                $elementType = $elementData['type'];
                $categoryId = !empty($elementData['category']) ? (int)$elementData['category'] : null;
                
                $elementSaveData = [
                    'element_type' => $elementType,
                    'position' => $position,
                    'category_id' => $categoryId,
                ];
                
                if ($elementType === 'seat' || $elementType === 'wheelchair') {
                    // Skip seats that belong to a table (they are saved separately)
                    // Table seats have table_number set, so we check if this seat has table_number in the data
                    if (isset($elementData['table_number']) && $elementData['table_number'] !== null) {
                        continue; // Skip - this is a table seat, will be saved with the table
                    }

                    // Validate seat number
                    if (!isset($elementData['no']) || $elementData['no'] === null || $elementData['no'] === '') {
                        continue; // Skip seats with invalid numbers
                    }

                    $elementSaveData['element_number'] = (int)$elementData['no'];
                    $elementSaveData['alt_row'] = $elementData['alt_row'] ?? null;
                    $elementSaveData['alt_seat'] = $elementData['alt_seat'] ?? null;

                    if ($elementId && in_array($elementId, $existingElementIds)) {
                        $seatPlanModel->updateElement($elementId, $elementSaveData);
                        $newElementIds[] = $elementId;
                    } else {
                        $newElementId = $seatPlanModel->addElement($rowId, $elementSaveData);
                        $newElementIds[] = $newElementId;
                    }
                } elseif ($elementType === 'table') {
                    $elementSaveData['table_number'] = (int)$elementData['no'];
                    
                    // Save table element first
                    if ($elementId && in_array($elementId, $existingElementIds)) {
                        $seatPlanModel->updateElement($elementId, $elementSaveData);
                        $tableElementId = $elementId;
                    } else {
                        $tableElementId = $seatPlanModel->addElement($rowId, $elementSaveData);
                    }
                    $newElementIds[] = $tableElementId;
                    
                    // Handle table seats if present
                    // First, delete existing table seats for this table
                    $existingTableSeats = $seatPlanModel->getTableSeatsForTable($rowId, (int)$elementData['no']);
                    foreach ($existingTableSeats as $existingSeatId) {
                        $seatPlanModel->deleteElement($existingSeatId);
                    }
                    
                    // Then add new table seats
                    // seats is an object with area keys (left, right, back, front), each containing an array
                    if (isset($elementData['seats']) && (is_array($elementData['seats']) || is_object($elementData['seats']))) {
                        foreach ($elementData['seats'] as $area => $seats) {
                            if (in_array($area, ['left', 'right', 'back', 'front']) && is_array($seats)) {
                                foreach ($seats as $seat) {
                                    // Skip invalid seat data - must have a valid number
                                    if (!isset($seat['number']) || $seat['number'] === null || $seat['number'] === '') {
                                        continue;
                                    }
                                    
                                    $seatNumber = (int)$seat['number'];
                                    if ($seatNumber <= 0) {
                                        continue; // Skip invalid seat numbers
                                    }
                                    
                                    $seatData = [
                                        'element_type' => 'seat',
                                        'position' => $position, // Same position as table
                                        'table_number' => (int)$elementData['no'],
                                        'table_seat_area' => $area,
                                        'table_seat_number' => $seatNumber,
                                        'alt_row' => $seat['alt_row'] ?? null,
                                        'alt_seat' => $seat['alt_seat'] ?? null,
                                        'category_id' => !empty($seat['category']) ? (int)$seat['category'] : null,
                                    ];
                                    $newSeatId = $seatPlanModel->addElement($rowId, $seatData);
                                    $newElementIds[] = $newSeatId; // Add to list so they don't get deleted
                                }
                            }
                        }
                    }
                } elseif ($elementType === 'stage') {
                    // The stage is implied by row 0 and not stored (no 'stage' value in the element_type enum)
                    continue;
                } elseif ($elementType === 'distance' || $elementType === 'spacer') {
                    $elementSaveData['element_number'] = isset($elementData['no']) ? (int)$elementData['no'] : $position;
                    $elementSaveData['consumes_number'] = !empty($elementData['consumes_number']) ? 1 : 0;
                    $elementSaveData['is_stairs'] = !empty($elementData['stairs']) ? 1 : 0;

                    if ($elementId && in_array($elementId, $existingElementIds)) {
                        $seatPlanModel->updateElement($elementId, $elementSaveData);
                        $newElementIds[] = $elementId;
                    } else {
                        $newElementId = $seatPlanModel->addElement($rowId, $elementSaveData);
                        $newElementIds[] = $newElementId;
                    }
                }
            }
        }
        
        // Delete elements that are no longer in the row
        foreach ($existingElements as $existingElement) {
            if (!in_array($existingElement['id'], $newElementIds)) {
                $seatPlanModel->deleteElement($existingElement['id']);
            }
        }
    }
    
    // Delete rows that are no longer in the plan
    foreach ($existingRows as $existingRow) {
        if (!in_array($existingRow['id'], $newRowIds)) {
            $seatPlanModel->deleteRow($existingRow['id']);
        }
    }
    
    $connection->commit();

    echo json_encode(['success' => true, 'message' => 'Sitzplan gespeichert']);

} catch (Exception $e) {
    if ($connection->inTransaction()) {
        $connection->rollBack();
    }
    // Admin-only endpoint: expose the underlying MySQL error, otherwise production only says "Database query failed."
    $detail = $e->getPrevious() ? $e->getPrevious()->getMessage() : $e->getMessage();
    \App\Services\LoggerService::logAdmin('seatplan_save_failed', [
        'event_id' => $eventId,
        'error' => $detail,
    ], 'error');
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $detail]);
}

