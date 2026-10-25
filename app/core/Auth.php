<?php
/**
 * Authentication System
 * Handles user login/logout and authorization
 */

namespace App\Core;

use App\Core\Database;
use App\Core\Session;
use App\Models\DeviceToken;

class Auth
{
    private const SESSION_USER_KEY = 'user_id';
    private const SESSION_ROLE_KEY = 'user_role';
    private const SESSION_USERNAME_KEY = 'username';
    private const DEVICE_TOKEN_COOKIE = 'presales_device_token';

    // Pre-computed bcrypt hash used to keep timing constant when the supplied
    // username does not exist. password_verify() against this never matches a
    // real password, but takes the same time as a real verification – so the
    // response time can no longer leak whether the username is valid.
    private const DUMMY_PASSWORD_HASH = '$2y$10$gKFDBlwIQ8NpsLi9mx.1Z.pLI9fjVUbJNrLWVNAZy72045y3QqQA6';

    /**
     * Login user
     */
    public static function login(string $username, string $password): bool
    {
        $db = Database::getInstance();

        $sql = "SELECT id, username, email, password_hash, role, active
                FROM users
                WHERE username = :username AND active = 1";

        $stmt = $db->query($sql, [':username' => $username]);
        $user = $stmt->fetch();

        // Always run password_verify – against the user's real hash if the
        // user exists, otherwise against a dummy hash of equal cost – so the
        // response time does not reveal whether the username is valid.
        $hashToVerify = $user['password_hash'] ?? self::DUMMY_PASSWORD_HASH;
        $passwordOk   = password_verify($password, $hashToVerify);

        if ($user && $passwordOk) {
            Session::regenerate();
            
            Session::set(self::SESSION_USER_KEY, $user['id']);
            Session::set(self::SESSION_ROLE_KEY, $user['role']);
            Session::set(self::SESSION_USERNAME_KEY, $user['username']);

            $db->query(
                "UPDATE users SET last_login = NOW() WHERE id = :id",
                [':id' => $user['id']]
            );

            // Log successful login
            if (function_exists('log' . ucfirst($user['role']) . 'Login')) {
                $logFunc = 'log' . ucfirst($user['role']) . 'Login';
                $logFunc((string)$user['id'], $username, true);
            }

            return true;
        }

        // Log failed login. If the username matched a real account, route the
        // entry to that user's role-specific log so presales failures don't
        // pollute admin.log. If the username does not exist (or the role has
        // no dedicated logger), fall back to admin.log – admin-side failures
        // are the most security-relevant default.
        $failUserId = $user['id'] ?? 'failed';
        $logFunc    = $user ? ('log' . ucfirst((string)$user['role']) . 'Login') : null;
        if ($logFunc !== null && function_exists($logFunc)) {
            $logFunc((string)$failUserId, $username, false, 'invalid_password');
        } elseif (function_exists('logAdminLogin')) {
            logAdminLogin((string)$failUserId, $username, false, 'invalid_password');
        }

        return false;
    }

    /**
     * Get redirect URL after login based on role
     */
    public static function getLoginRedirectUrl(): string
    {
        $role = self::role();
        if ($role === 'admin') {
            return \App\Helpers\UrlHelper::url('admin/');
        } elseif ($role === 'presales') {
            return \App\Helpers\UrlHelper::url('presales/');
        }
        return \App\Helpers\UrlHelper::url('');
    }

    /**
     * Logout user
     */
    public static function logout(): void
    {
        $userId = self::id();
        $role = self::role();
        $username = self::username();

        Session::remove(self::SESSION_USER_KEY);
        Session::remove(self::SESSION_ROLE_KEY);
        Session::remove(self::SESSION_USERNAME_KEY);
        Session::regenerate();

        // Log logout
        if ($userId && $role) {
            if (function_exists('log' . ucfirst($role) . 'Logout')) {
                $logFunc = 'log' . ucfirst($role) . 'Logout';
                $logFunc((string)$userId, $username ?? 'unknown');
            }
        }
    }

    /**
     * Check if user is logged in
     */
    public static function check(): bool
    {
        return Session::has(self::SESSION_USER_KEY);
    }

    /**
     * Get current user ID
     */
    public static function id(): ?int
    {
        return Session::get(self::SESSION_USER_KEY);
    }

    /**
     * Get current user role
     */
    public static function role(): ?string
    {
        return Session::get(self::SESSION_ROLE_KEY);
    }

    /**
     * Get current username
     */
    public static function username(): ?string
    {
        return Session::get(self::SESSION_USERNAME_KEY);
    }

    /**
     * Check if user has specific role
     */
    public static function hasRole(string $role): bool
    {
        return self::role() === $role;
    }

    /**
     * Check if user is admin
     */
    public static function isAdmin(): bool
    {
        return self::hasRole('admin');
    }

    /**
     * Check if user is presales
     */
    public static function isPresales(): bool
    {
        return self::hasRole('presales');
    }

    /**
     * Require authentication (redirect if not logged in)
     */
    public static function requireAuth(): void
    {
        if (!self::check()) {
            $url = \App\Helpers\UrlHelper::url('login.php');
            header('Location: ' . $url);
            exit;
        }
    }

    /**
     * Require specific role
     */
    public static function requireRole(string $role): void
    {
        self::requireAuth();
        
        if (!self::hasRole($role)) {
            $url = \App\Helpers\UrlHelper::url('');
            header('Location: ' . $url);
            exit;
        }
    }

    /**
     * Require admin role
     */
    public static function requireAdmin(): void
    {
        self::requireRole('admin');
    }

    /**
     * Require presales role (with automatic device token fallback)
     */
    public static function requirePresales(): void
    {
        self::authenticateViaDeviceToken();
        self::requireRole('presales');
    }

    /**
     * Try to authenticate the current request via the presales device token cookie.
     * If the session is already active, this is a no-op.
     * Returns true if the session was populated (either already was or just now via cookie).
     */
    public static function authenticateViaDeviceToken(): bool
    {
        if (self::check()) {
            return true; // Already authenticated via session
        }

        $token = $_COOKIE[self::DEVICE_TOKEN_COOKIE] ?? null;
        if (empty($token)) {
            return false;
        }

        $deviceTokenModel = new DeviceToken();
        $record = $deviceTokenModel->findByToken($token);

        if (!$record || $record['role'] !== 'presales') {
            return false;
        }

        // Populate session as the linked presales user
        Session::regenerate();
        Session::set(self::SESSION_USER_KEY, (int)$record['user_id']);
        Session::set(self::SESSION_ROLE_KEY, $record['role']);
        Session::set(self::SESSION_USERNAME_KEY, $record['username']);
        Session::set('_device_token_id', (int)$record['id']);

        // Track usage
        $deviceTokenModel->updateLastUsed((int)$record['id']);

        // Log as a presales login via device token
        if (function_exists('logPresalesLogin')) {
            logPresalesLogin((string)$record['user_id'], $record['username'], true, 'device_token');
        }

        return true;
    }

    /**
     * Check whether the current session was authenticated via a device token.
     */
    public static function isDeviceTokenSession(): bool
    {
        return Session::has('_device_token_id');
    }
}

