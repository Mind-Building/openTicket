<?php
/**
 * URL Helper
 * Generates URLs with correct base path
 */

namespace App\Helpers;

class UrlHelper
{
    private static ?string $basePath = null;

    /**
     * Get base path of the application
     * Returns the path to the public directory
     */
    public static function getBasePath(): string
    {
        if (self::$basePath === null) {
            // Allow explicit override via environment variable (useful for shared hosting)
            if (isset($_ENV['APP_BASE_PATH'])) {
                $scriptDir = $_ENV['APP_BASE_PATH'];
            } else {
                // Get absolute path to public directory
                $publicFolder = str_replace('\\', '/', realpath(__DIR__ . '/../../public'));
                // Get document root
                $docRoot = str_replace('\\', '/', $_SERVER['DOCUMENT_ROOT'] ?? '');

                // If public is within document root, find the relative path
                if (!empty($docRoot) && stripos($publicFolder, $docRoot) === 0) {
                    $scriptDir = substr($publicFolder, strlen($docRoot));
                } else {
                    // Fallback to old logic if docRoot detection fails
                    $scriptName = $_SERVER['SCRIPT_NAME'] ?? '';
                    $publicPos = stripos($scriptName, '/public');
                    $scriptDir = ($publicPos !== false) ? substr($scriptName, 0, $publicPos + 7) : '';
                }
            }
            
            // Cleanup: ensure it starts with / and has no trailing slash
            $scriptDir = str_replace('\\', '/', $scriptDir);
            $scriptDir = '/' . ltrim($scriptDir, '/');
            self::$basePath = rtrim($scriptDir, '/');
        }
        
        return self::$basePath;
    }

    /**
     * Generate URL with base path
     */
    public static function url(string $path = ''): string
    {
        $path = ltrim($path, '/');
        $base = self::getBasePath();
        
        // Always return absolute path starting with /
        if (empty($path)) {
            return $base ?: '/';
        }
        
        return ($base ?: '') . '/' . $path;
    }

    /**
     * Generate absolute URL.
     *
     * Resolution order:
     *   1. ticket_url_start from DB config (admin-managed, single source of truth)
     *   2. Reconstruction from $_SERVER, respecting reverse-proxy headers
     *
     * Preferring the configured base URL eliminates Host Header Injection –
     * an attacker can no longer poison email links by sending a fake Host: header.
     */
    public static function absoluteUrl(string $path = ''): string
    {
        $base = self::getAbsoluteBaseUrl();
        $path = ltrim($path, '/');
        return $path === '' ? $base : $base . '/' . $path;
    }

    /**
     * Resolve the absolute base URL (scheme + host + base path, no trailing slash).
     */
    private static function getAbsoluteBaseUrl(): string
    {
        // Preferred: the admin-configured ticket_url_start. This is the same
        // value used by EmailService for ticket/voucher links and matches the
        // deployment's canonical hostname and scheme.
        try {
            if (class_exists(\App\Core\Config::class)) {
                $configured = (string)\App\Core\Config::get('ticket_url_start', '');
                if ($configured !== '') {
                    return rtrim($configured, '/');
                }
            }
        } catch (\Throwable $e) {
            // DB unavailable (e.g. during a connection-error page) – fall through.
        }

        // Fallback: reconstruct from the current request.
        return self::detectScheme() . '://'
            . self::detectHost()
            . self::getBasePath();
    }

    /**
     * Detect the request scheme, honouring common reverse-proxy headers
     * (Cloudflare, nginx, AWS ALB, …). PHP itself only sees HTTP when the
     * proxy terminates TLS, so we need these hints to build correct https links.
     */
    private static function detectScheme(): string
    {
        if (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off') {
            return 'https';
        }
        $forwardedProto = $_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '';
        if (strtolower((string)$forwardedProto) === 'https') {
            return 'https';
        }
        $forwardedSsl = $_SERVER['HTTP_X_FORWARDED_SSL'] ?? '';
        if (strtolower((string)$forwardedSsl) === 'on') {
            return 'https';
        }
        return 'http';
    }

    private static function detectHost(): string
    {
        return $_SERVER['HTTP_HOST'] ?? ($_SERVER['SERVER_NAME'] ?? 'localhost');
    }
}

