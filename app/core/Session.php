<?php
/**
 * Session Management
 */

namespace App\Core;

class Session
{
    private static bool $started = false;
    private const SESSION_TIMEOUT_KEY = '_session_expires_at';
    private const DEFAULT_TIMEOUT_MINUTES = 30;
    private const RESERVATION_TIMEOUT_KEY = '_reservation_expires_at';
    private const RESERVATION_TIMEOUT_MINUTES = 15;

    /**
     * Set secure session cookie attributes. Must run before session_start() –
     * also used by pages that restart the session manually (checkout_success.php).
     */
    public static function applyCookieParams(): void
    {
        if (session_status() !== PHP_SESSION_NONE || headers_sent()) {
            return;
        }
        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => '/',
            'secure'   => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }

    public static function start(): void
    {
        if (!self::$started) {
            self::applyCookieParams();
            session_start();
            self::$started = true;
            
            // Initialize session expiry timestamp if not set
            if (!isset($_SESSION[self::SESSION_TIMEOUT_KEY])) {
                self::resetTimeout();
            }
            
            // Only log session creation if not already logged for this session
            if (!isset($_SESSION['_session_logged'])) {
                if (function_exists('logSessionCreated')) {
                    logSessionCreated(session_id(), $_SESSION['user_id'] ?? null);
                }
                $_SESSION['_session_logged'] = true;
            }
        }
    }

    /**
     * Get session expiry timestamp
     */
    public static function getTimeout(): int
    {
        self::start();
        return (int)($_SESSION[self::SESSION_TIMEOUT_KEY] ?? 0);
    }

    /**
     * Reset session timeout to default duration
     */
    public static function resetTimeout(?int $minutes = null): int
    {
        self::start();
        $minutes = $minutes ?? self::DEFAULT_TIMEOUT_MINUTES;
        $_SESSION[self::SESSION_TIMEOUT_KEY] = time() + ($minutes * 60);
        return $_SESSION[self::SESSION_TIMEOUT_KEY];
    }

    /**
     * Check if session has expired
     */
    public static function isExpired(): bool
    {
        self::start();
        $expiresAt = $_SESSION[self::SESSION_TIMEOUT_KEY] ?? 0;
        return $expiresAt > 0 && time() > $expiresAt;
    }

    /**
     * Get remaining seconds until session expires
     */
    public static function getRemainingSeconds(): int
    {
        self::start();
        $expiresAt = $_SESSION[self::SESSION_TIMEOUT_KEY] ?? 0;
        $remaining = $expiresAt - time();
        return max(0, $remaining);
    }

    /**
     * Get default timeout in minutes
     */
    public static function getDefaultTimeoutMinutes(): int
    {
        return self::DEFAULT_TIMEOUT_MINUTES;
    }

    /**
     * Reset reservation countdown (15 min timer for seat reservations)
     */
    public static function resetReservationTimeout(): int
    {
        self::start();
        $_SESSION[self::RESERVATION_TIMEOUT_KEY] = time() + (self::RESERVATION_TIMEOUT_MINUTES * 60);
        return $_SESSION[self::RESERVATION_TIMEOUT_KEY];
    }

    /**
     * Get reservation expiry timestamp
     */
    public static function getReservationTimeout(): int
    {
        self::start();
        return (int)($_SESSION[self::RESERVATION_TIMEOUT_KEY] ?? 0);
    }

    /**
     * Clear reservation timeout (when cart is empty)
     */
    public static function clearReservationTimeout(): void
    {
        self::start();
        unset($_SESSION[self::RESERVATION_TIMEOUT_KEY]);
    }

    /**
     * Check if reservation timer has expired
     */
    public static function isReservationExpired(): bool
    {
        self::start();
        $expiresAt = $_SESSION[self::RESERVATION_TIMEOUT_KEY] ?? 0;
        return $expiresAt > 0 && time() > $expiresAt;
    }

    /**
     * Get reservation timeout in minutes
     */
    public static function getReservationTimeoutMinutes(): int
    {
        return self::RESERVATION_TIMEOUT_MINUTES;
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        self::start();
        return $_SESSION[$key] ?? $default;
    }

    public static function set(string $key, mixed $value): void
    {
        self::start();
        $_SESSION[$key] = $value;
    }

    public static function has(string $key): bool
    {
        self::start();
        return isset($_SESSION[$key]);
    }

    public static function remove(string $key): void
    {
        self::start();
        unset($_SESSION[$key]);
    }

    public static function destroy(): void
    {
        self::start();
        $sessionId = session_id();
        $userId = $_SESSION['user_id'] ?? null;
        
        session_destroy();
        $_SESSION = [];
        self::$started = false;
        
        if (function_exists('logSessionDestroyed')) {
            logSessionDestroyed($sessionId, $userId, 'destroy_called');
        }
    }

    public static function id(): string
    {
        self::start();
        return session_id();
    }

    public static function regenerate(): void
    {
        self::start();
        $oldSessionId = session_id();
        session_regenerate_id(true);
        
        if (function_exists('logSessionCreated')) {
            logSessionCreated(session_id());
        }
    }
}

