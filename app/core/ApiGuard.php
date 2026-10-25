<?php
/**
 * API Guard
 *
 * Rate-Limit + Bot-Heuristik für öffentliche JSON-Endpoints
 * unter public/api/.
 *
 * Nutzung am Anfang eines API-Files (nach require bootstrap):
 *
 *     ApiGuard::publicApi('cart', 90, 60);
 *
 * Bei Überschreitung: HTTP 429 + JSON-Fehler + exit.
 * Bei leerem User-Agent: HTTP 403 + JSON-Fehler + exit.
 *
 * Blockierte Requests werden in customer.log protokolliert
 * (Action: api_rate_limit_hit / api_rate_limit_blocked / api_bot_blocked).
 */

namespace App\Core;

use App\Services\LoggerService;

class ApiGuard
{
    public static function publicApi(string $category, int $maxAttempts = 60, int $window = 60): void
    {
        // Leerer User-Agent auf einem JSON-Endpoint ist praktisch immer
        // ein Script (curl ohne -A, simple bots). Echte Browser senden
        // immer einen UA — auch firmeninterne Proxies setzen ihn nicht
        // auf leer, sondern hängen höchstens etwas an.
        $ua = trim($_SERVER['HTTP_USER_AGENT'] ?? '');
        if ($ua === '') {
            self::logBlock(LoggerService::ACTION_API_BOT_BLOCKED, $category, [
                'reason' => 'empty_user_agent',
            ], 'warning');
            self::reject(403, 'Forbidden');
        }

        $ip = RateLimiter::getClientIp();
        $key = 'api:' . $category . ':' . $ip;
        $result = RateLimiter::check($key, $maxAttempts, $window);

        if (!$result['allowed']) {
            $retryAfter = max(1, (int)(($result['reset_at'] ?? (time() + $window)) - time()));
            if (!headers_sent()) {
                header('Retry-After: ' . $retryAfter);
            }

            // "blocked" = 1-Stunden-Sperre (Limit innerhalb des Fensters
            // bereits überschritten). Sonst nur normaler Window-Treffer.
            $action = !empty($result['blocked'])
                ? LoggerService::ACTION_API_RATE_LIMIT_BLOCKED
                : LoggerService::ACTION_API_RATE_LIMIT_HIT;
            $level = !empty($result['blocked']) ? 'error' : 'warning';

            self::logBlock($action, $category, [
                'max_attempts' => $maxAttempts,
                'window_seconds' => $window,
                'retry_after_seconds' => $retryAfter,
            ], $level);

            self::reject(429, 'Zu viele Anfragen. Bitte versuchen Sie es in Kürze erneut.');
        }

        RateLimiter::record($key);
    }

    private static function logBlock(string $action, string $category, array $extra, string $level): void
    {
        try {
            $context = array_merge([
                'category' => $category,
                'endpoint' => basename($_SERVER['SCRIPT_NAME'] ?? 'unknown'),
                'method' => $_SERVER['REQUEST_METHOD'] ?? 'GET',
            ], $extra);
            // session_id() + IP + UA werden im LoggerService automatisch ergänzt.
            LoggerService::logCustomer($action, $context, $level);
        } catch (\Throwable $e) {
            // Logging darf den Block-Pfad nie aufhalten.
            error_log('ApiGuard logging failed: ' . $e->getMessage());
        }
    }

    private static function reject(int $code, string $message): void
    {
        if (!headers_sent()) {
            http_response_code($code);
            header('Content-Type: application/json; charset=utf-8');
        }
        echo json_encode(['success' => false, 'error' => $message]);
        exit;
    }
}
