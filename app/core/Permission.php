<?php
/**
 * Feature permissions for the sales roles.
 *
 * Admin and presales may do everything (unchanged behaviour). The role "partner"
 * (Externe Partner) only gets what is enabled on two levels:
 *   1. the installation: settings partner_role_active + partner_features,
 *   2. the partner user: users.partner_permissions {"features": [...], "event_ids": [...]}
 *      – may only narrow the installation's features down, never widen them.
 * The user level is loaded once per request (not from the session), so changes made
 * by an admin take effect immediately.
 */

namespace App\Core;

class Permission
{
    public const SELL_CASH      = 'sell_cash';
    public const SELL_CUSTOMER  = 'sell_customer';
    public const RESERVE        = 'reserve';
    public const PRICE_TYPES    = 'price_types';
    public const VOUCHER_REDEEM = 'voucher_redeem';
    public const OWN_ORDERS     = 'own_orders';
    public const CANCEL_OWN     = 'cancel_own';
    public const OCCUPANCY      = 'occupancy';
    public const CUSTOMERS      = 'customers';

    /** Feature key => [German label, description, enabled by default] */
    public const FEATURES = [
        self::SELL_CASH      => ['Barverkauf ohne Kundendaten', 'Karten bar verkaufen, ohne Name und Adresse.', true],
        self::SELL_CUSTOMER  => ['Barverkauf mit Kundendaten', 'Karten bar verkaufen und den Käufer erfassen (Tickets per E-Mail möglich).', true],
        self::RESERVE        => ['Reservieren', 'Plätze für einen Kunden reservieren, Bezahlung später (an der Kasse).', false],
        self::PRICE_TYPES    => ['Preisarten', 'Ermäßigt, FN-Card und Rabatte anbieten (soweit für die Veranstaltung freigegeben). Ohne diese Funktion nur Normalpreis.', true],
        self::VOUCHER_REDEEM => ['Gutschein einlösen', 'Karten gegen einen Gutscheincode ausgeben.', false],
        self::OWN_ORDERS     => ['Eigene Verkäufe', 'Eigene Verkäufe ansehen und Tickets nachdrucken.', true],
        self::CANCEL_OWN     => ['Eigene Verkäufe stornieren', 'Eigene, noch nicht abgerechnete Verkäufe stornieren.', false],
        self::OCCUPANCY      => ['Sitzplan-Belegung', 'Belegung des Sitzplans ansehen.', false],
        self::CUSTOMERS      => ['Kundensuche', 'Bestehende Kunden suchen und übernehmen (personenbezogene Daten – nur gezielt freigeben).', false],
    ];

    /** @var array<int, array{features: ?array, event_ids: ?array}> user id => decoded permissions */
    private static array $userCache = [];

    /** @var bool|null Whether the partner migration has run */
    private static ?bool $available = null;

    // ------------------------------------------------------------------
    // Pure rule (unit tested)
    // ------------------------------------------------------------------

    /**
     * Decide whether a role may use a feature.
     *
     * @param array      $installFeatures Features the installation allows for partners
     * @param array|null $userFeatures    Features of the partner user (null = all the installation allows)
     */
    public static function decide(?string $role, string $feature, bool $roleActive, array $installFeatures, ?array $userFeatures): bool
    {
        if ($role === 'admin' || $role === 'presales') {
            return true;
        }
        if ($role !== 'partner' || !$roleActive || !isset(self::FEATURES[$feature])) {
            return false;
        }
        if (!in_array($feature, $installFeatures, true)) {
            return false;
        }
        return $userFeatures === null || in_array($feature, $userFeatures, true);
    }

    /**
     * Decide whether a role may sell for an event.
     *
     * @param array|null $allowedEventIds null = all events
     */
    public static function decideEvent(?string $role, int $eventId, ?array $allowedEventIds): bool
    {
        if ($role === 'admin' || $role === 'presales') {
            return true;
        }
        if ($role !== 'partner') {
            return false;
        }
        return $allowedEventIds === null || in_array($eventId, $allowedEventIds, true);
    }

    /**
     * Normalise a users.partner_permissions value (JSON string or array).
     *
     * @return array{features: ?array, event_ids: ?array} null entries = not restricted
     */
    public static function parseUserPermissions(mixed $raw): array
    {
        $data = is_string($raw) && $raw !== '' ? json_decode($raw, true) : $raw;
        if (!is_array($data)) {
            return ['features' => null, 'event_ids' => null];
        }
        $features = isset($data['features']) && is_array($data['features'])
            ? array_values(array_intersect(array_map('strval', $data['features']), array_keys(self::FEATURES)))
            : null;
        $eventIds = isset($data['event_ids']) && is_array($data['event_ids'])
            ? array_values(array_unique(array_filter(array_map('intval', $data['event_ids']))))
            : null;
        // An empty event list would lock the partner out completely – treat it as "all events".
        if ($eventIds === []) {
            $eventIds = null;
        }
        return ['features' => $features, 'event_ids' => $eventIds];
    }

