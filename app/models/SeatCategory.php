<?php
/**
 * Seat Category Model
 */

namespace App\Models;

use App\Core\Database;

class SeatCategory
{
    private Database $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    /**
     * Get all categories for an event
     */
    public function getByEvent(int $eventId): array
    {
        $stmt = $this->db->query(
            "SELECT * FROM seat_categories WHERE event_id = :event_id ORDER BY name ASC",
            [':event_id' => $eventId]
        );
        return $stmt->fetchAll();
    }

    /**
     * Get category by ID
     */
    public function getById(int $id): ?array
    {
        $stmt = $this->db->query("SELECT * FROM seat_categories WHERE id = :id", [':id' => $id]);
        $result = $stmt->fetch();
        return $result ?: null;
    }

    /**
     * Create category
     */
    public function create(array $data): int
    {
        $sql = "INSERT INTO seat_categories 
                (event_id, name, sign, price, reduced_price, general_rehearsal_price, color, is_general_rehearsal)
                VALUES (:event_id, :name, :sign, :price, :reduced_price, :general_rehearsal_price, :color, :is_general_rehearsal)";
        
        $this->db->query($sql, [
            ':event_id' => $data['event_id'],
            ':name' => $data['name'],
            ':sign' => $data['sign'],
            ':price' => $data['price'],
            ':reduced_price' => $data['reduced_price'] ?? null,
            ':general_rehearsal_price' => $data['general_rehearsal_price'] ?? null,
            ':color' => $data['color'] ?? null,
            ':is_general_rehearsal' => $data['is_general_rehearsal'] ?? 0,
        ]);

        return (int)$this->db->lastInsertId();
    }

    /**
     * Update category
     */
    public function update(int $id, array $data): bool
    {
        $sql = "UPDATE seat_categories SET 
                name = :name,
                sign = :sign,
                price = :price,
                reduced_price = :reduced_price,
                general_rehearsal_price = :general_rehearsal_price,
                color = :color,
                is_general_rehearsal = :is_general_rehearsal,
                updated_at = NOW()
                WHERE id = :id";
        
        $this->db->query($sql, [
            ':id' => $id,
            ':name' => $data['name'],
            ':sign' => $data['sign'],
            ':price' => $data['price'],
            ':reduced_price' => $data['reduced_price'] ?? null,
            ':general_rehearsal_price' => $data['general_rehearsal_price'] ?? null,
            ':color' => $data['color'] ?? null,
            ':is_general_rehearsal' => $data['is_general_rehearsal'] ?? 0,
        ]);

        return true;
    }

    /**
     * Delete category
     */
    public function delete(int $id): bool
    {
        $this->db->query("DELETE FROM seat_categories WHERE id = :id", [':id' => $id]);
        return true;
    }
}

