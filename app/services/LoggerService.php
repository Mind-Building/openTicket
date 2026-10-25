<?php
/**
 * Logger Service
 * Handles all application logging with Monolog
 */

namespace App\Services;

use Monolog\Logger;
use Monolog\Handler\StreamHandler;
use Monolog\Handler\RotatingFileHandler;
use Monolog\Formatter\LineFormatter;

class LoggerService
{
    private static ?Logger $customerLogger = null;
    private static ?Logger $adminLogger = null;
    private static ?Logger $presalesLogger = null;
    private static string $logPath = __DIR__ . '/../../logging';

    const ACTION_SESSION_CREATED = 'session_created';
    const ACTION_SESSION_DESTROYED = 'session_destroyed';
    const ACTION_SEATS_RESERVED = 'seats_reserved';
    const ACTION_SEATS_RELEASED = 'seats_released';
    const ACTION_SEATS_ADDED_TO_CART = 'seats_added_to_cart';
    const ACTION_VOUCHER_APPLIED = 'voucher_applied';
    const ACTION_VOUCHER_REDEEM_ATTEMPT = 'voucher_redeem_attempt';
    const ACTION_VOUCHER_REDEEM_FAILED = 'voucher_redeem_failed';
    const ACTION_CART_CLEARED = 'cart_cleared';
    const ACTION_PAYMENT_STARTED = 'payment_started';
    const ACTION_PAYMENT_CANCELLED = 'payment_cancelled';
    const ACTION_PAYMENT_COMPLETED = 'payment_completed';
    const ACTION_EMAIL_SENT = 'email_sent';

    // API guard / Rate-Limit / Bot-Erkennung
    const ACTION_API_RATE_LIMIT_HIT = 'api_rate_limit_hit';
    const ACTION_API_RATE_LIMIT_BLOCKED = 'api_rate_limit_blocked';
    const ACTION_API_BOT_BLOCKED = 'api_bot_blocked';

    // Admin actions
    const ACTION_ADMIN_LOGIN = 'admin_login';
    const ACTION_ADMIN_LOGOUT = 'admin_logout';
    const ACTION_ADMIN_USER_CREATE = 'admin_user_create';
    const ACTION_ADMIN_USER_UPDATE = 'admin_user_update';
    const ACTION_ADMIN_USER_DELETE = 'admin_user_delete';
    const ACTION_ADMIN_EVENT_CREATE = 'admin_event_create';
    const ACTION_ADMIN_EVENT_UPDATE = 'admin_event_update';
    const ACTION_ADMIN_EVENT_DELETE = 'admin_event_delete';
    const ACTION_ADMIN_SETTINGS_CHANGE = 'admin_settings_change';
    const ACTION_ADMIN_RESERVATION_STATUS_CHANGE = 'admin_reservation_status_change';

    // Presales actions
    const ACTION_PRESALES_LOGIN = 'presales_login';
    const ACTION_PRESALES_LOGOUT = 'presales_logout';
    const ACTION_PRESALES_SALE = 'presales_sale';
    const ACTION_PRESALES_REFUND = 'presales_refund';
    const ACTION_PRESALES_VOUCHER_CREATE = 'presales_voucher_create';
    const ACTION_VOUCHER_STATUS_CHANGE = 'voucher_status_change';
    const ACTION_RESERVATION_REBOOKED = 'reservation_rebooked';

    private static function ensureLogDirectory(): void
    {
        if (!is_dir(self::$logPath)) {
            @mkdir(self::$logPath, 0755, true);
        }
    }

    private static function createLogger(string $name, string $filename): Logger
    {
        self::ensureLogDirectory();

        $logger = new Logger($name);

        $dateFormat = 'Y-m-d H:i:s';
        $output = '[%datetime%] %channel%.%level_name%: %message% %context% %extra%' . PHP_EOL;
        $formatter = new LineFormatter($output, $dateFormat);

        $logFile = self::$logPath . '/' . $filename;
        
        try {
            $handler = new RotatingFileHandler($logFile, 180, Logger::DEBUG);
            $handler->setFormatter($formatter);
            $logger->pushHandler($handler);
        } catch (\Exception $e) {
            error_log("LoggerService: Failed to create handler for $logFile: " . $e->getMessage());
        }

        if (php_sapi_name() === 'cli') {
            $consoleHandler = new StreamHandler('php://stdout', Logger::DEBUG);
            $consoleHandler->setFormatter($formatter);
            $logger->pushHandler($consoleHandler);
        }

        return $logger;
    }

