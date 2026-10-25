<?php
/**
 * Customer Model
 */

namespace App\Models;

use App\Core\Database;

class Customer
{
    private Database $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    /**
     * Get all customers
     */
    public function getAll(int $limit = 100, int $offset = 0): array
    {
        $sql = "SELECT * FROM customers ORDER BY created_at DESC LIMIT " . (int)$limit . " OFFSET " . (int)$offset;
        $stmt = $this->db->query($sql);
        return $stmt->fetchAll();
    }

    /**
     * Get customer by ID
     */
    public function getById(int $id): ?array
    {
        $stmt = $this->db->query("SELECT * FROM customers WHERE id = :id", [':id' => $id]);
        $result = $stmt->fetch();
        return $result ?: null;
    }

    /**
     * Get customer by session ID
     */
    public function getBySessionId(string $sessionId): ?array
    {
        $stmt = $this->db->query("SELECT * FROM customers WHERE session_id = :session_id ORDER BY created_at DESC LIMIT 1", [':session_id' => $sessionId]);
        $result = $stmt->fetch();
        return $result ?: null;
    }

    /**
     * Get customer by session ID and item type
     */
    public function getBySession(string $sessionId, string $itemType = 'ticket'): ?array
    {
        $stmt = $this->db->query("SELECT * FROM customers WHERE session_id = :session_id AND item_type = :item_type ORDER BY created_at DESC LIMIT 1", [
            ':session_id' => $sessionId,
            ':item_type' => $itemType
        ]);
        $result = $stmt->fetch();
        return $result ?: null;
    }

    /**
     * Search customers
     */
    public function search(string $query, int $limit = 50): array
    {
        $searchTerm = '%' . $query . '%';
        $sql = "SELECT * FROM customers
                WHERE first_name LIKE :q1
                OR last_name LIKE :q2
                OR email LIKE :q3
                OR city LIKE :q4
                OR id LIKE :q5
                ORDER BY created_at DESC
                LIMIT " . (int)$limit;
        $stmt = $this->db->query($sql, [
            ':q1' => $searchTerm,
            ':q2' => $searchTerm,
            ':q3' => $searchTerm,
            ':q4' => $searchTerm,
            ':q5' => $searchTerm,
        ]);
        return $stmt->fetchAll();
    }

    /**
     * Count all customers
     */
    public function count(): int
    {
        $stmt = $this->db->query("SELECT COUNT(*) as count FROM customers");
        $result = $stmt->fetch();
        return (int)($result['count'] ?? 0);
    }

    /**
     * Find existing customer by email and address
     * Returns customer ID if found, null otherwise
     */
    public function findExisting(array $data): ?int
    {
        // Normalize email for comparison
        $email = strtolower(trim($data['email'] ?? ''));
        if (empty($email)) {
            return null;
        }
        
        // Try to find by email first (most reliable)
        $sql = "SELECT id FROM customers 
                WHERE LOWER(TRIM(email)) = :email 
                AND first_name = :first_name 
                AND last_name = :last_name
                AND street = :street 
                AND house_number = :house_number 
                AND postal_code = :postal_code 
                AND city = :city
                ORDER BY created_at DESC 
                LIMIT 1";
        
        $stmt = $this->db->query($sql, [
            ':email' => $email,
            ':first_name' => $data['first_name'] ?? '',
            ':last_name' => $data['last_name'] ?? '',
            ':street' => $data['street'] ?? '',
            ':house_number' => $data['house_number'] ?? '',
            ':postal_code' => $data['postal_code'] ?? '',
            ':city' => $data['city'] ?? '',
        ]);
        
        $result = $stmt->fetch();
        return $result ? (int)$result['id'] : null;
    }

    /**
     * Create new customer or return existing one
     * Checks for duplicate customers before creating
     */
    public function createOrGet(array $data): int
    {
        // Check if customer already exists
        $existingId = $this->findExisting($data);
        if ($existingId !== null) {
            // Update ALL customer data (including session_id and item_type)
            $updateData = $data;
            $updateData['session_id'] = $data['session_id'] ?? '';
            $updateData['item_type'] = $data['item_type'] ?? 'ticket';
            $this->update($existingId, $updateData);
            return $existingId;
        }
        
        // Create new customer
        return $this->create($data);
    }

    /**
     * Create new customer
     */
    public function create(array $data): int
    {
        $sql = "INSERT INTO customers (salutation, first_name, last_name, street, house_number, postal_code, city, email, phone_prefix, phone_number, session_id, item_type)
                VALUES (:salutation, :first_name, :last_name, :street, :house_number, :postal_code, :city, :email, :phone_prefix, :phone_number, :session_id, :item_type)";
        
        $this->db->query($sql, [
            ':salutation' => $data['salutation'] ?? null,
            ':first_name' => $data['first_name'],
            ':last_name' => $data['last_name'],
            ':street' => $data['street'],
            ':house_number' => $data['house_number'],
            ':postal_code' => $data['postal_code'],
            ':city' => $data['city'],
            ':email' => $data['email'],
            ':phone_prefix' => $data['phone_prefix'] ?? null,
            ':phone_number' => $data['phone_number'] ?? null,
            ':session_id' => $data['session_id'],
            ':item_type' => $data['item_type'] ?? 'ticket',
        ]);

        return (int)$this->db->lastInsertId();
    }

    /**
     * Update customer
     */
    public function update(int $id, array $data): bool
    {
        // Also update session_id if provided
        $updateSessionId = isset($data['session_id']);
        
        $sql = "UPDATE customers SET 
                salutation = :salutation,
                first_name = :first_name,
                last_name = :last_name,
                street = :street,
                house_number = :house_number,
                postal_code = :postal_code,
                city = :city,
                email = :email,
                phone_prefix = :phone_prefix,
                phone_number = :phone_number,
                item_type = :item_type" .
                ($updateSessionId ? ", session_id = :session_id" : "") . ",
                updated_at = NOW()
                WHERE id = :id";
        
        $params = [
            ':id' => $id,
            ':salutation' => $data['salutation'] ?? null,
            ':first_name' => $data['first_name'] ?? '',
            ':last_name' => $data['last_name'] ?? '',
            ':street' => $data['street'] ?? '',
            ':house_number' => $data['house_number'] ?? '',
            ':postal_code' => $data['postal_code'] ?? '',
            ':city' => $data['city'] ?? '',
            ':email' => $data['email'] ?? '',
            ':phone_prefix' => $data['phone_prefix'] ?? null,
            ':phone_number' => $data['phone_number'] ?? null,
            ':item_type' => $data['item_type'] ?? 'ticket',
        ];
        
        if ($updateSessionId) {
            $params[':session_id'] = $data['session_id'];
        }
        
        $this->db->query($sql, $params);

        return true;
    }

    /**
     * Delete customer
     */
    public function delete(int $id): bool
    {
        $this->db->query("DELETE FROM customers WHERE id = :id", [':id' => $id]);
        return true;
    }
}
