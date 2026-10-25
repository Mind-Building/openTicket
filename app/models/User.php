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
     * Create new user
     */
    public function create(array $data): int
    {
        $passwordHash = password_hash($data['password'], PASSWORD_DEFAULT);
        
        $sql = "INSERT INTO users (username, email, password_hash, role, active)
                VALUES (:username, :email, :password_hash, :role, :active)";
        
        $this->db->query($sql, [
            ':username' => $data['username'],
            ':email' => !empty($data['email']) ? $data['email'] : null,
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
            ':email' => !empty($data['email']) ? $data['email'] : null,
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
     * Delete user
     */
    public function delete(int $id): bool
    {
        $this->db->query("DELETE FROM users WHERE id = :id", [':id' => $id]);
        return true;
    }
}