    public static function getCustomerLogger(): Logger
    {
        if (self::$customerLogger === null) {
            self::$customerLogger = self::createLogger('customer', 'customer.log');
        }
        return self::$customerLogger;
    }

    public static function getAdminLogger(): Logger
    {
        if (self::$adminLogger === null) {
            self::$adminLogger = self::createLogger('admin', 'admin.log');
        }
        return self::$adminLogger;
    }

    public static function getPresalesLogger(): Logger
    {
        if (self::$presalesLogger === null) {
            self::$presalesLogger = self::createLogger('presales', 'presales.log');
        }
        return self::$presalesLogger;
    }

    private static function logToLogger(Logger $logger, string $action, array $context, string $level): void
    {
        $context['ip'] = $_SERVER['REMOTE_ADDR'] ?? 'N/A';
        $context['user_agent'] = $_SERVER['HTTP_USER_AGENT'] ?? 'N/A';

        // Append the current session id to every entry so log lines can be
        // correlated across actions. Don't overwrite an explicitly passed
        // session_id, and skip it on CLI/cron where no session exists.
        if (!array_key_exists('session_id', $context)) {
            $sid = function_exists('session_id') ? session_id() : '';
            if (is_string($sid) && $sid !== '') {
                $context['session_id'] = substr($sid, 0, 32);
            }
        }

        $message = "Action: {$action}";

        switch ($level) {
            case 'debug': $logger->debug($message, $context); break;
            case 'info': $logger->info($message, $context); break;
            case 'warning': $logger->warning($message, $context); break;
            case 'error': $logger->error($message, $context); break;
            default: $logger->info($message, $context);
        }
    }

    public static function logCustomer(string $action, array $context = [], string $level = 'info'): void
    {
        self::logToLogger(self::getCustomerLogger(), $action, $context, $level);
    }

    public static function logAdmin(string $action, array $context = [], string $level = 'info'): void
    {
        self::logToLogger(self::getAdminLogger(), $action, $context, $level);
    }

    public static function logPresales(string $action, array $context = [], string $level = 'info'): void
    {
        self::logToLogger(self::getPresalesLogger(), $action, $context, $level);
    }

    // Customer logging methods
    public static function logSessionCreated(string $sessionId, ?string $userId = null): void
    {
        self::logCustomer(self::ACTION_SESSION_CREATED, [
            'session_id' => substr($sessionId, 0, 32),
            'user_id' => $userId
        ]);
    }

    public static function logSessionDestroyed(string $sessionId, ?string $userId = null, string $reason = 'user_logout'): void
    {
        self::logCustomer(self::ACTION_SESSION_DESTROYED, [
            'session_id' => substr($sessionId, 0, 32),
            'user_id' => $userId,
            'reason' => $reason
        ]);
    }

    public static function logSeatsReserved(string $sessionId, int $eventId, string $eventDate, array $seatIdentifiers, ?string $userId = null): void
    {
        self::logCustomer(self::ACTION_SEATS_RESERVED, [
            'session_id' => substr($sessionId, 0, 32),
            'user_id' => $userId,
            'event_id' => $eventId,
            'event_date' => $eventDate,
            'seats' => $seatIdentifiers,
            'seat_count' => count($seatIdentifiers)
        ]);
    }

    public static function logSeatsReleased(string $sessionId, int $eventId, string $eventDate, array $seatIdentifiers, ?string $userId = null, string $reason = 'timeout'): void
    {
        self::logCustomer(self::ACTION_SEATS_RELEASED, [
            'session_id' => substr($sessionId, 0, 32),
            'user_id' => $userId,
            'event_id' => $eventId,
            'event_date' => $eventDate,
            'seats' => $seatIdentifiers,
            'seat_count' => count($seatIdentifiers),
            'reason' => $reason
        ]);
    }

