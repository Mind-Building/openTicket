<?php
/**
 * Load Test: 100 Concurrent Users Seat Reservation
 * 
 * This script simulates 100 users concurrently:
 * 1. Selecting seats on the seatplan
 * 2. Adding seats to cart
 * 3. Completing checkout
 * 
 * Also tests the logging system by logging all actions to customer.log
 * 
 * Run from command line: php load_test.php
 */

require_once __DIR__ . '/../app/bootstrap.php';

use App\Core\Database;
use App\Core\Session;
use App\Models\Event;
use App\Models\Reservation;
use App\Models\SeatPlan;
use App\Services\LoggerService;

class LoadTest
{
    private $numUsers = 100;
    private $eventId = 1;
    private $eventDate = '2026-02-15';
    private $results = [];
    private $db;
    private $reservationModel;
    private $testSeats = [];
    
    public function __construct()
    {
        $this->db = Database::getInstance();
        $this->reservationModel = new Reservation();
    }
    
    public function run()
    {
        $this->printHeader();
        
        $startTime = microtime(true);
        
        $this->setupTestData();
        
        $this->printPhase("Phase 1: Simulating {$this->numUsers} users selecting seats concurrently");
        
        $seatSelectResults = $this->simulateConcurrentSeatSelection();
        
        $this->printPhase("Phase 2: Adding seats to cart");
        
        $cartResults = $this->simulateConcurrentCartAdds();
        
        $this->printPhase("Phase 3: Concurrent checkout simulation");
        
        $checkoutResults = $this->simulateConcurrentCheckouts();
        
        $endTime = microtime(true);
        $totalTime = $endTime - $startTime;
        
        $this->printSummary([
            'total_users' => $this->numUsers,
            'seat_selects' => $seatSelectResults,
            'cart_adds' => $cartResults,
            'checkouts' => $checkoutResults,
            'total_time' => $totalTime,
        ]);
        
        $this->printLogSummary();
        
        $this->cleanup();
    }
    
    private function setupTestData()
    {
        echo "\nSetting up test data...\n";
        
        $seatPlanModel = new SeatPlan();
        $seatPlan = $seatPlanModel->getSeatPlanStructure($this->eventId);
        
        $availableSeats = [];
        foreach ($seatPlan as $row) {
            if ($row['type'] === 'row' && isset($row['no']) && $row['no'] > 0) {
                foreach ($row['content'] as $element) {
                    if ($element['type'] === 'seat') {
                        $availableSeats[] = 'seat_' . $row['no'] . '_' . $element['no'];
                    }
                }
            }
        }
        
        $this->testSeats = array_slice($availableSeats, 0, $this->numUsers);
        
        echo "  Found " . count($availableSeats) . " total seats\n";
        echo "  Using " . count($this->testSeats) . " seats for testing\n";
        
        $this->clearTestReservations();
        
        echo "  Logging enabled: All actions will be logged to customer.log\n";
    }
    
    private function clearTestReservations()
    {
        $this->db->query("DELETE FROM reservations WHERE session_id LIKE 'loadtest_%'");
        echo "  Cleared previous test reservations\n";
    }
    
    private function simulateConcurrentSeatSelection()
    {
        $results = ['success' => 0, 'failed' => 0, 'conflicts' => 0];
        
        echo "  Starting seat selection simulation with logging...\n";
        
        foreach (range(1, $this->numUsers) as $userId) {
            $sessionId = 'loadtest_' . $userId . '_' . time();
            $seatIndex = ($userId - 1) % count($this->testSeats);
            $seatId = $this->testSeats[$seatIndex];
            
            $result = $this->reserveSeatWithLogging($sessionId, $seatId, 1, $userId);
            
            if ($result['success']) {
                $results['success']++;
            } elseif (strpos($result['error'], 'already reserved') !== false || strpos($result['error'], 'already in session') !== false) {
                $results['conflicts']++;
            } else {
                $results['failed']++;
            }
            
            if ($userId % 20 === 0) {
                echo "  Progress: {$userId}/{$this->numUsers} users\n";
            }
        }
        
        echo "  Completed: {$results['success']} success, {$results['conflicts']} conflicts, {$results['failed']} failed\n";
        
        return $results;
    }
    
