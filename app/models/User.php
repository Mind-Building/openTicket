<?php
/**
 * User Model
 */

namespace App\Models;

use App\Core\Database;

class User
{
    private Database $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    /**
     * Get all users
     */
    public function getAll(): array
    {
        $stmt = $this->db->query("SELECT id, username, email, role, active, created_at, updated_at FROM users ORDER BY created_at DESC");
        return $stmt->fetchAll();
    }

    /**
     * Get user by ID
     */
    public function getById(int $id): ?array
    {
        $stmt = $this->db->query("SELECT id, username, email, role, active, created_at, updated_at FROM users WHERE id = :id", [':id' => $id]);
        $result = $stmt->fetch();
        return $result ?: null;
    }

    /**
     * Get user by username
     */
    public function getByUsername(string $username): ?array
    {
        $stmt = $this->db->query("SELECT * FROM users WHERE username = :username", [':username' => $username]);
        $result = $stmt->fetch();
        return $result ?: null;
    }

    /**
     * Whether users.email still is NOT NULL (installations before migrate_users_email_nullable.sql).
     */
    public function emailRequired(): bool
    {
        static $required = null;
        if ($required === null) {
            try {
                $stmt = $this->db->query(
                    "SELECT IS_NULLABLE FROM information_schema.COLUMNS
                     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'email'"
                );
                $required = ($stmt->fetchColumn() ?: 'YES') === 'NO';
            } catch (\Throwable) {
                $required = false;
            }
        }
        return $required;
    }

    /**
     * Throws a readable error instead of an SQL error when the e-mail is missing or taken.
     */
    public function validateEmail(?string $email, ?int $ignoreUserId = null): void
    {
        $email = trim((string)$email);
        if ($email === '') {
            if ($this->emailRequired()) {
                throw new \Exception('Bitte eine E-Mail-Adresse angeben. Benutzer ohne E-Mail sind erst nach dem Datenbank-Update (Einstellungen → Datenbank-Update) möglich.');
            }
            return;
        }
        $stmt = $this->db->query(
            'SELECT id FROM users WHERE email = :email AND id != :id',
            [':email' => $email, ':id' => $ignoreUserId ?? 0]
        );
        if ($stmt->fetch()) {
            throw new \Exception('Diese E-Mail-Adresse wird bereits von einem anderen Benutzer verwendet.');
        }
    }

    /**
     * Create new user
     */
    public function create(array $data): int
    {
        $passwordHash = password_hash($data['password'], PASSWORD_DEFAULT);
        
        $sql = "INSERT INTO users (username, email, password_hash, role, active)
                VALUES (:username, :email, :password_hash, :role, :active)";
        
        $this->db->query($sql, [
            ':username' => $data['username'],
            ':email' => trim((string)($data['email'] ?? '')) !== '' ? trim($data['email']) : null,
            ':password_hash' => $passwordHash,
            ':role' => $data['role'] ?? 'presales',
            ':active' => $data['active'] ?? 1,
        ]);

        return (int)$this->db->lastInsertId();
    }

    /**
     * Update user
     */
    public function update(int $id, array $data): bool
    {
        $sql = "UPDATE users SET 
                username = :username,
                email = :email,
                role = :role,
                active = :active";
        
        $params = [
            ':id' => $id,
            ':username' => $data['username'],
            ':email' => trim((string)($data['email'] ?? '')) !== '' ? trim($data['email']) : null,
            ':role' => $data['role'],
            ':active' => $data['active'] ?? 1,
        ];

        // Only update password if provided
        if (!empty($data['password'])) {
            $sql .= ", password_hash = :password_hash";
            $params[':password_hash'] = password_hash($data['password'], PASSWORD_DEFAULT);
        }

        $sql .= ", updated_at = NOW() WHERE id = :id";
        
        $this->db->query($sql, $params);
        return true;
    }

    /**
     * Partner permissions JSON of a user (users.partner_permissions) or null.
     * Returns null as well before database/migrate_partner_role.sql has run.
     */
    public function getPartnerPermissions(int $id): ?string
    {
        if (!\App\Core\Permission::partnerRoleAvailable()) {
            return null;
        }
        $row = $this->db->query("SELECT partner_permissions FROM users WHERE id = :id", [':id' => $id])->fetch();
        return $row['partner_permissions'] ?? null;
    }

    /**
     * Store the partner permissions (JSON from Permission::encodeUserPermissions(), null = no restriction).
     */
    public function setPartnerPermissions(int $id, ?string $json): void
    {
        if (!\App\Core\Permission::partnerRoleAvailable()) {
            return;
        }
        $this->db->query(
            "UPDATE users SET partner_permissions = :p, updated_at = NOW() WHERE id = :id",
            [':p' => $json, ':id' => $id]
        );
    }

    /**
     * All users with the role "partner" (Externe Partner), by username.
     */
    public function getPartners(): array
    {
        return $this->db->query(
            "SELECT id, username, email, active FROM users WHERE role = 'partner' ORDER BY username"
        )->fetchAll();
    }

    /**
     * Delete user
     */
    public function delete(int $id): bool
    {
        $this->db->query("DELETE FROM users WHERE id = :id", [':id' => $id]);
        return true;
    }
}

