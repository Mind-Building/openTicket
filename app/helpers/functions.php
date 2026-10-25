<?php
/**
 * Global Helper Functions
 */

// Prevent multiple includes
if (defined('HELPER_FUNCTIONS_LOADED')) {
    return;
}
define('HELPER_FUNCTIONS_LOADED', true);

use App\Helpers\UrlHelper;
use App\Services\LoggerService;

if (!function_exists('generateTicketDownloadToken')) {
    /**
     * Generate a secure download token for a set of reservation IDs.
     * Token covers ALL IDs so it cannot be reused for other reservations.
     *
     * @param array $reservationIds Array of reservation IDs
     * @return string SHA-256 token
     */
    function generateTicketDownloadToken(array $reservationIds): string
    {
        $secret = $_ENV['APP_SECRET'] ?? '';
        if (empty($secret)) {
            throw new \RuntimeException('APP_SECRET ist nicht konfiguriert. Bitte .env anpassen.');
        }
        $ids = array_map('intval', $reservationIds);
        sort($ids);
        return hash('sha256', 'ticket:' . implode(',', $ids) . ':' . $secret);
    }
}

if (!function_exists('generateSeatplanViewToken')) {
    /**
     * Generate a secure token for the customer-facing seatplan view.
     * Token is bound to the exact set of reservation IDs so it cannot
     * be reused to view other reservations.
     *
     * @param array $reservationIds Array of reservation IDs
     * @return string SHA-256 token
     */
    function generateSeatplanViewToken(array $reservationIds): string
    {
        $secret = $_ENV['APP_SECRET'] ?? '';
        if (empty($secret)) {
            throw new \RuntimeException('APP_SECRET ist nicht konfiguriert. Bitte .env anpassen.');
        }
        $ids = array_map('intval', $reservationIds);
        sort($ids);
        return hash('sha256', 'seatplan:' . implode(',', $ids) . ':' . $secret);
    }
}

if (!function_exists('generateEmailResendToken')) {
    /**
     * Generate a secure token that allows re-sending the confirmation email
     * for a set of reservation IDs. Bound to the exact ID set so it cannot
     * be reused for other reservations. Needed because the customer session
     * is destroyed on checkout_success.php.
     *
     * @param array $reservationIds Array of reservation IDs
     * @return string SHA-256 token
     */
    function generateEmailResendToken(array $reservationIds): string
    {
        $secret = $_ENV['APP_SECRET'] ?? '';
        if (empty($secret)) {
            throw new \RuntimeException('APP_SECRET ist nicht konfiguriert. Bitte .env anpassen.');
        }
        $ids = array_map('intval', $reservationIds);
        sort($ids);
        return hash('sha256', 'emailresend:' . implode(',', $ids) . ':' . $secret);
    }
}

if (!function_exists('generateVoucherDownloadToken')) {
    /**
     * Generate a secure download token for a set of voucher IDs.
     *
     * @param array $voucherIds Array of voucher IDs
     * @return string SHA-256 token
     */
    function generateVoucherDownloadToken(array $voucherIds): string
    {
        $secret = $_ENV['APP_SECRET'] ?? '';
        if (empty($secret)) {
            throw new \RuntimeException('APP_SECRET ist nicht konfiguriert. Bitte .env anpassen.');
        }
        $ids = array_map('intval', $voucherIds);
        sort($ids);
        return hash('sha256', 'voucher:' . implode(',', $ids) . ':' . $secret);
    }
}

if (!function_exists('logSessionCreated')) {
    function logSessionCreated(string $sessionId, ?string $userId = null): void
    {
        LoggerService::logSessionCreated($sessionId, $userId);
    }
}

if (!function_exists('logSessionDestroyed')) {
    function logSessionDestroyed(string $sessionId, ?string $userId = null, string $reason = 'user_logout'): void
    {
        LoggerService::logSessionDestroyed($sessionId, $userId, $reason);
    }
}

if (!function_exists('logSeatsReserved')) {
    function logSeatsReserved(string $sessionId, int $eventId, string $eventDate, array $seatIdentifiers, ?string $userId = null): void
    {
        LoggerService::logSeatsReserved($sessionId, $eventId, $eventDate, $seatIdentifiers, $userId);
    }
}

