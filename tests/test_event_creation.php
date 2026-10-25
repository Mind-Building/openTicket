<?php
/**
 * Test script to verify event creation with the new schema
 */

require_once __DIR__ . '/../app/bootstrap.php';

use App\Models\Event;

try {
    echo "Testing Event Creation...\n";
    
    $eventModel = new Event();
    
    $data = [
        'name' => 'Test-Veranstaltung Schema Fix ' . time(),
        'active' => 1,
        'sell_tickets' => 1,
        'sell_vouchers' => 0,
        'use_seat_plan' => 1,
        'max_seats' => 100,
        'show_start_time' => '20:30',
        'max_reservation_time' => '17:00'
    ];
    
    $eventId = $eventModel->create($data);
    
    if ($eventId > 0) {
        echo "✓ Event created successfully with ID: $eventId\n";
        
        // Delete test event
        $eventModel->delete($eventId);
        echo "✓ Test event cleaned up.\n";
    } else {
        echo "✗ Event creation failed!\n";
    }
    
    echo "\nVerification complete.\n";

} catch (Exception $e) {
    echo "Verification failed: " . $e->getMessage() . "\n";
}
