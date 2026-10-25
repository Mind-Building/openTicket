<?php
/**
 * DeviceToken Model
 * Manages device authentication tokens for presales terminals
 */

namespace App\Models;

use App\Core\Database;

class DeviceToken
{
    // Tokens are stored as "sha256:<hex>" so a DB leak does not expose usable
    // tokens. Rows without this prefix are legacy plaintext tokens; they keep
    // working via the fallback in findByToken() and are upgraded on first use.
    private const HASH_PREFIX = 'sha256:';

    private Database $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    private static function hashToken(string $token): string
    {
        return self::HASH_PREFIX . hash('sha256', $token);
    }

    /**
     * Whether a stored token value is already hashed (vs. legacy plaintext).
     */
    public static function isHashed(string $storedToken): bool
    {
        return str_starts_with($storedToken, self::HASH_PREFIX);
    }

    /**
     * Create a new device token for a presales user.
     *
     * @param int $userId The presales user this token belongs to
     * @param string $label Human-readable label (e.g. "Kasse 1")
     * @param int|null $expiryDays Days until expiry, null = no expiry
     * @return array ['id' => int, 'token' => string, 'expires_at' => string|null]
     */
    public function create(int $userId, string $label, ?int $expiryDays = 365): array
    {
        $token = bin2hex(random_bytes(32)); // 64 hex chars = 256 bits
        $expiresAt = $expiryDays !== null
            ? date('Y-m-d H:i:s', strtotime("+{$expiryDays} days"))
            : null;

        $this->db->query(
            "INSERT INTO device_tokens (token, user_id, label, expires_at)
             VALUES (:token, :user_id, :label, :expires_at)",
            [
                ':token'      => self::hashToken($token),
                ':user_id'    => $userId,
                ':label'      => $label,
                ':expires_at' => $expiresAt,
            ]
        );

        $id = (int)$this->db->lastInsertId();

        return ['id' => $id, 'token' => $token, 'expires_at' => $expiresAt];
    }

    /**
     * Find a valid (non-revoked, non-expired) device token and return it
     * together with the linked user data.
     *
     * @param string $token Raw token value from cookie
     * @return array|null Token+user record or null if invalid
     */
    public function findByToken(string $token): ?array
    {
        // Primary lookup: hashed storage format
        $record = $this->fetchValidToken(self::hashToken($token));

        if (!$record) {
            // A supplied value with the hash prefix can only be a stolen DB
            // value being replayed – never a real token (those are pure hex).
            if (self::isHashed($token)) {
                return null;
            }

            // Legacy fallback: plaintext rows created before token hashing.
            $record = $this->fetchValidToken($token);
            if ($record) {
                // Upgrade to hashed storage. The raw token in cookie and
                // activation URL keeps working – it now matches via the hash.
                $this->db->query(
                    "UPDATE device_tokens SET token = :hash WHERE id = :id",
                    [':hash' => self::hashToken($token), ':id' => $record['id']]
                );
            }
        }

        return $record;
    }

    private function fetchValidToken(string $storedValue): ?array
    {
        $stmt = $this->db->query(
            "SELECT dt.*, u.username, u.role, u.active AS user_active
             FROM device_tokens dt
             JOIN users u ON dt.user_id = u.id
             WHERE dt.token = :token
               AND dt.revoked_at IS NULL
               AND (dt.expires_at IS NULL OR dt.expires_at > NOW())
               AND u.active = 1",
            [':token' => $storedValue]
        );
        $result = $stmt->fetch();
        return $result ?: null;
    }

    /**
     * Get all device tokens for a specific user (including revoked).
     */
    public function getByUserId(int $userId): array
    {
        $stmt = $this->db->query(
            "SELECT * FROM device_tokens WHERE user_id = :user_id ORDER BY created_at DESC",
            [':user_id' => $userId]
        );
        return $stmt->fetchAll();
    }

    /**
     * Get a single token record by ID.
     */
    public function getById(int $id): ?array
    {
        $stmt = $this->db->query(
            "SELECT * FROM device_tokens WHERE id = :id",
            [':id' => $id]
        );
        $result = $stmt->fetch();
        return $result ?: null;
    }

    /**
     * Revoke a device token so it can no longer be used.
     */
    public function revoke(int $id): void
    {
        $this->db->query(
            "UPDATE device_tokens SET revoked_at = NOW() WHERE id = :id",
            [':id' => $id]
        );
    }

    /**
     * Update the last_used_at timestamp of a token.
     */
    public function updateLastUsed(int $id): void
    {
        $this->db->query(
            "UPDATE device_tokens SET last_used_at = NOW() WHERE id = :id",
            [':id' => $id]
        );
    }
}