if (!function_exists('logSeatsReleased')) {
    function logSeatsReleased(string $sessionId, int $eventId, string $eventDate, array $seatIdentifiers, ?string $userId = null, string $reason = 'timeout'): void
    {
        LoggerService::logSeatsReleased($sessionId, $eventId, $eventDate, $seatIdentifiers, $userId, $reason);
    }
}

if (!function_exists('logSeatsAddedToCart')) {
    function logSeatsAddedToCart(string $sessionId, int $eventId, string $eventDate, array $seatIdentifiers, ?string $userId = null): void
    {
        LoggerService::logSeatsAddedToCart($sessionId, $eventId, $eventDate, $seatIdentifiers, $userId);
    }
}

if (!function_exists('logVoucherApplied')) {
    function logVoucherApplied(string $sessionId, string $voucherCode, float $discount, ?string $userId = null): void
    {
        LoggerService::logVoucherApplied($sessionId, $voucherCode, $discount, $userId);
    }
}

if (!function_exists('logVoucherRedeemAttempt')) {
    function logVoucherRedeemAttempt(string $sessionId, string $voucherCode, ?string $userId = null): void
    {
        LoggerService::logVoucherRedeemAttempt($sessionId, $voucherCode, $userId);
    }
}

if (!function_exists('logVoucherRedeemFailed')) {
    function logVoucherRedeemFailed(string $sessionId, string $voucherCode, string $reason, ?string $userId = null): void
    {
        LoggerService::logVoucherRedeemFailed($sessionId, $voucherCode, $reason, $userId);
    }
}

if (!function_exists('logCartCleared')) {
    function logCartCleared(string $sessionId, int $itemCount, ?string $userId = null, string $reason = 'user_action'): void
    {
        LoggerService::logCartCleared($sessionId, $itemCount, $userId, $reason);
    }
}

if (!function_exists('logPaymentStarted')) {
    function logPaymentStarted(string $sessionId, string $orderId, float $amount, string $currency = 'EUR', ?string $userId = null): void
    {
        LoggerService::logPaymentStarted($sessionId, $orderId, $amount, $currency, $userId);
    }
}

if (!function_exists('logPaymentCancelled')) {
    function logPaymentCancelled(string $sessionId, string $orderId, ?string $userId = null, string $reason = 'user_cancelled'): void
    {
        LoggerService::logPaymentCancelled($sessionId, $orderId, $userId, $reason);
    }
}

if (!function_exists('logPaymentCompleted')) {
    function logPaymentCompleted(string $sessionId, string $orderId, float $amount, string $currency = 'EUR', ?string $userId = null, array $details = []): void
    {
        LoggerService::logPaymentCompleted($sessionId, $orderId, $amount, $currency, $userId, $details);
    }
}

if (!function_exists('getLogPath')) {
    function getLogPath(): string
    {
        return LoggerService::getLogPath();
    }
}

if (!function_exists('logCheckoutSuccessPage')) {
    function logCheckoutSuccessPage(string $sessionId, ?string $userId = null, array $details = []): void
    {
        $context = array_merge($details, [
            'session_id' => $sessionId,
            'user_id' => $userId,
            'ip' => $_SERVER['REMOTE_ADDR'] ?? 'N/A',
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'N/A',
        ]);
        LoggerService::logCustomer('checkout_success_page', $context);
    }
}

if (!function_exists('logEmailSent')) {
    function logEmailSent(string $type, string $emailHash, bool $success, ?string $userId = null, array $details = []): void
    {
        $context = array_merge($details, [
            'type' => $type,
            'email_hash' => $emailHash,
            'success' => $success,
            'user_id' => $userId,
            'ip' => $_SERVER['REMOTE_ADDR'] ?? 'N/A'
        ]);
        
        $level = $success ? 'info' : 'warning';
        LoggerService::logCustomer('email_sent', $context, $level);
    }
}

