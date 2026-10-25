<?php
/**
 * CSRF Protection
 * Generates and validates CSRF tokens
 */

namespace App\Core;

class Csrf
{
    private const SESSION_KEY = 'csrf_token';
    private const TOKEN_LENGTH = 32;

    /**
     * Generate a new CSRF token
     */
    public static function generate(): string
    {
        Session::start();
        
        // Generate random token
        $token = bin2hex(random_bytes(self::TOKEN_LENGTH));
        
        // Store in session
        Session::set(self::SESSION_KEY, $token);
        
        return $token;
    }

    /**
     * Get current CSRF token (generate if not exists)
     */
    public static function get(): string
    {
        Session::start();
        
        if (!Session::has(self::SESSION_KEY)) {
            return self::generate();
        }
        
        return Session::get(self::SESSION_KEY);
    }

    /**
     * Validate CSRF token
     */
    public static function validate(string $token): bool
    {
        Session::start();
        
        $sessionToken = Session::get(self::SESSION_KEY);
        
        if (empty($sessionToken) || empty($token)) {
            return false;
        }
        
        // Use hash_equals for timing-safe comparison
        return hash_equals($sessionToken, $token);
    }

    /**
     * Validate token from POST request
     */
    public static function validatePost(): bool
    {
        $token = $_POST['csrf_token'] ?? '';
        return self::validate($token);
    }

    /**
     * Validate token from GET request (for non-destructive operations)
     */
    public static function validateGet(): bool
    {
        $token = $_GET['csrf_token'] ?? '';
        return self::validate($token);
    }

    /**
     * Generate hidden input field HTML
     */
    public static function field(): string
    {
        $token = self::get();
        return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars($token, ENT_QUOTES, 'UTF-8') . '">';
    }

    /**
     * Get token value for use in JavaScript/AJAX
     */
    public static function token(): string
    {
        return self::get();
    }

    /**
     * Regenerate token (after successful validation for one-time use)
     */
    public static function regenerate(): void
    {
        Session::start();
        Session::remove(self::SESSION_KEY);
        self::generate();
    }
}