    public static function logSeatsAddedToCart(string $sessionId, int $eventId, string $eventDate, array $seatIdentifiers, ?string $userId = null): void
    {
        self::logCustomer(self::ACTION_SEATS_ADDED_TO_CART, [
            'session_id' => substr($sessionId, 0, 32),
            'user_id' => $userId,
            'event_id' => $eventId,
            'event_date' => $eventDate,
            'seats' => $seatIdentifiers,
            'seat_count' => count($seatIdentifiers)
        ]);
    }

    public static function logVoucherApplied(string $sessionId, string $voucherCode, float $discount, ?string $userId = null): void
    {
        self::logCustomer(self::ACTION_VOUCHER_APPLIED, [
            'session_id' => substr($sessionId, 0, 32),
            'user_id' => $userId,
            'voucher_code' => $voucherCode,
            'discount' => $discount
        ]);
    }

    public static function logVoucherRedeemAttempt(string $sessionId, string $voucherCode, ?string $userId = null): void
    {
        self::logCustomer(self::ACTION_VOUCHER_REDEEM_ATTEMPT, [
            'session_id' => substr($sessionId, 0, 32),
            'user_id' => $userId,
            'voucher_code' => $voucherCode,
        ]);
    }

    public static function logVoucherRedeemFailed(string $sessionId, string $voucherCode, string $reason, ?string $userId = null): void
    {
        self::logCustomer(self::ACTION_VOUCHER_REDEEM_FAILED, [
            'session_id' => substr($sessionId, 0, 32),
            'user_id' => $userId,
            'voucher_code' => $voucherCode,
            'reason' => $reason,
        ], 'warning');
    }

    public static function logCartCleared(string $sessionId, int $itemCount, ?string $userId = null, string $reason = 'user_action'): void
    {
        self::logCustomer(self::ACTION_CART_CLEARED, [
            'session_id' => substr($sessionId, 0, 32),
            'user_id' => $userId,
            'item_count' => $itemCount,
            'reason' => $reason
        ]);
    }

    public static function logPaymentStarted(string $sessionId, string $orderId, float $amount, string $currency = 'EUR', ?string $userId = null): void
    {
        self::logCustomer(self::ACTION_PAYMENT_STARTED, [
            'session_id' => substr($sessionId, 0, 32),
            'user_id' => $userId,
            'order_id' => $orderId,
            'amount' => $amount,
            'currency' => $currency
        ]);
    }

    public static function logPaymentCancelled(string $sessionId, string $orderId, ?string $userId = null, string $reason = 'user_cancelled'): void
    {
        self::logCustomer(self::ACTION_PAYMENT_CANCELLED, [
            'session_id' => substr($sessionId, 0, 32),
            'user_id' => $userId,
            'order_id' => $orderId,
            'reason' => $reason
        ]);
    }

    public static function logPaymentCompleted(string $sessionId, string $orderId, float $amount, string $currency = 'EUR', ?string $userId = null, array $details = []): void
    {
        self::logCustomer(self::ACTION_PAYMENT_COMPLETED, [
            'session_id' => substr($sessionId, 0, 32),
            'user_id' => $userId,
            'order_id' => $orderId,
            'amount' => $amount,
            'currency' => $currency
        ] + $details);
    }

    public static function logEmailSent(string $type, string $emailHash, bool $success, ?string $userId = null, array $details = []): void
    {
        self::logCustomer(self::ACTION_EMAIL_SENT, [
            'type' => $type,
            'email_hash' => $emailHash,
            'success' => $success,
            'user_id' => $userId
        ] + $details, $success ? 'info' : 'warning');
    }

    // Admin logging methods
    public static function logAdminLogin(string $adminId, string $username, bool $success, ?string $reason = null): void
    {
        self::logAdmin(self::ACTION_ADMIN_LOGIN, [
            'admin_id' => $adminId,
            'username' => $username,
            'success' => $success,
            'reason' => $reason
        ], $success ? 'info' : 'warning');
    }

    public static function logAdminLogout(string $adminId, string $username): void
    {
        self::logAdmin(self::ACTION_ADMIN_LOGOUT, [
            'admin_id' => $adminId,
            'username' => $username
        ]);
    }

    public static function logAdminUserCreate(string $adminId, string $targetUserId, string $role): void
    {
        self::logAdmin(self::ACTION_ADMIN_USER_CREATE, [
            'admin_id' => $adminId,
            'target_user_id' => $targetUserId,
            'role' => $role
        ]);
    }