if (!function_exists('logAdminLogin')) {
    function logAdminLogin(string $adminId, string $username, bool $success, ?string $reason = null): void
    {
        LoggerService::logAdminLogin($adminId, $username, $success, $reason);
    }
}

if (!function_exists('logAdminLogout')) {
    function logAdminLogout(string $adminId, string $username): void
    {
        LoggerService::logAdminLogout($adminId, $username);
    }
}

if (!function_exists('logAdminUserCreate')) {
    function logAdminUserCreate(string $adminId, string $targetUserId, string $role): void
    {
        LoggerService::logAdminUserCreate($adminId, $targetUserId, $role);
    }
}

if (!function_exists('logAdminUserUpdate')) {
    function logAdminUserUpdate(string $adminId, string $targetUserId, array $changes): void
    {
        LoggerService::logAdminUserUpdate($adminId, $targetUserId, $changes);
    }
}

if (!function_exists('logAdminUserDelete')) {
    function logAdminUserDelete(string $adminId, string $targetUserId): void
    {
        LoggerService::logAdminUserDelete($adminId, $targetUserId);
    }
}

if (!function_exists('logAdminEventCreate')) {
    function logAdminEventCreate(string $adminId, int $eventId, string $eventName): void
    {
        LoggerService::logAdminEventCreate($adminId, $eventId, $eventName);
    }
}

if (!function_exists('logAdminEventUpdate')) {
    function logAdminEventUpdate(string $adminId, int $eventId, array $changes): void
    {
        LoggerService::logAdminEventUpdate($adminId, $eventId, $changes);
    }
}

if (!function_exists('logAdminEventDelete')) {
    function logAdminEventDelete(string $adminId, int $eventId, string $eventName): void
    {
        LoggerService::logAdminEventDelete($adminId, $eventId, $eventName);
    }
}

if (!function_exists('logAdminSettingsChange')) {
    function logAdminSettingsChange(string $adminId, string $settingName, $oldValue, $newValue): void
    {
        LoggerService::logAdminSettingsChange($adminId, $settingName, $oldValue, $newValue);
    }
}

if (!function_exists('logAdminAction')) {
    function logAdminAction(string $adminId, string $action, array $context = [], string $level = 'info'): void
    {
        LoggerService::logAdmin($action, array_merge(['admin_id' => $adminId], $context), $level);
    }
}

if (!function_exists('logPresalesLogin')) {
    function logPresalesLogin(string $userId, string $username, bool $success, ?string $reason = null): void
    {
        LoggerService::logPresalesLogin($userId, $username, $success, $reason);
    }
}

if (!function_exists('logPresalesLogout')) {
    function logPresalesLogout(string $userId, string $username): void
    {
        LoggerService::logPresalesLogout($userId, $username);
    }
}

if (!function_exists('logPresalesSale')) {
    function logPresalesSale(string $userId, string $customerName, float $amount, int $ticketCount, string $paymentMethod, array $details = []): void
    {
        LoggerService::logPresalesSale($userId, $customerName, $amount, $ticketCount, $paymentMethod, $details);
    }
}

if (!function_exists('logPresalesRefund')) {
    function logPresalesRefund(string $userId, string $customerName, float $amount, string $reason): void
    {
        LoggerService::logPresalesRefund($userId, $customerName, $amount, $reason);
    }
}

if (!function_exists('logPresalesVoucherCreate')) {
    function logPresalesVoucherCreate(string $userId, string $voucherCode, float $value, ?string $customerName = null, ?string $emailHash = null): void
    {
        LoggerService::logPresalesVoucherCreate($userId, $voucherCode, $value, $customerName, $emailHash);
    }
}

if (!function_exists('logVoucherStatusChange')) {
    function logVoucherStatusChange(string $channel, string $voucherCode, ?int $oldStatusId, ?int $newStatusId, string $trigger, array $extra = []): void
    {
        LoggerService::logVoucherStatusChange($channel, $voucherCode, $oldStatusId, $newStatusId, $trigger, $extra);
    }
}

