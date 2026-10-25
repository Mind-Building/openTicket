<?php
/**
 * Reservation Model
 */

namespace App\Models;

use App\Core\Database;

class Reservation
{
    /**
     * Lifetime of a cart reservation (status_id = 1) in minutes.
     *
     * This single value drives BOTH the seat block and the cleanup, so a cart row
     * blocks its seat for exactly as long as it exists. The two used to differ
     * (block 15 min, delete 20 min), which left a 5-minute window in which a row
     * was still present but no longer blocked: two sessions could end up holding
     * the same seat, and there is no unique key on
     * (event_id, event_date, seat_identifier) to catch that.
     */
    public const CART_LIFETIME_MINUTES = 20;

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
     * Get all reservations
     */
    public function getAll(array $filters = []): array
    {
        try {
            $sql = "SELECT r.*, 
                    e.name as event_name, e.show_start_time as event_time,
                    c.first_name, c.last_name, c.email,
                    sc.name as category_name, sc.sign as category_sign, sc.price as category_price,
                    rs.name as status_name,
                    v.voucher_code, v.id as voucher_id
                    FROM reservations r
                    LEFT JOIN events e ON r.event_id = e.id
                    LEFT JOIN customers c ON r.customer_id = c.id
                    LEFT JOIN seat_categories sc ON r.category_id = sc.id
                    LEFT JOIN reservation_statuses rs ON r.status_id = rs.id
                    LEFT JOIN vouchers v ON r.voucher_id = v.id
                    WHERE 1=1";
            
            $params = [];
            
            if (!empty($filters['event_id'])) {
                $sql .= " AND r.event_id = :event_id";
                $params[':event_id'] = $filters['event_id'];
            }
            
            if (!empty($filters['event_date'])) {
                $sql .= " AND r.event_date = :event_date";
                $params[':event_date'] = $filters['event_date'];
            }
            
            if (isset($filters['status_id'])) {
                $sql .= " AND r.status_id = :status_id";
                $params[':status_id'] = $filters['status_id'];
            }
            
            if (!empty($filters['session_id'])) {
                $sql .= " AND r.session_id = :session_id";
                $params[':session_id'] = $filters['session_id'];
            }
            
            if (!empty($filters['customer_id'])) {
                $sql .= " AND r.customer_id = :customer_id";
                $params[':customer_id'] = $filters['customer_id'];
            }
            
            $sql .= " ORDER BY r.created_at DESC";
            
            $stmt = $this->db->query($sql, $params);
            return $stmt->fetchAll();
        } catch (\Exception $e) {
            // If table doesn't exist yet, return empty array
            error_log("Error getting reservations: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Return all reservations that belong to the same order group as the given reservation ID.
     * Uses the same group key: session_id + customer_id + event_id + event_date.
     * Returns full detail rows (same joins as getAll()).
     */
    public function getGroupOf(int $reservationId): array
    {
        $reservation = $this->getById($reservationId);
        if (!$reservation) {
            return [];
        }

        $filters = [
            'session_id' => $reservation['session_id'],
            'event_id'   => $reservation['event_id'],
            'event_date' => $reservation['event_date'],
        ];
        if (!empty($reservation['customer_id'])) {
            $filters['customer_id'] = $reservation['customer_id'];
        }

        // getAll() already applies all four filters; result sorted by created_at ASC
        $all = $this->getAll($filters);

        // For Barverkauf (customer_id = NULL) limit strictly to same session
        // (getAll already filters by session_id, so no extra step needed)
        return $all;
    }

    /**
     * Get reservations grouped by order (session_id + customer_id + event_id + event_date).
     * Returns one row per order with aggregate info.
     */
    public function getGrouped(array $filters = []): array
    {
        try {
            $sql = "SELECT
                    MIN(r.id)                                          AS first_id,
                    COUNT(*)                                           AS ticket_count,
                    GROUP_CONCAT(r.id ORDER BY r.id)                  AS reservation_ids,
                    GROUP_CONCAT(r.seat_identifier ORDER BY r.id SEPARATOR ', ') AS seat_identifiers,
                    MIN(r.order_date)                                  AS order_date,
                    r.session_id,
                    r.customer_id,
                    r.event_id,
                    r.event_date,
                    -- representative status: most frequent, prefer non-cart statuses
                    (SELECT r2.status_id
                     FROM reservations r2
                     WHERE r2.session_id    = r.session_id
                       AND (r2.customer_id  = r.customer_id OR (r2.customer_id IS NULL AND r.customer_id IS NULL))
                       AND r2.event_id      = r.event_id
                       AND r2.event_date    = r.event_date
                     GROUP BY r2.status_id
                     ORDER BY COUNT(*) DESC
                     LIMIT 1
                    ) AS status_id,
                    e.name                AS event_name,
                    e.show_start_time     AS event_time,
                    c.first_name, c.last_name, c.email
                FROM reservations r
                LEFT JOIN events e     ON r.event_id = e.id
                LEFT JOIN customers c  ON r.customer_id = c.id
                WHERE 1=1";

            $params = [];

            if (!empty($filters['event_id'])) {
                $sql .= " AND r.event_id = :event_id";
                $params[':event_id'] = $filters['event_id'];
            }
            if (!empty($filters['event_date'])) {
                $sql .= " AND r.event_date = :event_date";
                $params[':event_date'] = $filters['event_date'];
            }
            if (isset($filters['status_id'])) {
                $sql .= " AND r.status_id = :status_id";
                $params[':status_id'] = $filters['status_id'];
            }
            if (!empty($filters['customer_id'])) {
                $sql .= " AND r.customer_id = :customer_id";
                $params[':customer_id'] = $filters['customer_id'];
            }
            // Standardansicht: nur Bestellungen zu aktiven Veranstaltungen.
            // Vergangene Veranstaltungen werden automatisch deaktiviert, ihre
            // Bestellungen sind nur über den Filter erreichbar.
            if (!empty($filters['active_events_only'])) {
                $sql .= " AND e.active = 1";
            }

            $sql .= " GROUP BY r.session_id, r.customer_id, r.event_id, r.event_date";

            if (!empty($filters['reservation_id'])) {
                $sql .= " HAVING FIND_IN_SET(:reservation_id, GROUP_CONCAT(r.id ORDER BY r.id)) > 0";
                $params[':reservation_id'] = (int)$filters['reservation_id'];
            }

            $sql .= " ORDER BY order_date DESC";

            $stmt = $this->db->query($sql, $params);
            return $stmt->fetchAll();
        } catch (\Exception $e) {
            error_log("Error getting grouped reservations: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Get reservation by ID
     */
    public function getById(int $id): ?array
    {
        $sql = "SELECT r.*, 
                e.name as event_name, e.show_start_time as event_time,
                c.first_name, c.last_name, c.email, c.street, c.house_number, c.postal_code, c.city,
                sc.name as category_name, sc.sign as category_sign, sc.price as category_price,
                rs.name as status_name,
                v.voucher_code, v.id as voucher_id
                FROM reservations r
                LEFT JOIN events e ON r.event_id = e.id
                LEFT JOIN customers c ON r.customer_id = c.id
                LEFT JOIN seat_categories sc ON r.category_id = sc.id
                LEFT JOIN reservation_statuses rs ON r.status_id = rs.id
                LEFT JOIN vouchers v ON r.voucher_id = v.id
                WHERE r.id = :id";
        
        $stmt = $this->db->query($sql, [':id' => $id]);
        $result = $stmt->fetch();
        return $result ?: null;
    }

    /**
     * Get reservations by session ID (cart)
     * Only returns items with status_id = 1 (in cart)
     */
    public function getBySession(string $sessionId): array
    {
        try {
            return $this->getAll(['session_id' => $sessionId, 'status_id' => 1]);
        } catch (\Exception $e) {
            // If table doesn't exist yet, return empty array
            error_log("Error getting reservations by session: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Get all reservations by session ID (including paid/reserved)
     * Used for admin/presales views
     */
    public function getAllBySession(string $sessionId): array
    {
        try {
            return $this->getAll(['session_id' => $sessionId]);
        } catch (\Exception $e) {
            // If table doesn't exist yet, return empty array
            error_log("Error getting all reservations by session: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Check if seat is available
     * A seat is not available if:
     * - It's paid/reserved/box-office-printed (status_id >= 2)
     * - It's in someone's cart (status_id = 1) and the reservation is less than 15 minutes old
     */
    public function isSeatAvailable(int $eventId, string $eventDate, string $seatIdentifier): bool
    {
        $sql = "SELECT COUNT(*) as count FROM reservations
                WHERE event_id = :event_id
                AND event_date = :event_date
                AND seat_identifier = :seat_identifier
                AND (
                    status_id >= 2
                    OR (status_id = 1 AND created_at > DATE_SUB(NOW(), INTERVAL " . self::CART_LIFETIME_MINUTES . " MINUTE))
                )";
        
        $stmt = $this->db->query($sql, [
            ':event_id' => $eventId,
            ':event_date' => $eventDate,
            ':seat_identifier' => $seatIdentifier
        ]);
        
        $result = $stmt->fetch();
        return ($result['count'] ?? 0) === 0;
    }

    /**
     * Update an existing reservation or create a new one
     * Primarily used for presales where a seat is first reserved via AJAX
     * and then confirmed via form submit.
     */
    public function updateOrCreate(array $data): int
    {
        $connection = $this->db->getConnection();
        
        try {
            // Find existing reservation for this session AND seat
            $sql = "SELECT id FROM reservations 
                    WHERE event_id = :event_id 
                    AND event_date = :event_date 
                    AND seat_identifier = :seat_identifier
                    AND session_id = :session_id
                    LIMIT 1";
            
            $stmt = $this->db->query($sql, [
                ':event_id' => $data['event_id'],
                ':event_date' => $data['event_date'],
                ':seat_identifier' => $data['seat_identifier'],
                ':session_id' => $data['session_id']
            ]);
            
            $existing = $stmt->fetch();
            
            if ($existing) {
                // Update existing
                $reservationId = (int)$existing['id'];
                $this->update($reservationId, [
                    'customer_id' => $data['customer_id'] ?? null,
                    'category_id' => $data['category_id'] ?? null,
                    'status_id' => $data['status_id'] ?? 4,
                    'is_reduced' => $data['is_reduced'] ?? 0,
                    'is_fn_card' => $data['is_fn_card'] ?? 0,
                    'fn_card_number' => !empty($data['is_fn_card']) ? ($data['fn_card_number'] ?? null) : null,
                    'is_honor_card' => $data['is_honor_card'] ?? 0,
                    'is_companion' => $data['is_companion'] ?? 0,
                    'voucher_id' => $data['voucher_id'] ?? null
                ]);
                return $reservationId;
            } else {
                // Create new
                return $this->create($data);
            }
        } catch (\Exception $e) {
            throw $e;
        }
    }


    /**
     * Create reservation atomically (with lock to prevent race conditions)
     */
    public function create(array $data): int
    {
        $connection = $this->db->getConnection();
        
        try {
            // Start transaction
            $connection->beginTransaction();
            
            // Lock the seat to prevent concurrent access
            $lockSql = "SELECT id FROM reservations
                       WHERE event_id = :event_id
                       AND event_date = :event_date
                       AND seat_identifier = :seat_identifier
                       AND (
                           status_id >= 2
                           OR (status_id = 1 AND created_at > DATE_SUB(NOW(), INTERVAL " . self::CART_LIFETIME_MINUTES . " MINUTE))
                       )
                       FOR UPDATE";
            
            $lockStmt = $connection->prepare($lockSql);
            $lockStmt->execute([
                ':event_id' => $data['event_id'],
                ':event_date' => $data['event_date'],
                ':seat_identifier' => $data['seat_identifier']
            ]);
            
            if ($lockStmt->fetch()) {
                // Seat is already reserved
                $connection->rollBack();
                throw new \RuntimeException("Platz ist bereits reserviert");
            }
            
            // Insert reservation
            // fn_card_number only once database/migrate_fn_card_number.sql has run
            $withFnNumber = $this->hasFnCardNumberColumn();
            $sql = "INSERT INTO reservations
                    (session_id, customer_id, event_id, event_date, seat_identifier, category_id, status_id, is_reduced, is_fn_card, is_honor_card, is_companion, voucher_id" . ($withFnNumber ? ", fn_card_number" : "") . ")
                    VALUES (:session_id, :customer_id, :event_id, :event_date, :seat_identifier, :category_id, :status_id, :is_reduced, :is_fn_card, :is_honor_card, :is_companion, :voucher_id" . ($withFnNumber ? ", :fn_card_number" : "") . ")";

            $params = [
                ':session_id' => $data['session_id'],
                ':customer_id' => $data['customer_id'] ?? null,
                ':event_id' => $data['event_id'],
                ':event_date' => $data['event_date'],
                ':seat_identifier' => $data['seat_identifier'],
                ':category_id' => $data['category_id'] ?? null,
                ':status_id' => $data['status_id'] ?? 1, // Default: in cart
                ':is_reduced' => $data['is_reduced'] ?? 0,
                ':is_fn_card' => $data['is_fn_card'] ?? 0,
                ':is_honor_card' => $data['is_honor_card'] ?? 0,
                ':is_companion' => $data['is_companion'] ?? 0,
                ':voucher_id' => $data['voucher_id'] ?? null,
            ];
            if ($withFnNumber) {
                $params[':fn_card_number'] = !empty($data['is_fn_card']) && !empty($data['fn_card_number']) ? $data['fn_card_number'] : null;
            }
            $stmt = $connection->prepare($sql);
            $stmt->execute($params);
            
            $id = (int)$connection->lastInsertId();
            
            // Commit transaction
            $connection->commit();
            
            return $id;
        } catch (\Exception $e) {
            if ($connection->inTransaction()) {
                $connection->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Get reservations by voucher ID
     */
    public function getByVoucherId(int $voucherId): array
    {
        $sql = "SELECT r.*, 
                e.name as event_name, e.show_start_time as event_time,
                c.first_name, c.last_name, c.email,
                sc.name as category_name, sc.sign as category_sign, sc.price as category_price,
                rs.name as status_name,
                v.voucher_code
                FROM reservations r
                LEFT JOIN events e ON r.event_id = e.id
                LEFT JOIN customers c ON r.customer_id = c.id
                LEFT JOIN seat_categories sc ON r.category_id = sc.id
                LEFT JOIN reservation_statuses rs ON r.status_id = rs.id
                LEFT JOIN vouchers v ON r.voucher_id = v.id
                WHERE r.voucher_id = :voucher_id
                ORDER BY r.created_at DESC";
        
        $stmt = $this->db->query($sql, [':voucher_id' => $voucherId]);
        return $stmt->fetchAll();
    }

    /**
     * Update reservation
     */
    /**
     * Store the price type the customer chose per cart item at checkout ("normal" | "reduced" |
     * "fncard"). Reduced is only stored where it is allowed (settings, event, category reduced
     * price - see TicketPrice::choices()); the FN card flag itself is set by api/fncard_validate.php
     * after the card number check, so "fncard" only clears the reduced flag here.
     *
     * @param array $cartItems Reservation rows of the current session
     * @param array $types     reservation id => price type
     * @return int Number of changed reservations
     */
    public function applyCustomerPriceTypes(array $cartItems, array $types): int
    {
        $changed = 0;
        foreach ($cartItems as $item) {
            $id = (int)$item['id'];
            if (!isset($types[$id]) && !isset($types[(string)$id])) {
                continue;
            }
            $type = (string)($types[$id] ?? $types[(string)$id]);
            $choices = \App\Helpers\TicketPrice::choices($item, (int)$item['event_id'], (string)$item['event_date']);
            $isReduced = ($type === 'reduced' && isset($choices[\App\Helpers\TicketPrice::TYPE_REDUCED])) ? 1 : 0;
            $data = [];
            if ((int)($item['is_reduced'] ?? 0) !== $isReduced) {
                $data['is_reduced'] = $isReduced;
            }
            // Another price type than FN card: drop a previously validated card (frees its quota)
            if ($type !== 'fncard' && !empty($item['is_fn_card'])) {
                $data['is_fn_card'] = 0;
                $data['fn_card_number'] = null;
            }
            if ($data) {
                $this->update($id, $data);
                $changed++;
            }
        }
        return $changed;
    }

    /**
     * Cart items for which the customer chose FN card but whose card number was not validated
     * (api/fncard_validate.php sets is_fn_card only after the check). Such an order must not be
     * paid or submitted - it would silently be charged at the normal price.
     *
     * @return int[] reservation ids
     */
    public function unvalidatedFnCardItems(array $cartItems, array $types): array
    {
        $ids = [];
        foreach ($cartItems as $item) {
            $id = (int)$item['id'];
            $type = (string)($types[$id] ?? $types[(string)$id] ?? '');
            if ($type === 'fncard' && empty($item['is_fn_card'])) {
                $ids[] = $id;
            }
        }
        return $ids;
    }

    public function update(int $id, array $data): bool
    {
        $setClause = [];
        $params = [':id' => $id];
        
        if (isset($data['customer_id'])) {
            $setClause[] = "customer_id = :customer_id";
            $params[':customer_id'] = $data['customer_id'];
        }
        if (isset($data['category_id'])) {
            $setClause[] = "category_id = :category_id";
            $params[':category_id'] = $data['category_id'];
        }
        if (isset($data['status_id'])) {
            $setClause[] = "status_id = :status_id";
            $params[':status_id'] = $data['status_id'];
        }
        if (isset($data['is_reduced'])) {
            $setClause[] = "is_reduced = :is_reduced";
            $params[':is_reduced'] = $data['is_reduced'];
        }
        if (isset($data['is_fn_card'])) {
            $setClause[] = "is_fn_card = :is_fn_card";
            $params[':is_fn_card'] = $data['is_fn_card'];
        }
        if (array_key_exists('fn_card_number', $data) && $this->hasFnCardNumberColumn()) {
            $setClause[] = "fn_card_number = :fn_card_number";
            $params[':fn_card_number'] = $data['fn_card_number'] !== '' ? $data['fn_card_number'] : null;
        }
        if (isset($data['is_honor_card'])) {
            $setClause[] = "is_honor_card = :is_honor_card";
            $params[':is_honor_card'] = $data['is_honor_card'];
        }
        if (isset($data['is_companion'])) {
            $setClause[] = "is_companion = :is_companion";
            $params[':is_companion'] = $data['is_companion'];
        }
        if (isset($data['voucher_id'])) {
            $setClause[] = "voucher_id = :voucher_id";
            $params[':voucher_id'] = $data['voucher_id'];
        }
        
        if (empty($setClause)) {
            return false; // No fields to update
        }
        
        $setClause[] = "updated_at = NOW()";
        $sql = "UPDATE reservations SET " . implode(', ', $setClause) . " WHERE id = :id";
        
        $this->db->query($sql, $params);

        return true;
    }
    
    /** @var bool|null Whether reservations.fn_card_number exists (database/migrate_fn_card_number.sql) */
    private static ?bool $fnCardNumberColumn = null;

    /**
     * Whether the fn_card_number column exists. Until the migration has run, the number is
     * simply not stored, so selling keeps working on installations that are not migrated yet.
     */
    public function hasFnCardNumberColumn(): bool
    {
        if (self::$fnCardNumberColumn === null) {
            self::$fnCardNumberColumn = (bool)$this->db->query("SHOW COLUMNS FROM reservations LIKE 'fn_card_number'")->fetch();
        }
        return self::$fnCardNumberColumn;
    }

    /**
     * Number of active tickets (paid/reserved, or in a cart that has not expired) of an event
     * that were bought with this FN card number. Used for the per-card limit (setting max_fn_card).
     *
     * @param int|null $excludeReservationId Reservation being (re)assigned, not counted
     */
    public function getFnCardCount(string $fnCardNumber, int $eventId, ?int $excludeReservationId = null): int
    {
        if (!$this->hasFnCardNumberColumn()) {
            return 0; // without stored numbers there is nothing to count per card
        }
        $sql = "SELECT COUNT(*) AS count FROM reservations
                WHERE event_id = :event_id
                AND fn_card_number = :fn_card_number
                AND (status_id >= 2 OR (status_id = 1 AND created_at > DATE_SUB(NOW(), INTERVAL " . self::CART_LIFETIME_MINUTES . " MINUTE)))
                AND id <> :exclude_id";
        $result = $this->db->query($sql, [
            ':event_id' => $eventId,
            ':fn_card_number' => $fnCardNumber,
            ':exclude_id' => $excludeReservationId ?? 0,
        ])->fetch();
        return (int)($result['count'] ?? 0);
    }

    /** FN card numbers are 8 digits (same check as in the old system and api/fncard_validate.php) */
    public static function isValidFnCardNumber(string $number): bool
    {
        return (bool)preg_match('/^\d{8}$/', $number);
    }

    /**
     * Rebook one or more reservations to new seats and/or dates.
     * All entries must belong to the same event. Category must match.
     * Performed atomically in a single database transaction.
     *
     * @param array $rebookings Array of ['reservation_id' => int, 'new_seat_identifier' => string, 'new_event_date' => string]
     * @return array Array of ['reservation_id', 'old_date', 'old_seat', 'new_date', 'new_seat'] for logging
     */
    public function rebook(array $rebookings): array
    {
        $connection = $this->db->getConnection();
        $seatPlanModel = new \App\Models\SeatPlan();

        try {
            $connection->beginTransaction();

            $results = [];
            $eventId = null;

            // Build a category map from seatplan structure (lazy, once per event)
            $categoryMap = null;

            foreach ($rebookings as $entry) {
                $resId = (int)$entry['reservation_id'];
                $newSeat = $entry['new_seat_identifier'];
                $newDate = $entry['new_event_date'];

                // Lock and fetch the existing reservation
                $lockSql = "SELECT id, event_id, event_date, seat_identifier, category_id, status_id
                            FROM reservations WHERE id = :id FOR UPDATE";
                $lockStmt = $connection->prepare($lockSql);
                $lockStmt->execute([':id' => $resId]);
                $reservation = $lockStmt->fetch(\PDO::FETCH_ASSOC);

                if (!$reservation) {
                    throw new \RuntimeException("Reservierung #$resId nicht gefunden.");
                }

                // Must be an active reservation (not cart, not box office print)
                $statusId = (int)$reservation['status_id'];
                if ($statusId < 2 || $statusId === 7) {
                    throw new \RuntimeException("Reservierung #$resId kann nicht umgebucht werden (Status: $statusId).");
                }

                // All must be same event
                if ($eventId === null) {
                    $eventId = (int)$reservation['event_id'];
                } elseif ((int)$reservation['event_id'] !== $eventId) {
                    throw new \RuntimeException("Alle Reservierungen müssen zur gleichen Veranstaltung gehören.");
                }

                // Check if target seat is available (exclude the current reservation itself)
                $availSql = "SELECT id FROM reservations
                             WHERE event_id = :event_id
                             AND event_date = :event_date
                             AND seat_identifier = :seat_identifier
                             AND id != :exclude_id
                             AND (
                                 status_id >= 2
                                 OR (status_id = 1 AND created_at > DATE_SUB(NOW(), INTERVAL " . self::CART_LIFETIME_MINUTES . " MINUTE))
                             )
                             FOR UPDATE";
                $availStmt = $connection->prepare($availSql);
                $availStmt->execute([
                    ':event_id' => $eventId,
                    ':event_date' => $newDate,
                    ':seat_identifier' => $newSeat,
                    ':exclude_id' => $resId,
                ]);

                if ($availStmt->fetch()) {
                    $displayName = $seatPlanModel->getSeatDisplayName($eventId, $newSeat);
                    throw new \RuntimeException("Platz \"$displayName\" ist am $newDate bereits belegt.");
                }

                // Validate category match (skip for no-seatplan events)
                if (!str_starts_with($newSeat, 'no_seatplan_')) {
                    if ($categoryMap === null) {
                        $categoryMap = $this->buildCategoryMap($seatPlanModel, $eventId);
                    }

                    $targetCategory = $categoryMap[$newSeat] ?? null;
                    if ($targetCategory === null) {
                        throw new \RuntimeException("Platz \"$newSeat\" nicht im Sitzplan gefunden.");
                    }
                    if ((int)$targetCategory !== (int)$reservation['category_id']) {
                        $displayName = $seatPlanModel->getSeatDisplayName($eventId, $newSeat);
                        throw new \RuntimeException("Platz \"$displayName\" hat eine andere Kategorie. Umbuchung nur innerhalb der gleichen Kategorie möglich.");
                    }
                }

                // Perform the update
                $updateSql = "UPDATE reservations
                              SET event_date = :new_date, seat_identifier = :new_seat, updated_at = NOW()
                              WHERE id = :id";
                $updateStmt = $connection->prepare($updateSql);
                $updateStmt->execute([
                    ':new_date' => $newDate,
                    ':new_seat' => $newSeat,
                    ':id' => $resId,
                ]);

                $results[] = [
                    'reservation_id' => $resId,
                    'old_date' => $reservation['event_date'],
                    'old_seat' => $reservation['seat_identifier'],
                    'new_date' => $newDate,
                    'new_seat' => $newSeat,
                ];
            }

            $connection->commit();
            return $results;

        } catch (\Exception $e) {
            if ($connection->inTransaction()) {
                $connection->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Build a map of seat_identifier => category_id from the seatplan structure.
     */
    private function buildCategoryMap(\App\Models\SeatPlan $seatPlanModel, int $eventId): array
    {
        $structure = $seatPlanModel->getSeatPlanStructure($eventId);
        $map = [];

        foreach ($structure as $row) {
            $rowCategory = $row['category'] ?? null;
            foreach ($row['content'] as $element) {
                if ($element['type'] === 'seat') {
                    $identifier = 'seat_' . $row['no'] . '_' . $element['no'];
                    $map[$identifier] = $element['category'] ?? $rowCategory;
                } elseif ($element['type'] === 'table') {
                    $tableNo = $element['no'];
                    // Table itself
                    $map['table_' . $tableNo] = $rowCategory;
                    // Table seats
                    if (isset($element['seats'])) {
                        foreach ($element['seats'] as $area => $seats) {
                            foreach ($seats as $seat) {
                                $map['tableseat_' . $tableNo . '_' . $seat['number']] = $seat['category'] ?? $rowCategory;
                            }
                        }
                    }
                }
            }
        }

        return $map;
    }

    /**
     * Restart the reservation clock for all cart items (status_id = 1) of a session.
     *
     * created_at drives both the seat block and the cleanup. Without this the clock
     * keeps running from the moment of seat selection, so a customer who spends
     * longer than CART_LIFETIME_MINUTES in checkout or the PayPal flow has their
     * seats deleted mid-payment by a cleanup triggered from someone else's request.
     * Call this whenever the customer shows they are still active.
     *
     * A row is only refreshed when its seat is still free. Refreshing blindly would
     * revive a row whose seat another session legitimately took over in the
     * meantime, producing two blocking rows for one seat. Such rows are dropped
     * instead, and their seats returned in 'conflicts' so the caller can tell the
     * customer. Uses the same FOR UPDATE lock as create() to stay race-free.
     *
     * Returns ['refreshed' => int, 'conflicts' => array<int, array{seat, event_id, event_date}>].
     */
    public function touchCartItems(string $sessionId): array
    {
        $ownItems = $this->db->query(
            "SELECT id, event_id, event_date, seat_identifier
             FROM reservations
             WHERE session_id = :session_id
             AND status_id = 1",
            [':session_id' => $sessionId]
        )->fetchAll();

        if (empty($ownItems)) {
            return ['refreshed' => 0, 'conflicts' => []];
        }

        $connection = $this->db->getConnection();
        $refreshed  = 0;
        $conflicts  = [];

        // Join an outer transaction rather than opening a nested one, which PDO
        // rejects. When we did not start it, the outer owner also commits it.
        $ownsTransaction = !$connection->inTransaction();
        if ($ownsTransaction) {
            $connection->beginTransaction();
        }

        try {
            $lockStmt = $connection->prepare(
                "SELECT id FROM reservations
                 WHERE event_id = :event_id
                 AND event_date = :event_date
                 AND seat_identifier = :seat_identifier
                 AND session_id != :session_id
                 AND (
                     status_id >= 2
                     OR (status_id = 1 AND created_at > DATE_SUB(NOW(), INTERVAL " . self::CART_LIFETIME_MINUTES . " MINUTE))
                 )
                 FOR UPDATE"
            );
            $dropStmt  = $connection->prepare("DELETE FROM reservations WHERE id = :id");
            $touchStmt = $connection->prepare("UPDATE reservations SET created_at = NOW() WHERE id = :id");

            foreach ($ownItems as $item) {
                $lockStmt->execute([
                    ':event_id'        => $item['event_id'],
                    ':event_date'      => $item['event_date'],
                    ':seat_identifier' => $item['seat_identifier'],
                    ':session_id'      => $sessionId,
                ]);
                $takenByOther = (bool)$lockStmt->fetch();
                $lockStmt->closeCursor();

                if ($takenByOther) {
                    $dropStmt->execute([':id' => $item['id']]);
                    $conflicts[] = [
                        'seat'       => $item['seat_identifier'],
                        'event_id'   => (int)$item['event_id'],
                        'event_date' => $item['event_date'],
                    ];
                    continue;
                }

                $touchStmt->execute([':id' => $item['id']]);
                $refreshed++;
            }

            if ($ownsTransaction) {
                $connection->commit();
            }
        } catch (\Throwable $e) {
            if ($ownsTransaction) {
                $connection->rollBack();
            }
            throw $e;
        }

        // Log outside the transaction so logging never holds row locks.
        foreach ($conflicts as $conflict) {
            \App\Services\LoggerService::logSeatsReleased(
                $sessionId, $conflict['event_id'], $conflict['event_date'],
                [$conflict['seat']], null, 'seat_taken_by_other_session'
            );
        }

        return ['refreshed' => $refreshed, 'conflicts' => $conflicts];
    }

    /**
     * Delete all cart reservations (status_id = 1) older than CART_LIFETIME_MINUTES.
     * Also cleans up expired vouchers in cart (status_id = 1).
     * Logs each released seat and voucher via LoggerService before deleting.
     * Returns number of deleted reservation rows.
     */
    public function cleanupExpiredCartItems(): int
    {
        // Fetch before deleting so we can log seat identifiers
        $rows = $this->db->query(
            "SELECT id, session_id, event_id, event_date, seat_identifier
             FROM reservations
             WHERE status_id = 1
             AND created_at < DATE_SUB(NOW(), INTERVAL " . self::CART_LIFETIME_MINUTES . " MINUTE)"
        )->fetchAll();

        $stmt = $this->db->query(
            "DELETE FROM reservations
             WHERE status_id = 1
             AND created_at < DATE_SUB(NOW(), INTERVAL " . self::CART_LIFETIME_MINUTES . " MINUTE)"
        );
        $deleted = (int)$stmt->rowCount();

        if ($deleted > 0 && !empty($rows)) {
            // Group by session+event for compact log entries
            $groups = [];
            foreach ($rows as $r) {
                $key = $r['session_id'] . '|' . $r['event_id'] . '|' . $r['event_date'];
                $groups[$key][] = $r['seat_identifier'];
            }
            foreach ($groups as $key => $seats) {
                [$sessionId, $eventId, $eventDate] = explode('|', $key, 3);
                \App\Services\LoggerService::logSeatsReleased(
                    $sessionId, (int)$eventId, $eventDate, $seats, null, 'presales_cleanup'
                );
            }
        }

        // Clean up expired vouchers in cart (status_id = 1, updated_at older than 20 min)
        $expiredVouchers = $this->db->query(
            "SELECT id, voucher_code, purchase_intent, session_id FROM vouchers
             WHERE status_id = 1
             AND updated_at < DATE_SUB(NOW(), INTERVAL " . self::CART_LIFETIME_MINUTES . " MINUTE)"
        )->fetchAll();

        $voucherModel = new \App\Models\Voucher();
        foreach ($expiredVouchers as $ev) {
            $evCode = $ev['voucher_code'] ?? (string)$ev['id'];
            if (($ev['purchase_intent'] ?? 'buy') === 'buy') {
                // Delete vouchers that were being purchased
                $voucherModel->delete((int)$ev['id']);
                if (function_exists('logVoucherStatusChange')) {
                    logVoucherStatusChange('customer', $evCode, 1, null, 'presales_cleanup', [
                        'voucher_id' => $ev['id'],
                        'session_id' => substr($ev['session_id'] ?? '', 0, 32),
                    ]);
                }
            } else {
                // Reset redeemed vouchers back to available
                $voucherModel->update((int)$ev['id'], [
                    'session_id'      => '',
                    'status_id'       => 0,
                    'purchase_intent' => 'buy',
                ]);
                if (function_exists('logVoucherStatusChange')) {
                    logVoucherStatusChange('customer', $evCode, 1, 0, 'presales_cleanup', [
                        'voucher_id' => $ev['id'],
                        'session_id' => substr($ev['session_id'] ?? '', 0, 32),
                    ]);
                }
            }
        }

        return $deleted;
    }

    /**
     * Delete reservation
     */
    public function delete(int $id): bool
    {
        $this->db->query("DELETE FROM reservations WHERE id = :id", [':id' => $id]);
        return true;
    }

    /**
     * Update status
     */
    public function updateStatus(int $id, int $statusId): bool
    {
        $this->db->query(
            "UPDATE reservations SET status_id = :status_id, updated_at = NOW() WHERE id = :id",
            [':id' => $id, ':status_id' => $statusId]
        );
        return true;
    }

    /**
     * Get reserved seats for an event date
     * Includes both permanently reserved (status >= 2) and temporarily reserved (status = 1, < 15 min old)
     */
    public function getReservedSeats(int $eventId, string $eventDate): array
    {
        $sql = "SELECT seat_identifier, status_id FROM reservations
                WHERE event_id = :event_id
                AND event_date = :event_date
                AND (
                    status_id >= 2
                    OR (status_id = 1 AND created_at > DATE_SUB(NOW(), INTERVAL " . self::CART_LIFETIME_MINUTES . " MINUTE))
                )";

        $stmt = $this->db->query($sql, [
            ':event_id' => $eventId,
            ':event_date' => $eventDate
        ]);

        $reserved = [];
        while ($row = $stmt->fetch()) {
            $reserved[$row['seat_identifier']] = $row['status_id'];
        }

        return $reserved;
    }

    /**
     * Get all reserved/occupied seats for a given event+date including customer and status details.
     * Returns an array indexed by seat_identifier, each entry containing reservation details.
     * Includes all non-cancelled reservations (status_id >= 2 and cart entries still within 15 min).
     */
    public function getReservedSeatsWithDetails(int $eventId, string $eventDate): array
    {
        $sql = "SELECT r.id, r.seat_identifier, r.status_id, r.is_companion,
                       rs.name as status_name,
                       r.created_at,
                       sc.name as category_name, sc.price as category_price,
                       c.first_name, c.last_name, c.email
                FROM reservations r
                LEFT JOIN reservation_statuses rs ON r.status_id = rs.id
                LEFT JOIN seat_categories sc ON r.category_id = sc.id
                LEFT JOIN customers c ON r.customer_id = c.id
                WHERE r.event_id = :event_id
                AND r.event_date = :event_date
                AND (
                    r.status_id >= 2
                    OR (r.status_id = 1 AND r.created_at > DATE_SUB(NOW(), INTERVAL " . self::CART_LIFETIME_MINUTES . " MINUTE))
                )
                ORDER BY r.created_at ASC";

        $stmt = $this->db->query($sql, [
            ':event_id'  => $eventId,
            ':event_date' => $eventDate,
        ]);

        $result = [];
        while ($row = $stmt->fetch()) {
            $result[$row['seat_identifier']] = $row;
        }
        return $result;
    }
}

