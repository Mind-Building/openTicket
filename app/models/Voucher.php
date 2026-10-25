<?php
/**
 * Voucher Model
 */

namespace App\Models;

use App\Core\Database;

class Voucher
{
    private Database $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }
    
    /**
     * Get database connection for direct queries
     */
    public function getConnection()
    {
        return $this->db->getConnection();
    }

    /**
     * Get all vouchers
     */
    public function getAll(int $limit = 100, int $offset = 0): array
    {
        $sql = "SELECT v.*, 
                       e.name as event_name, 
                       sc.name as category_name, 
                       sc.price as category_price,
                       c.first_name as customer_first_name,
                       c.last_name as customer_last_name,
                       c.email as customer_email
                FROM vouchers v
                LEFT JOIN events e ON v.event_id = e.id
                LEFT JOIN seat_categories sc ON v.category_id = sc.id
                LEFT JOIN customers c ON v.customer_id = c.id
                ORDER BY v.created_at DESC 
                LIMIT " . (int)$limit . " OFFSET " . (int)$offset;
        $stmt = $this->db->query($sql);
        return $stmt->fetchAll();
    }

    /**
     * Get voucher by ID
     */
    public function getById(int $id): ?array
    {
        $sql = "SELECT v.*, 
                       e.name as event_name, 
                       sc.name as category_name, 
                       sc.price as category_price,
                       c.first_name as customer_first_name,
                       c.last_name as customer_last_name,
                       c.email as customer_email
                FROM vouchers v
                LEFT JOIN events e ON v.event_id = e.id
                LEFT JOIN seat_categories sc ON v.category_id = sc.id
                LEFT JOIN customers c ON v.customer_id = c.id
                WHERE v.id = :id";
        $stmt = $this->db->query($sql, [':id' => $id]);
        $result = $stmt->fetch();
        return $result ?: null;
    }

    /**
     * Get voucher by code
     */
    public function getByCode(string $code): ?array
    {
        $sql = "SELECT v.*, e.name as event_name, sc.name as category_name, sc.price as category_price
                FROM vouchers v
                LEFT JOIN events e ON v.event_id = e.id
                LEFT JOIN seat_categories sc ON v.category_id = sc.id
                WHERE v.voucher_code = :code";
        $stmt = $this->db->query($sql, [':code' => $code]);
        $result = $stmt->fetch();
        return $result ?: null;
    }

    /**
     * Get vouchers by session (in cart)
     * $purchaseIntent: 'buy' = vouchers to purchase, 'redeem' = vouchers being used as discount, null = all
     */
    public function getBySession(string $sessionId, ?string $purchaseIntent = null): array
    {
        $sql = "SELECT v.*, e.name as event_name, sc.name as category_name, sc.price as category_price
                FROM vouchers v
                LEFT JOIN events e ON v.event_id = e.id
                LEFT JOIN seat_categories sc ON v.category_id = sc.id
                WHERE v.session_id = :session_id AND v.status_id = 1";
        
        $params = [':session_id' => $sessionId];
        
        if ($purchaseIntent !== null) {
            $sql .= " AND v.purchase_intent = :purchase_intent";
            $params[':purchase_intent'] = $purchaseIntent;
        }
        
        $sql .= " ORDER BY v.created_at ASC";

        $stmt = $this->db->query($sql, $params);
        return $stmt->fetchAll();
    }

    /**
     * Restart the expiry clock for all cart vouchers (status_id = 1) of a session.
     *
     * Mirrors Reservation::touchCartItems(). The cleanup deletes vouchers being
     * bought and releases vouchers being redeemed once updated_at is older than
     * 20 minutes, so a customer who spends longer in checkout or the PayPal flow
     * loses them mid-payment. Explicit SET is required: an UPDATE that writes
     * identical values does not trigger MySQL's ON UPDATE timestamp.
     *
     * Returns number of refreshed rows.
     */
    public function touchCartItems(string $sessionId): int
    {
        $stmt = $this->db->query(
            "UPDATE vouchers
             SET updated_at = NOW()
             WHERE session_id = :session_id
             AND status_id = 1",
            [':session_id' => $sessionId]
        );

        return (int)$stmt->rowCount();
    }

    /**
     * Search vouchers
     */
    public function search(string $query, int $limit = 50): array
    {
        $searchTerm = '%' . $query . '%';
        $sql = "SELECT v.*, 
                       e.name as event_name, 
                       sc.name as category_name, 
                       sc.price as category_price,
                       c.first_name as customer_first_name,
                       c.last_name as customer_last_name,
                       c.email as customer_email
                FROM vouchers v
                LEFT JOIN events e ON v.event_id = e.id
                LEFT JOIN seat_categories sc ON v.category_id = sc.id
                LEFT JOIN customers c ON v.customer_id = c.id
                WHERE v.voucher_code LIKE :query1
                OR COALESCE(c.first_name, '') LIKE :query2
                OR COALESCE(c.last_name, '') LIKE :query3
                OR COALESCE(c.email, '') LIKE :query4
                ORDER BY v.created_at DESC 
                LIMIT " . (int)$limit;
        $stmt = $this->db->query($sql, [
            ':query1' => $searchTerm,
            ':query2' => $searchTerm,
            ':query3' => $searchTerm,
            ':query4' => $searchTerm
        ]);
        return $stmt->fetchAll();
    }

    /**
     * Count all vouchers
     */
    public function count(): int
    {
        $stmt = $this->db->query("SELECT COUNT(*) as count FROM vouchers");
        $result = $stmt->fetch();
        return (int)($result['count'] ?? 0);
    }

    /**
     * Get vouchers stuck in cart (status_id=1) older than given minutes.
     * These are unfinished customer checkouts.
     */
    public function getStuckInCart(int $olderThanMinutes = 15): array
    {
        $sql = "SELECT v.*,
                       e.name as event_name,
                       sc.name as category_name,
                       sc.price as category_price,
                       c.first_name as customer_first_name,
                       c.last_name as customer_last_name,
                       c.email as customer_email
                FROM vouchers v
                LEFT JOIN events e ON v.event_id = e.id
                LEFT JOIN seat_categories sc ON v.category_id = sc.id
                LEFT JOIN customers c ON v.customer_id = c.id
                WHERE v.status_id = 1
                AND v.created_at <= DATE_SUB(NOW(), INTERVAL :minutes MINUTE)
                ORDER BY v.created_at ASC";
        $stmt = $this->db->query($sql, [':minutes' => $olderThanMinutes]);
        return $stmt->fetchAll();
    }

    /**
     * Reset stuck cart vouchers back to status_id=0 (release from cart).
     * Returns the number of released vouchers.
     */
    public function releaseStuckCartVouchers(int $olderThanMinutes = 15): int
    {
        $sql = "UPDATE vouchers SET status_id = 0
                WHERE status_id = 1
                AND created_at <= DATE_SUB(NOW(), INTERVAL :minutes MINUTE)";
        $stmt = $this->db->query($sql, [':minutes' => $olderThanMinutes]);
        return $stmt->rowCount();
    }

    /**
     * Create new voucher
     */
    public function create(array $data): int
    {
        // Generate unique voucher code
        $voucherCode = $this->generateVoucherCode();
        $hash = hash('sha256', $voucherCode . time());
        
        $sql = "INSERT INTO vouchers (voucher_code, hash, customer_id, session_id, purchase_intent, event_id, category_id, quantity, status_id, is_reduced, is_fn_card)
                VALUES (:voucher_code, :hash, :customer_id, :session_id, :purchase_intent, :event_id, :category_id, :quantity, :status_id, :is_reduced, :is_fn_card)";
        
        $this->db->query($sql, [
            ':voucher_code' => $voucherCode,
            ':hash' => $hash,
            ':customer_id' => $data['customer_id'] ?? null,
            ':session_id' => $data['session_id'],
            ':purchase_intent' => $data['purchase_intent'] ?? 'buy',
            ':event_id' => $data['event_id'],
            ':category_id' => $data['category_id'],
            ':quantity' => $data['quantity'] ?? 1,
            ':status_id' => $data['status_id'] ?? 0,
            ':is_reduced' => $data['is_reduced'] ?? 0,
            ':is_fn_card' => $data['is_fn_card'] ?? 0,
        ]);

        return (int)$this->db->lastInsertId();
    }

    /**
     * Update voucher
     */
    public function update(int $id, array $data): bool
    {
        $setClause = [];
        $params = [':id' => $id];

        // Only update fields that are provided
        if (isset($data['customer_id'])) {
            $setClause[] = "customer_id = :customer_id";
            $params[':customer_id'] = $data['customer_id'];
        }
        if (isset($data['status_id'])) {
            $setClause[] = "status_id = :status_id";
            $params[':status_id'] = $data['status_id'];
        }
        if (isset($data['purchase_intent'])) {
            $setClause[] = "purchase_intent = :purchase_intent";
            $params[':purchase_intent'] = $data['purchase_intent'];
        }
        if (isset($data['session_id'])) {
            $setClause[] = "session_id = :session_id";
            $params[':session_id'] = $data['session_id'];
        }
        if (isset($data['is_reduced'])) {
            $setClause[] = "is_reduced = :is_reduced";
            $params[':is_reduced'] = $data['is_reduced'];
        }
        if (isset($data['is_fn_card'])) {
            $setClause[] = "is_fn_card = :is_fn_card";
            $params[':is_fn_card'] = $data['is_fn_card'];
        }
        if (isset($data['transformed_session_id'])) {
            $setClause[] = "transformed_session_id = :transformed_session_id";
            $params[':transformed_session_id'] = $data['transformed_session_id'];
        }
        if (isset($data['transformed_status_id'])) {
            $setClause[] = "transformed_status_id = :transformed_status_id";
            $params[':transformed_status_id'] = $data['transformed_status_id'];
        }
        if (isset($data['mail_sent'])) {
            $setClause[] = "mail_sent = :mail_sent";
            $params[':mail_sent'] = $data['mail_sent'];
        }
        if (isset($data['mail_sent_date'])) {
            $setClause[] = "mail_sent_date = :mail_sent_date";
            $params[':mail_sent_date'] = $data['mail_sent_date'];
        }

        if (empty($setClause)) {
            return false; // No fields to update
        }

        $setClause[] = "updated_at = NOW()";
        $sql = "UPDATE vouchers SET " . implode(', ', $setClause) . " WHERE id = :id";
        
        try {
            $this->db->query($sql, $params);
            return true;
        } catch (\Exception $e) {
            error_log("Voucher update error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Delete voucher
     */
    public function delete(int $id): bool
    {
        $this->db->query("DELETE FROM vouchers WHERE id = :id", [':id' => $id]);
        return true;
    }

    /**
     * Generate a unique voucher code.
     *
     * Format: 12 characters from Crockford-Base32 (0-9, A-Z without I, L, O, U)
     * via random_int(), which uses a cryptographically secure RNG.
     * That gives ~60 bits of entropy — astronomically unlikely to collide and
     * not brute-forceable.
     *
     * Existing voucher codes in the database stay valid unchanged: redemption
     * does a plain string match in voucher_code, so old codes (generated with
     * the previous md5/uniqid scheme) keep working.
     */
    private function generateVoucherCode(): string
    {
        // Crockford-Base32 alphabet – avoids visually ambiguous characters
        // (no I, L, O, U) so codes can be read aloud or written down safely.
        $alphabet    = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';
        $alphabetLen = strlen($alphabet);
        $codeLength  = 12;
        $maxAttempts = 10;

        for ($attempt = 0; $attempt < $maxAttempts; $attempt++) {
            $code = '';
            for ($i = 0; $i < $codeLength; $i++) {
                $code .= $alphabet[random_int(0, $alphabetLen - 1)];
            }
            if ($this->getByCode($code) === null) {
                return $code;
            }
        }

        throw new \RuntimeException(
            'Konnte nach ' . $maxAttempts . ' Versuchen keinen eindeutigen Gutschein-Code erzeugen.'
        );
    }
}