if (!function_exists('logPresalesEmailSent')) {
    function logPresalesEmailSent(string $userId, string $type, string $emailHash, bool $success, array $details = []): void
    {
        LoggerService::logPresalesEmailSent($userId, $type, $emailHash, $success, $details);
    }
}

if (!function_exists('url')) {
    /**
     * Generate URL with base path
     */
    function url(string $path = ''): string
    {
        return UrlHelper::url($path);
    }
}

if (!function_exists('isAdminEventPreview')) {
    /**
     * Admin preview of the customer booking flow: a logged-in admin may open an event that is
     * not active (yet), e.g. to test a purchase before publishing it. Customers never can.
     */
    function isAdminEventPreview(?array $event): bool
    {
        return $event !== null && empty($event['active']) && \App\Core\Auth::isAdmin();
    }
}

if (!function_exists('adminEventPreviewBanner')) {
    /**
     * Notice shown on customer pages while an admin previews an inactive event.
     */
    function adminEventPreviewBanner(?array $event): string
    {
        if (!isAdminEventPreview($event)) {
            return '';
        }
        return '<div class="alert alert-warning d-flex align-items-center gap-2" role="alert">'
            . '<i class="bi bi-eye"></i><div><strong>Admin-Vorschau:</strong> „' . htmlspecialchars($event['name'] ?? '')
            . '“ ist nicht aktiv und für Kunden nicht sichtbar. Buchungen in dieser Vorschau sind echte Bestellungen '
            . '(mit PayPal-Sandbox testen) und sollten danach im Admin gelöscht werden.</div></div>';
    }
}

if (!function_exists('seatplanElementTypes')) {
    /**
     * Element types occurring in a seat plan (seat, wheelchair, table, distance, ... and "stairs"),
     * so legends only list what the hall actually has.
     *
     * @return array<string, true>
     */
    function seatplanElementTypes(array $seatPlan): array
    {
        $types = [];
        foreach ($seatPlan as $row) {
            foreach ($row['content'] ?? [] as $element) {
                $types[$element['type'] ?? ''] = true;
                if (!empty($element['stairs'])) {
                    $types['stairs'] = true;
                }
            }
        }
        return $types;
    }
}

if (!function_exists('seatplanStairsAttr')) {
    /**
     * Extra attributes for a distance element that is marked as stairs: white with a black
     * border in every seat plan view. Inline !important so it wins over the views' grey
     * distance styling. Empty string for a normal distance.
     */
    function seatplanStairsAttr(array $element): string
    {
        if (empty($element['stairs'])) {
            return '';
        }
        return ' title="Treppe" data-stairs="1" style="background:#fff !important;border:2px solid #000 !important;color:#000 !important;"';
    }
}

if (!function_exists('assetUrl')) {
    /**
     * URL of a file below public/ with its modification time as version (?v=...),
     * so browsers and proxy caches load a changed CSS/JS file right after a deploy.
     */
    function assetUrl(string $path): string
    {
        $file = __DIR__ . '/../../public/' . ltrim($path, '/');
        $version = is_file($file) ? filemtime($file) : null;
        return UrlHelper::url($path) . ($version ? '?v=' . $version : '');
    }
}

if (!function_exists('absoluteUrl')) {
    /**
     * Generate absolute URL (with protocol and host)
     */
    function absoluteUrl(string $path = ''): string
    {
        return UrlHelper::absoluteUrl($path);
    }
}

if (!function_exists('isEventDateBookable')) {
    /**
     * Check if an event date is still bookable
     * @param string $eventDate Date in format Y-m-d
     * @param string|null $maxReservationTime Time in format H:i:s (e.g., '12:00:00') - booking deadline on event day
     * @return array ['bookable' => bool, 'reason' => string|null]
     */
    function isEventDateBookable(string $eventDate, ?string $maxReservationTime = '12:00:00'): array
    {
        $today = date('Y-m-d');
        $eventDateTime = new \DateTime($eventDate);
        $todayDateTime = new \DateTime($today);
        
        // Check if event date is in the past
        if ($eventDateTime < $todayDateTime) {
            return [
                'bookable' => false,
                'reason' => 'Das Veranstaltungsdatum liegt in der Vergangenheit.'
            ];
        }
        
        // If event is today, check if booking deadline has passed
        if ($eventDate === $today && $maxReservationTime) {
            $now = new \DateTime();
            $deadline = new \DateTime($today . ' ' . $maxReservationTime);
            
            if ($now >= $deadline) {
                return [
                    'bookable' => false,
                    'reason' => 'Die Buchungsfrist für heute ist bereits abgelaufen (bis ' . date('H:i', strtotime($maxReservationTime)) . ' Uhr).'
                ];
            }
        }
        
        return [
            'bookable' => true,
            'reason' => null
        ];
    }
}