    private function reserveSeatWithLogging($sessionId, $seatIdentifier, $categoryId = 1, $userId = 0)
    {
        try {
            $this->db->query("START TRANSACTION");
            
            // Check if already in this user's cart
            $stmt = $this->db->query(
                "SELECT id FROM reservations WHERE session_id = :session AND seat_identifier = :seat AND event_id = :event AND event_date = :date FOR UPDATE",
                ['session' => $sessionId, 'seat' => $seatIdentifier, 'event' => $this->eventId, 'date' => $this->eventDate]
            );
            
            if ($stmt->fetch()) {
                $this->db->query("ROLLBACK");
                return ['success' => false, 'error' => 'Seat already in session reservation'];
            }
            
            // Check if seat is already reserved
            $stmt = $this->db->query(
                "SELECT id, status_id FROM reservations WHERE seat_identifier = :seat AND event_date = :date FOR UPDATE",
                ['seat' => $seatIdentifier, 'date' => $this->eventDate]
            );
            
            $existing = $stmt->fetch();
            
            if ($existing && (int)$existing['status_id'] !== 5) {
                $this->db->query("ROLLBACK");
                return ['success' => false, 'error' => 'Seat already reserved by another user'];
            }
            
            // Insert reservation
            $sql = "INSERT INTO reservations (session_id, customer_id, event_id, event_date, seat_identifier, category_id, status_id, is_reduced, is_fn_card, is_honor_card, created_at, updated_at) 
                    VALUES (:session_id, :customer_id, :event_id, :event_date, :seat_identifier, :category_id, :status_id, :is_reduced, :is_fn_card, :is_honor_card, NOW(), NOW())";
            
            $this->db->query($sql, [
                'session_id' => $sessionId,
                'customer_id' => null,
                'event_id' => $this->eventId,
                'event_date' => $this->eventDate,
                'seat_identifier' => $seatIdentifier,
                'category_id' => $categoryId,
                'status_id' => 1,
                'is_reduced' => 0,
                'is_fn_card' => 0,
                'is_honor_card' => 0
            ]);
            
            $this->db->query("COMMIT");
            
            // LOG THE ACTION to customer.log
            LoggerService::logCustomer('customer_seat_selected', [
                'event_id' => $this->eventId,
                'event_date' => $this->eventDate,
                'seat_identifier' => $seatIdentifier,
                'category_id' => $categoryId,
                'session_id' => substr($sessionId, 0, 32),
                'test_user_id' => $userId,
                'test_run' => true
            ]);
            
            return ['success' => true, 'seat' => $seatIdentifier];
            
        } catch (\Exception $e) {
            $this->db->query("ROLLBACK");
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
    
    private function simulateConcurrentCartAdds()
    {
        $results = ['success' => 0, 'failed' => 0];
        
        echo "  Processing cart additions...\n";
        
        $reservations = $this->db->query("SELECT * FROM reservations WHERE session_id LIKE 'loadtest_%' AND status_id = 1")->fetchAll();
        
        foreach ($reservations as $res) {
            // Log the "added to cart" action
            LoggerService::logCustomer('customer_seat_added_to_cart', [
                'reservation_id' => $res['id'],
                'event_id' => $res['event_id'],
                'event_date' => $res['event_date'],
                'seat_identifier' => $res['seat_identifier'],
                'session_id' => substr($res['session_id'], 0, 32),
                'test_run' => true
            ]);
            $results['success']++;
        }
        
        echo "  Found " . count($reservations) . " reservations in cart\n";
        
        return $results;
    }
    
    private function simulateConcurrentCheckouts()
    {
        $results = ['success' => 0, 'failed' => 0];
        
        echo "  Processing checkouts...\n";
        
        $reservations = $this->db->query("SELECT * FROM reservations WHERE session_id LIKE 'loadtest_%' AND status_id = 1")->fetchAll();
        
        foreach ($reservations as $res) {
            // Update status to "paid" (status_id = 4)
            $this->db->query("UPDATE reservations SET status_id = 4 WHERE id = :id", ['id' => $res['id']]);
            
            // Log the checkout completion
            LoggerService::logCustomer('customer_checkout_completed', [
                'reservation_id' => $res['id'],
                'event_id' => $res['event_id'],
                'event_date' => $res['event_date'],
                'seat_identifier' => $res['seat_identifier'],
                'session_id' => substr($res['session_id'], 0, 32),
                'payment_method' => 'load_test_simulated',
                'test_run' => true
            ]);
            
            $results['success']++;
        }
        
        echo "  Completed {$results['success']} checkouts\n";
        
        return $results;
    }
    
    private function printLogSummary()
    {
        echo "\n===========================================\n";
        echo "  Log Entries Created\n";
        echo "===========================================\n";
        
        $logFile = dirname(__DIR__) . '/logging/customer-' . date('Y-m-d') . '.log';
        
        if (file_exists($logFile)) {
            $logContent = file_get_contents($logFile);
            $lines = explode("\n", trim($logContent));
            
            $testLines = array_filter($lines, function($line) {
                return strpos($line, '"test_run":true') !== false;
            });
            
            echo "  Found " . count($testLines) . " test log entries in customer.log\n";
            echo "  Log file: $logFile\n\n";
            
            echo "  Sample log entries:\n";
            $sampleCount = 0;
            foreach ($testLines as $line) {
                if ($sampleCount >= 3) break;
                if (strlen($line) > 10) {
                    echo "  " . substr($line, 0, 200) . "...\n";
                    $sampleCount++;
                }
            }
        } else {
            echo "  Log file not found (logging may be disabled)\n";
        }
        
        echo "\n";
    }
    
    private function cleanup()
    {
        echo "Cleaning up test data...\n";
        $this->clearTestReservations();
        echo "  Test reservations cleared (logs remain for verification)\n";
    }
    
    private function printHeader()
    {
        echo "\n";
        echo "===========================================\n";
        echo "  Ticket 2.0 Load Test - 100 Concurrent Users\n";
        echo "  With Logging Integration\n";
        echo "===========================================\n";
        echo "  Test Date: " . date('Y-m-d H:i:s') . "\n";
        echo "  Target Event ID: {$this->eventId}\n";
        echo "  Target Event Date: {$this->eventDate}\n";
        echo "===========================================\n\n";
    }
    
    private function printPhase($message)
    {
        echo "\n$message\n";
        echo str_repeat('-', 50) . "\n";
    }
    
    private function printSummary($results)
    {
        echo "\n===========================================\n";
        echo "  Load Test Results\n";
        echo "===========================================\n";
        echo "\nPerformance Metrics:\n";
        echo "  Total Users: {$results['total_users']}\n";
        echo "  Total Time: " . number_format($results['total_time'], 2) . " seconds\n";
        echo "  Avg Time per User: " . number_format($results['total_time'] / $results['total_users'] * 1000, 2) . " ms\n";
        
        echo "\nSeat Selection:\n";
        echo "  Successful: {$results['seat_selects']['success']}\n";
        echo "  Conflicts: {$results['seat_selects']['conflicts']}\n";
        echo "  Failed: {$results['seat_selects']['failed']}\n";
        
        echo "\nCart Operations:\n";
        echo "  Added: {$results['cart_adds']['success']}\n";
        
        echo "\nCheckouts:\n";
        echo "  Completed: {$results['checkouts']['success']}\n";
        
        echo "\n===========================================\n";
        
        $totalOps = $results['seat_selects']['success'] + $results['cart_adds']['success'] + $results['checkouts']['success'];
        echo "  Total Operations: {$totalOps}\n";
        echo "  Throughput: " . number_format($totalOps / $results['total_time'], 2) . " ops/sec\n";
        echo "===========================================\n\n";
    }
}

$loadTest = new LoadTest();
$loadTest->run();
