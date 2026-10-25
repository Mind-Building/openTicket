<?php
/**
 * Test script to verify logging and Monolog integration
 */

require_once __DIR__ . '/../app/bootstrap.php';

use App\Services\LoggerService;

try {
    echo "Testing Logging Service...\n";
    
    // Check if LoggerService exists
    if (!class_exists('App\Services\LoggerService')) {
        throw new Exception("LoggerService class not found.");
    }
    
    // Attempt to log something
    LoggerService::logAdmin('test_action', ['test' => 'data'], 'debug');
    
    echo "✓ Admin log entry created.\n";
    
    // Check if log directory exists
    $logPath = LoggerService::getLogPath();
    echo "Log path: $logPath\n";
    
    if (is_dir($logPath)) {
        echo "✓ Log directory exists.\n";
        
        $files = scandir($logPath);
        echo "Log files: " . implode(', ', array_diff($files, ['.', '..'])) . "\n";
    } else {
        echo "✗ Log directory does NOT exist!\n";
    }
    
    echo "\nVerification complete. Please check the log files manually if needed.\n";

} catch (Exception $e) {
    echo "Verification failed: " . $e->getMessage() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
}