if (!function_exists('reservationStatusLabel')) {
    /**
     * Single source of truth for reservation status labels (plain text).
     * IDs match `reservation_statuses` table in database/schema.sql.
     */
    function reservationStatusLabel(int $statusId): string
    {
        return match ($statusId) {
            0  => 'Nicht eingelöst',
            1  => 'Im Warenkorb',
            2  => 'Bezahlt (PayPal)',
            3  => 'Reserviert (Vorverkauf)',
            4  => 'Bezahlt & Abgeholt',
            5  => 'Barzahlung',
            6  => 'Reserviert (Online)',
            7  => 'Gedruckt (Abendkasse)',
            8  => 'Bezahlt (Gutschein)',
            9  => 'Gutschein eingelöst',
            10 => 'FN-Karte',
            11 => 'VIP',
            20 => 'PayPal gestartet',
            91 => 'FEHLER: PayPal ohne Kundendaten',
            default => 'Unbekannt (' . $statusId . ')',
        };
    }
}

if (!function_exists('reservationStatusBadge')) {
    /**
     * Single source of truth for reservation status badges (HTML).
     * Use this everywhere a status is rendered so labels and colors stay consistent.
     */
    function reservationStatusBadge(int $statusId): string
    {
        $label = htmlspecialchars(reservationStatusLabel($statusId));
        if ($statusId === 11) {
            // VIP – purple badge with inline color to match brand styling
            return '<span class="badge" style="background-color:#6f42c1;color:#fff;">' . $label . '</span>';
        }
        $class = match ($statusId) {
            2, 5, 8, 9            => 'bg-success',
            3, 6, 20              => 'bg-warning text-dark',
            4, 10                 => 'bg-info text-dark',
            91                    => 'bg-danger',
            default               => 'bg-secondary',
        };
        return '<span class="badge ' . $class . '">' . $label . '</span>';
    }
}

if (!function_exists('reservationStatusBadgeMapJson')) {
    /**
     * Returns a JSON object mapping status_id → badge HTML.
     * Inject into inline JS so the client uses the same labels as PHP.
     */
    function reservationStatusBadgeMapJson(): string
    {
        $ids = [0, 1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 20, 91];
        $map = [];
        foreach ($ids as $id) {
            $map[$id] = reservationStatusBadge($id);
        }
        return json_encode($map, JSON_UNESCAPED_UNICODE);
    }
}

if (!function_exists('reservationStatusOptionsJson')) {
    /**
     * Returns a JSON array of [statusId, plain-text label] pairs for use in <select> options.
     * Excludes status 0 (not_redeemed) which is internal-only.
     */
    function reservationStatusOptionsJson(): string
    {
        $ids = [1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 20, 91];
        $out = [];
        foreach ($ids as $id) {
            $out[] = [$id, reservationStatusLabel($id)];
        }
        return json_encode($out, JSON_UNESCAPED_UNICODE);
    }
}

if (!function_exists('ensurePublicInUrl')) {
    /**
     * Normalize base URL: removes a trailing /public segment if present.
     * The domain root now maps directly to /public/, so /public must not
     * appear in outgoing URLs (e.g. in email links).
     *
     * @param string $url The URL to normalize
     * @return string URL without trailing /public segment
     */
    function ensurePublicInUrl(string $url): string
    {
        // Remove trailing slash
        $url = rtrim($url, '/');

        // Strip a trailing /public path segment (case-insensitive)
        $url = preg_replace('#/public$#i', '', $url);

        return $url;
    }
}