    public static function logAdminUserUpdate(string $adminId, string $targetUserId, array $changes): void
    {
        self::logAdmin(self::ACTION_ADMIN_USER_UPDATE, [
            'admin_id' => $adminId,
            'target_user_id' => $targetUserId,
            'changes' => $changes
        ]);
    }

    public static function logAdminUserDelete(string $adminId, string $targetUserId): void
    {
        self::logAdmin(self::ACTION_ADMIN_USER_DELETE, [
            'admin_id' => $adminId,
            'target_user_id' => $targetUserId
        ]);
    }

    public static function logAdminEventCreate(string $adminId, int $eventId, string $eventName): void
    {
        self::logAdmin(self::ACTION_ADMIN_EVENT_CREATE, [
            'admin_id' => $adminId,
            'event_id' => $eventId,
            'event_name' => $eventName
        ]);
    }

    public static function logAdminEventUpdate(string $adminId, int $eventId, array $changes): void
    {
        self::logAdmin(self::ACTION_ADMIN_EVENT_UPDATE, [
            'admin_id' => $adminId,
            'event_id' => $eventId,
            'changes' => $changes
        ]);
    }

    public static function logAdminEventDelete(string $adminId, int $eventId, string $eventName): void
    {
        self::logAdmin(self::ACTION_ADMIN_EVENT_DELETE, [
            'admin_id' => $adminId,
            'event_id' => $eventId,
            'event_name' => $eventName
        ]);
    }

    public static function logAdminReservationStatusChange(string $adminId, string $username, int $reservationId, int $oldStatusId, int $newStatusId): void
    {
        self::logAdmin(self::ACTION_ADMIN_RESERVATION_STATUS_CHANGE, [
            'admin_id'       => $adminId,
            'username'       => $username,
            'reservation_id' => $reservationId,
            'old_status_id'  => $oldStatusId,
            'new_status_id'  => $newStatusId,
        ]);
    }

    public static function logAdminSettingsChange(string $adminId, string $settingName, $oldValue, $newValue): void
    {
        self::logAdmin(self::ACTION_ADMIN_SETTINGS_CHANGE, [
            'admin_id' => $adminId,
            'setting_name' => $settingName,
            'old_value' => is_array($oldValue) ? '[redacted]' : $oldValue,
            'new_value' => is_array($newValue) ? '[redacted]' : $newValue
        ]);
    }

    // Presales logging methods
    public static function logPresalesLogin(string $userId, string $username, bool $success, ?string $reason = null): void
    {
        self::logPresales(self::ACTION_PRESALES_LOGIN, [
            'user_id' => $userId,
            'username' => $username,
            'success' => $success,
            'reason' => $reason
        ], $success ? 'info' : 'warning');
    }

    public static function logPresalesLogout(string $userId, string $username): void
    {
        self::logPresales(self::ACTION_PRESALES_LOGOUT, [
            'user_id' => $userId,
            'username' => $username
        ]);
    }

    public static function logPresalesSale(string $userId, string $customerName, float $amount, int $ticketCount, string $paymentMethod, array $details = []): void
    {
        self::logPresales(self::ACTION_PRESALES_SALE, [
            'user_id' => $userId,
            'customer_name' => $customerName,
            'amount' => $amount,
            'ticket_count' => $ticketCount,
            'payment_method' => $paymentMethod
        ] + $details);
    }

    public static function logPresalesRefund(string $userId, string $customerName, float $amount, string $reason): void
    {
        self::logPresales(self::ACTION_PRESALES_REFUND, [
            'user_id' => $userId,
            'customer_name' => $customerName,
            'amount' => $amount,
            'reason' => $reason
        ], 'warning');
    }

    public static function logPresalesVoucherCreate(string $userId, string $voucherCode, float $value, ?string $customerName = null, ?string $emailHash = null): void
    {
        $context = [
            'user_id' => $userId,
            'voucher_code' => $voucherCode,
            'value' => $value,
        ];
        if ($customerName !== null) {
            $context['customer_name'] = $customerName;
        }
        if ($emailHash !== null) {
            $context['email_hash'] = $emailHash;
        }
        self::logPresales(self::ACTION_PRESALES_VOUCHER_CREATE, $context);
    }