    /**
     * Build the JSON stored in users.partner_permissions.
     *
     * @param array|null $features null = all features of the installation
     * @param array|null $eventIds null or [] = all events
     */
    public static function encodeUserPermissions(?array $features, ?array $eventIds): string
    {
        $parsed = self::parseUserPermissions(['features' => $features, 'event_ids' => $eventIds]);
        return json_encode($parsed);
    }

    // ------------------------------------------------------------------
    // Installation level
    // ------------------------------------------------------------------

    /** Whether the partner migration has run (users.role knows 'partner'). */
    public static function partnerRoleAvailable(): bool
    {
        if (self::$available === null) {
            try {
                $row = Database::getInstance()->query("SHOW COLUMNS FROM users LIKE 'partner_permissions'")->fetch();
                self::$available = (bool)$row
                    && (new \App\Models\Reservation())->hasSellerColumns();
            } catch (\Throwable $e) {
                self::$available = false;
            }
        }
        return self::$available;
    }

    /** Whether the installation uses the partner role at all. */
    public static function partnerRoleActive(): bool
    {
        return self::partnerRoleAvailable() && (bool)Config::get('partner_role_active', false);
    }

    /** Features the installation allows for partners (setting partner_features, defaults otherwise). */
    public static function installFeatures(): array
    {
        $configured = Config::get('partner_features', null);
        if (is_string($configured)) {
            $configured = json_decode($configured, true);
        }
        if (!is_array($configured)) {
            return self::defaultFeatures();
        }
        return array_values(array_intersect(array_map('strval', $configured), array_keys(self::FEATURES)));
    }

    /** @return string[] Features enabled by default */
    public static function defaultFeatures(): array
    {
        return array_keys(array_filter(self::FEATURES, fn($f) => $f[2]));
    }

    // ------------------------------------------------------------------
    // Current user
    // ------------------------------------------------------------------

    /** Permissions of a partner user, loaded once per request. */
    public static function userPermissions(int $userId): array
    {
        if (!isset(self::$userCache[$userId])) {
            $raw = null;
            if (self::partnerRoleAvailable()) {
                $row = Database::getInstance()->query(
                    "SELECT partner_permissions FROM users WHERE id = :id",
                    [':id' => $userId]
                )->fetch();
                $raw = $row['partner_permissions'] ?? null;
            }
            self::$userCache[$userId] = self::parseUserPermissions($raw);
        }
        return self::$userCache[$userId];
    }

    /** Whether the logged-in user may use a feature. */
    public static function can(string $feature): bool
    {
        $role = Auth::role();
        if ($role !== 'partner') {
            return self::decide($role, $feature, false, [], null);
        }
        $userId = Auth::id();
        if (!$userId) {
            return false;
        }
        return self::decide(
            $role,
            $feature,
            self::partnerRoleActive(),
            self::installFeatures(),
            self::userPermissions($userId)['features']
        );
    }

    /** @return int[]|null Event ids the logged-in user may sell for; null = all */
    public static function allowedEventIds(): ?array
    {
        if (Auth::role() !== 'partner') {
            return null;
        }
        $userId = Auth::id();
        return $userId ? self::userPermissions($userId)['event_ids'] : [];
    }

    /** Whether the logged-in user may sell for this event. */
    public static function canAccessEvent(int $eventId): bool
    {
        return self::decideEvent(Auth::role(), $eventId, self::allowedEventIds());
    }

    /**
     * Filter a list of event rows (each with 'id') to the events the user may sell for.
     */
    public static function filterEvents(array $events): array
    {
        $allowed = self::allowedEventIds();
        if ($allowed === null) {
            return $events;
        }
        return array_values(array_filter($events, fn($e) => in_array((int)($e['id'] ?? 0), $allowed, true)));
    }

    /**
     * Stop the request with 403 unless the user may use the feature.
     * $json = true answers with a JSON body (API endpoints).
     */
    public static function require(string $feature, bool $json = false): void
    {
        if (!self::can($feature)) {
            self::deny($json);
        }
    }

    /** Stop the request with 403 unless the user may sell for the event. */
    public static function requireEvent(int $eventId, bool $json = false): void
    {
        if (!self::canAccessEvent($eventId)) {
            self::deny($json, 'Für diese Veranstaltung haben Sie keine Berechtigung.');
        }
    }

    public static function deny(bool $json = false, string $message = 'Für diese Funktion haben Sie keine Berechtigung.'): never
    {
        http_response_code(403);
        if ($json) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['success' => false, 'error' => $message]);
        } else {
            echo '<!doctype html><html lang="de"><head><meta charset="utf-8"><title>Keine Berechtigung</title>'
                . '<link rel="stylesheet" href="' . htmlspecialchars(\App\Helpers\UrlHelper::url('libs/css/bootstrap.min.css')) . '"></head>'
                . '<body class="p-4"><div class="alert alert-danger">' . htmlspecialchars($message) . '</div>'
                . '<a class="btn btn-secondary" href="' . htmlspecialchars(\App\Helpers\UrlHelper::url('presales/')) . '">Zur Übersicht</a></body></html>';
        }
        exit;
    }

    /** Reset caches (tests, after an admin changed permissions). */
    public static function clearCache(): void
    {
        self::$userCache = [];
        self::$available = null;
    }
}
