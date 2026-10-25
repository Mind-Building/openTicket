<?php
/**
 * Comprehensive test script to verify all identified schema fixes
 */

require_once __DIR__ . '/../app/bootstrap.php';

use App\Models\Event;
use App\Models\SeatPlan;
use App\Models\Reservation;
use App\Models\Voucher;

try {
    echo "Starting Comprehensive Schema Verification...\n";
    
    // 1. Test Event Creation
    echo "\n[1] Testing Event Creation...\n";
    $eventModel = new Event();
    $eventId = $eventModel->create([
        'name' => 'Test-Veranstaltung Final ' . time(),
        'active' => 1,
        'max_seats' => 100
    ]);
    echo "✓ Event created successfully with ID: $eventId\n";

    // 2. Test Seat Plan Row Saving
    echo "\n[2] Testing Seat Plan Row Saving...\n";
    $seatPlanModel = new SeatPlan();
    $rowId = $seatPlanModel->saveRow($eventId, 1, null, 0, 'Parkett Reihe 1');
    echo "✓ Seat plan row created successfully with ID: $rowId\n";
    
    // Verify row name was saved
    $rows = $seatPlanModel->getRows($eventId);
    if (!empty($rows) && $rows[0]['row_name'] === 'Parkett Reihe 1') {
        echo "✓ Row name 'Parkett Reihe 1' correctly saved.\n";
    } else {
        throw new Exception("Row name was not saved correctly!");
    }

    // 3. Test Voucher Creation
    echo "\n[3] Testing Voucher Creation...\n";
    $voucherModel = new Voucher();
    // We need a seat category for voucher
    $db = \App\Core\Database::getInstance();
    $db->query("INSERT INTO seat_categories (event_id, name, sign, price) VALUES ($eventId, 'Test Cat', 'T', 10.00)");
    $categoryId = (int)$db->lastInsertId();
    
    $voucherId = $voucherModel->create([
        'session_id' => 'test_session_' . time(),
        'event_id' => $eventId,
        'category_id' => $categoryId,
        'quantity' => 1,
        'purchase_intent' => 'buy'
    ]);
    echo "✓ Voucher created successfully with ID: $voucherId\n";

    // 4. Test Reservation with Voucher
    echo "\n[4] Testing Reservation with Voucher...\n";
    $resModel = new Reservation();
    $resId = $resModel->create([
        'session_id' => 'test_session_' . time(),
        'event_id' => $eventId,
        'event_date' => date('Y-m-d'),
        'seat_identifier' => 'seat_1_1',
        'voucher_id' => $voucherId
    ]);
    echo "✓ Reservation created successfully with ID: $resId\n";

    // Cleanup
    echo "\nCleaning up test data...\n";
    $db->query("DELETE FROM reservations WHERE id = $resId");
    $db->query("DELETE FROM vouchers WHERE id = $voucherId");
    $db->query("DELETE FROM seat_categories WHERE id = $categoryId");
    $eventModel->delete($eventId);
    echo "✓ Cleanup completed.\n";
    
    echo "\n===========================================\n";
    echo "✓ ALL SCHEMA VERIFICATIONS PASSED!\n";
    echo "===========================================\n";

} catch (Exception $e) {
    echo "\n✗ VERIFICATION FAILED: " . $e->getMessage() . "\n";
    exit(1);
}
