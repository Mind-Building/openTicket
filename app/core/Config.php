<?php
/**
 * Configuration Manager
 * Loads configurations from database
 */

namespace App\Core;

use App\Core\Database;

class Config
{
    private static array $cache = [];
    private static bool $loaded = false;

    /**
     * Get configuration value by key
     */
    public static function get(string $key, mixed $default = null): mixed
    {
        if (!self::$loaded) {
            self::loadAll();
        }

        return self::$cache[$key] ?? $default;
    }

    /**
     * Set configuration value
     */
    public static function set(string $key, mixed $value, string $type = 'string'): bool
    {
        $db = Database::getInstance();
        
        // Convert value based on type
        $dbValue = self::convertValue($value, $type);
        
        $sql = "INSERT INTO configurations (`key`, `value`, `type`) 
                VALUES (:key, :value, :type)
                ON DUPLICATE KEY UPDATE `value` = :value2, `type` = :type2, `updated_at` = NOW()";
        
        $db->query($sql, [
            ':key' => $key,
            ':value' => $dbValue,
            ':value2' => $dbValue,
            ':type' => $type,
            ':type2' => $type
        ]);

        // Update cache
        self::$cache[$key] = self::parseValue($dbValue, $type);
        
        return true;
    }

    /**
     * Load all configurations from database
     */
    private static function loadAll(): void
    {
        try {
            $db = Database::getInstance();
            $stmt = $db->query("SELECT `key`, `value`, `type` FROM configurations");
            $configs = $stmt->fetchAll();

            foreach ($configs as $config) {
                self::$cache[$config['key']] = self::parseValue($config['value'], $config['type']);
            }

            self::$loaded = true;
        } catch (\Exception $e) {
            // If table doesn't exist yet, use defaults
            self::$cache = self::getDefaults();
            self::$loaded = true;
        }
    }

    /**
     * Parse value based on type
     */
    private static function parseValue(string $value, string $type): mixed
    {
        return match($type) {
            'integer' => (int)$value,
            'boolean' => (bool)$value,
            'json' => json_decode($value, true),
            default => $value
        };
    }

    /**
     * Convert value for database storage
     */
    private static function convertValue(mixed $value, string $type): string
    {
        return match($type) {
            'integer' => (string)(int)$value,
            'boolean' => $value ? '1' : '0',
            'json' => json_encode($value),
            default => (string)$value
        };
    }

    /**
     * Get default configurations
     */
    private static function getDefaults(): array
    {
        return [
            'company_name' => 'Ticket System',
            'company_logo' => '',
            'company_url' => '',
            'ticket_url_start' => '',
            'paypal_return_url' => '',
            'timezone' => 'Europe/Berlin',
            'max_reservation_time' => '12:00',
            'show_start_time' => '20:30',
            'reservation_phone' => '',
            'paypal_active' => false,
            'paypal_mode' => 'sandbox',
            'voucher_active' => false,
            'reduced_prices_active' => false,
            'fn_card_active' => false,
        ];
    }

    /**
     * Clear cache (useful after updates)
     */
    public static function clearCache(): void
    {
        self::$cache = [];
        self::$loaded = false;
    }
}