    public static function logPresalesEmailSent(string $userId, string $type, string $emailHash, bool $success, array $details = []): void
    {
        self::logPresales('presales_email_sent', array_merge($details, [
            'user_id'    => $userId,
            'email_type' => $type,
            'email_hash' => $emailHash,
            'success'    => $success,
        ]), $success ? 'info' : 'warning');
    }

    public static function logPresalesBoxOffice(string $userId, string $username, int $eventId, string $eventDate, array $reservationIds, int $count): void
    {
        self::logPresales('presales_box_office', [
            'user_id'         => $userId,
            'username'        => $username,
            'event_id'        => $eventId,
            'event_date'      => $eventDate,
            'reservation_ids' => $reservationIds,
            'count'           => $count,
            'old_status_ids'  => [3, 6],
            'new_status_id'   => 7,
        ]);
    }

    public static function logAdminBoxOffice(string $userId, string $username, int $eventId, string $eventDate, array $reservationIds, int $count): void
    {
        self::logAdmin('admin_box_office', [
            'user_id'         => $userId,
            'username'        => $username,
            'event_id'        => $eventId,
            'event_date'      => $eventDate,
            'reservation_ids' => $reservationIds,
            'count'           => $count,
            'old_status_ids'  => [3, 6],
            'new_status_id'   => 7,
        ]);
    }

    /**
     * Log a ticket PDF download / (re-)print for any role.
     * Routes to admin, presales or customer log depending on caller.
     */
    public static function logTicketDownload(string $role, ?string $userId, ?string $username, array $reservationIds): void
    {
        $context = [
            'user_id'         => $userId,
            'username'        => $username,
            'reservation_ids' => $reservationIds,
            'count'           => count($reservationIds),
            'ip'              => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
        ];
        match ($role) {
            'admin'    => self::logAdmin('ticket_download', $context),
            'presales' => self::logPresales('ticket_download', $context),
            default    => self::logCustomer('ticket_download', $context),
        };
    }

    public static function logPresalesMarkPaid(string $userId, string $username, array $reservationIds, int $count): void
    {
        self::logPresales('presales_mark_paid', [
            'user_id'         => $userId,
            'username'        => $username,
            'reservation_ids' => $reservationIds,
            'count'           => $count,
            'old_status_id'   => 3,
            'new_status_id'   => 4,
        ]);
    }

    /**
     * Log a voucher status change. Writes to the appropriate channel (admin, presales, or customer).
     */
    public static function logVoucherStatusChange(
        string $channel,
        string $voucherCode,
        ?int $oldStatusId,
        ?int $newStatusId,
        string $trigger,
        array $extra = []
    ): void {
        $statusLabels = [0 => 'presales', 1 => 'in_cart', 2 => 'paid', 3 => 'redeemed'];
        $context = array_merge([
            'voucher_code' => $voucherCode,
            'old_status'   => $oldStatusId !== null ? ($statusLabels[$oldStatusId] ?? (string)$oldStatusId) : null,
            'new_status'   => $newStatusId !== null ? ($statusLabels[$newStatusId] ?? (string)$newStatusId) : 'deleted',
            'trigger'      => $trigger,
        ], $extra);

        match ($channel) {
            'admin'    => self::logAdmin(self::ACTION_VOUCHER_STATUS_CHANGE, $context),
            'presales' => self::logPresales(self::ACTION_VOUCHER_STATUS_CHANGE, $context),
            default    => self::logCustomer(self::ACTION_VOUCHER_STATUS_CHANGE, $context),
        };
    }

    /**
     * Log a reservation rebooking (seat/date change).
     */
    public static function logReservationRebooked(
        string $channel,
        string $userId,
        string $username,
        int $eventId,
        array $rebookings
    ): void {
        $context = [
            'event_id'   => $eventId,
            'user_id'    => $userId,
            'username'   => $username,
            'rebookings' => $rebookings,
        ];

        match ($channel) {
            'admin'    => self::logAdmin(self::ACTION_RESERVATION_REBOOKED, $context),
            'presales' => self::logPresales(self::ACTION_RESERVATION_REBOOKED, $context),
            default    => self::logAdmin(self::ACTION_RESERVATION_REBOOKED, $context),
        };
    }

    public static function getLogPath(): string
    {
        return self::$logPath;
    }
}
