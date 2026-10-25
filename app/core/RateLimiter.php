<?php
/**
 * Rate Limiter
 * Prevents brute force attacks by limiting request frequency
 */

namespace App\Core;

use App\Core\Database;

class RateLimiter
{
    private const DEFAULT_MAX_ATTEMPTS = 5;
    private const DEFAULT_WINDOW = 900; // 15 minutes in seconds
    private const DEFAULT_BLOCK_DURATION = 3600; // 1 hour in seconds

    /**
     * Check if action is rate limited
     * 
     * @param string $key Unique identifier (e.g., 'login:username' or 'api:ip')
     * @param int $maxAttempts Maximum attempts allowed
     * @param int $window Time window in seconds
     * @return array ['allowed' => bool, 'remaining' => int, 'reset_at' => int|null]
     */
    public static function check(string $key, int $maxAttempts = self::DEFAULT_MAX_ATTEMPTS, int $window = self::DEFAULT_WINDOW): array
    {
        $db = Database::getInstance();
        
        // Clean up old entries
        self::cleanup();
        
        // Get current attempts
        $sql = "SELECT attempts, first_attempt, blocked_until 
                FROM rate_limits 
                WHERE `key` = :key 
                LIMIT 1";
        
        try {
            $stmt = $db->query($sql, [':key' => $key]);
            $record = $stmt->fetch();
            
            // Check if blocked
            if ($record && $record['blocked_until']) {
                $blockedUntil = strtotime($record['blocked_until']);
                if (time() < $blockedUntil) {
                    return [
                        'allowed' => false,
                        'remaining' => 0,
                        'reset_at' => $blockedUntil,
                        'blocked' => true
                    ];
                } else {
                    // Block expired, reset
                    self::reset($key);
                    $record = null;
                }
            }
            
            if (!$record) {
                // First attempt
                return [
                    'allowed' => true,
                    'remaining' => $maxAttempts - 1,
                    'reset_at' => time() + $window,
                    'blocked' => false
                ];
            }
            
            $firstAttempt = strtotime($record['first_attempt']);
            $attempts = (int)$record['attempts'];
            $windowStart = time() - $window;
            
            // Check if window has expired
            if ($firstAttempt < $windowStart) {
                // Window expired, reset
                self::reset($key);
                return [
                    'allowed' => true,
                    'remaining' => $maxAttempts - 1,
                    'reset_at' => time() + $window,
                    'blocked' => false
                ];
            }
            
            // Check if max attempts reached
            if ($attempts >= $maxAttempts) {
                // Block for extended period
                $blockedUntil = time() + self::DEFAULT_BLOCK_DURATION;
                self::block($key, $blockedUntil);
                return [
                    'allowed' => false,
                    'remaining' => 0,
                    'reset_at' => $blockedUntil,
                    'blocked' => true
                ];
            }
            
            // Still within limits
            $remaining = $maxAttempts - $attempts - 1;
            $resetAt = $firstAttempt + $window;
            
            return [
                'allowed' => true,
                'remaining' => max(0, $remaining),
                'reset_at' => $resetAt,
                'blocked' => false
            ];
            
        } catch (\Exception $e) {
            // If table doesn't exist, allow the request (fail open)
            error_log("RateLimiter error: " . $e->getMessage());
            return [
                'allowed' => true,
                'remaining' => $maxAttempts,
                'reset_at' => null,
                'blocked' => false
            ];
        }
    }

    /**
     * Record an attempt
     */
    public static function record(string $key): void
    {
        $db = Database::getInstance();
        
        $sql = "INSERT INTO rate_limits (`key`, attempts, first_attempt, last_attempt, blocked_until)
                VALUES (:key, 1, NOW(), NOW(), NULL)
                ON DUPLICATE KEY UPDATE 
                    attempts = attempts + 1,
                    last_attempt = NOW()";
        
        try {
            $db->query($sql, [':key' => $key]);
        } catch (\Exception $e) {
            error_log("RateLimiter record error: " . $e->getMessage());
        }
    }

    /**
     * Reset attempts for a key
     */
    public static function reset(string $key): void
    {
        $db = Database::getInstance();
        
        $sql = "DELETE FROM rate_limits WHERE `key` = :key";
        
        try {
            $db->query($sql, [':key' => $key]);
        } catch (\Exception $e) {
            error_log("RateLimiter reset error: " . $e->getMessage());
        }
    }

    /**
     * Block a key for a specific duration
     */
    private static function block(string $key, int $blockedUntil): void
    {
        $db = Database::getInstance();
        
        $sql = "UPDATE rate_limits 
                SET blocked_until = FROM_UNIXTIME(:blocked_until)
                WHERE `key` = :key";
        
        try {
            $db->query($sql, [
                ':key' => $key,
                ':blocked_until' => $blockedUntil
            ]);
        } catch (\Exception $e) {
            error_log("RateLimiter block error: " . $e->getMessage());
        }
    }

    /**
     * Clean up old entries
     */
    private static function cleanup(): void
    {
        $db = Database::getInstance();
        
        // Delete entries older than 24 hours that are not blocked
        $sql = "DELETE FROM rate_limits 
                WHERE blocked_until IS NULL 
                AND last_attempt < DATE_SUB(NOW(), INTERVAL 24 HOUR)";
        
        try {
            $db->query($sql);
        } catch (\Exception $e) {
            // Ignore cleanup errors
        }
    }

    /**
     * Get client IP address
     */
    public static function getClientIp(): string
    {
        $ipKeys = ['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'REMOTE_ADDR'];
        
        foreach ($ipKeys as $key) {
            if (!empty($_SERVER[$key])) {
                $ip = $_SERVER[$key];
                // Handle comma-separated IPs (from proxies)
                if (strpos($ip, ',') !== false) {
                    $ips = explode(',', $ip);
                    $ip = trim($ips[0]);
                }
                // Validate IP
                if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                    return $ip;
                }
            }
        }
        
        return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    }
}

