<?php
/**
 * PaymentRequest Model
 * Verwaltet Zahlungsanforderungen (Admin → Kunde per Bezahllink)
 */

namespace App\Models;

use App\Core\Database;

class PaymentRequest
{
    private Database $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    public function getAll(): array
    {
        return $this->db->query(
            'SELECT pr.*, u.username as created_by_name
             FROM payment_requests pr
             LEFT JOIN users u ON u.id = pr.created_by
             ORDER BY pr.created_at DESC',
            []
        )->fetchAll(\PDO::FETCH_ASSOC);
    }

    public function getById(int $id): ?array
    {
        $row = $this->db->query(
            'SELECT * FROM payment_requests WHERE id = :id',
            [':id' => $id]
        )->fetch(\PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function getByToken(string $token): ?array
    {
        $row = $this->db->query(
            'SELECT * FROM payment_requests WHERE token = :token',
            [':token' => $token]
        )->fetch(\PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function create(array $data): int
    {
        $this->db->query(
            'INSERT INTO payment_requests (token, customer_email, customer_name, amount, description, created_by, notes)
             VALUES (:token, :email, :name, :amount, :description, :created_by, :notes)',
            [
                ':token'       => $data['token'],
                ':email'       => $data['customer_email'],
                ':name'        => $data['customer_name'],
                ':amount'      => $data['amount'],
                ':description' => $data['description'] ?? null,
                ':created_by'  => $data['created_by'] ?? null,
                ':notes'       => $data['notes'] ?? null,
            ]
        );
        return (int)$this->db->lastInsertId();
    }

    public function markPaid(int $id, string $paypalOrderId): void
    {
        $this->db->query(
            'UPDATE payment_requests SET status = :status, paypal_order_id = :order_id, paid_at = NOW() WHERE id = :id',
            [':status' => 'paid', ':order_id' => $paypalOrderId, ':id' => $id]
        );
    }

    public function cancel(int $id): void
    {
        $this->db->query(
            'UPDATE payment_requests SET status = :status WHERE id = :id',
            [':status' => 'cancelled', ':id' => $id]
        );
    }

    /**
     * Generiert einen eindeutigen sicheren Token
     */
    public static function generateToken(): string
    {
        return bin2hex(random_bytes(32));
    }
}
