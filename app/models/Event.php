<?php
/**
 * Event Model
 */

namespace App\Models;

use App\Core\Database;

class Event
{
    private Database $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    /**
     * SQL-Bedingung: Veranstaltung ist vorüber, d.h. sie hat mindestens einen
     * Termin und alle Termine liegen vor dem heutigen Tag. Veranstaltungen ohne
     * Termine (z.B. reine Gutschein-Veranstaltungen) gelten nie als vorüber.
     *
     * `$alias` ist der Tabellen-Alias der events-Tabelle im umgebenden Query.
     */
    private static function finishedCondition(string $alias = 'e'): string
    {
        return "EXISTS (SELECT 1 FROM event_dates d WHERE d.event_id = {$alias}.id)
                AND NOT EXISTS (SELECT 1 FROM event_dates d WHERE d.event_id = {$alias}.id AND d.date >= CURDATE())";
    }

    /**
     * Get all events
     *
     * @param bool $activeOnly       Nur aktive Veranstaltungen
     * @param bool $excludeFinished  Vorüber gegangene Veranstaltungen ausblenden
     *                               (für den Kundenbereich)
     */
    public function getAll(bool $activeOnly = false, bool $excludeFinished = false): array
    {
        $sql = "SELECT e.*, (" . self::finishedCondition('e') . ") AS is_finished FROM events e";
        $conditions = [];
        if ($activeOnly) {
            $conditions[] = "e.active = 1";
        }
        if ($excludeFinished) {
            $conditions[] = "NOT (" . self::finishedCondition('e') . ")";
        }
        if ($conditions) {
            $sql .= " WHERE " . implode(' AND ', $conditions);
        }
        $sql .= " ORDER BY e.created_at DESC";

        $stmt = $this->db->query($sql);
        return $stmt->fetchAll();
    }

    /**
     * Letzter Termin einer Veranstaltung (oder null, wenn keine Termine existieren).
     */
    public function getLastDate(int $eventId): ?string
    {
        $stmt = $this->db->query(
            "SELECT MAX(date) AS last_date FROM event_dates WHERE event_id = :event_id",
            [':event_id' => $eventId]
        );
        $lastDate = $stmt->fetch()['last_date'] ?? null;

        return $lastDate ?: null;
    }

    /**
     * Ist die Veranstaltung vorüber? Wahr, sobald alle Termine vor dem heutigen
     * Tag liegen. Am Veranstaltungstag selbst bleibt sie buchbar – die
     * Bestellfrist regelt dort isEventDateBookable().
     *
     * Veranstaltungen ohne Termine gelten nicht als vorüber.
     */
    public function isFinished(int $eventId): bool
    {
        $lastDate = $this->getLastDate($eventId);
        if ($lastDate === null) {
            return false;
        }

        return $lastDate < date('Y-m-d');
    }

    /**
     * Deaktiviert automatisch alle aktiven Veranstaltungen, die vorüber sind.
     * Wird vom Cron-Skript aufgerufen (cleanup_expired_reservations.php).
     *
     * @return array<int, array{id:int, name:string, last_date:string}> deaktivierte Veranstaltungen
     */
    public function deactivateFinishedEvents(): array
    {
        $stmt = $this->db->query(
            "SELECT e.id, e.name,
                    (SELECT MAX(d.date) FROM event_dates d WHERE d.event_id = e.id) AS last_date
             FROM events e
             WHERE e.active = 1 AND " . self::finishedCondition('e')
        );
        $finished = $stmt->fetchAll();

        foreach ($finished as $event) {
            $this->db->query(
                "UPDATE events SET active = 0, updated_at = NOW() WHERE id = :id",
                [':id' => (int)$event['id']]
            );
        }

        return array_map(static fn(array $event): array => [
            'id'        => (int)$event['id'],
            'name'      => (string)$event['name'],
            'last_date' => (string)$event['last_date'],
        ], $finished);
    }

    /**
     * Get event by ID
     */
    public function getById(int $id): ?array
    {
        $stmt = $this->db->query(
            "SELECT e.*, (" . self::finishedCondition('e') . ") AS is_finished FROM events e WHERE e.id = :id",
            [':id' => $id]
        );
        $result = $stmt->fetch();
        return $result ?: null;
    }

    /**
     * Create new event
     */
    public function create(array $data): int
    {
        $sql = "INSERT INTO events (name, active, sell_tickets, sell_vouchers, allow_reduced_prices, allow_fn_card, use_seat_plan, max_seats, seat_category_id, email_ticket_subject, email_ticket_body, show_start_time, max_reservation_time, reservation_phone, voucher_use_template, voucher_template, voucher_template_config)
                VALUES (:name, :active, :sell_tickets, :sell_vouchers, :allow_reduced_prices, :allow_fn_card, :use_seat_plan, :max_seats, :seat_category_id, :email_ticket_subject, :email_ticket_body, :show_start_time, :max_reservation_time, :reservation_phone, :voucher_use_template, :voucher_template, :voucher_template_config)";
        
        $this->db->query($sql, [
            ':name' => $data['name'],
            ':active' => $data['active'] ?? 0,
            ':sell_tickets' => $data['sell_tickets'] ?? 0,
            ':sell_vouchers' => $data['sell_vouchers'] ?? 0,
            ':allow_reduced_prices' => $data['allow_reduced_prices'] ?? 0,
            ':allow_fn_card' => $data['allow_fn_card'] ?? 0,
            ':use_seat_plan' => $data['use_seat_plan'] ?? 1,
            ':max_seats' => isset($data['max_seats']) && $data['max_seats'] !== '' ? (int)$data['max_seats'] : null,
            ':seat_category_id' => isset($data['seat_category_id']) && $data['seat_category_id'] !== '' ? (int)$data['seat_category_id'] : null,
            ':email_ticket_subject' => $data['email_ticket_subject'] ?? null,
            ':email_ticket_body' => $data['email_ticket_body'] ?? null,
            ':show_start_time' => $data['show_start_time'] ?? '20:30:00',
            ':max_reservation_time' => $data['max_reservation_time'] ?? '12:00:00',
            ':reservation_phone' => $data['reservation_phone'] ?? null,
            ':voucher_use_template' => $data['voucher_use_template'] ?? 0,
            ':voucher_template' => $data['voucher_template'] ?? null,
            ':voucher_template_config' => isset($data['voucher_template_config']) && !is_string($data['voucher_template_config']) ? json_encode($data['voucher_template_config']) : ($data['voucher_template_config'] ?? null),
        ]);

        return (int)$this->db->lastInsertId();
    }

    /**
     * Update event
     */
    public function update(int $id, array $data): bool
    {
        $allowedFields = [
            'name',
            'active',
            'sell_tickets',
            'sell_vouchers',
            'allow_reduced_prices',
            'allow_fn_card',
            'use_seat_plan',
            'max_seats',
            'seat_category_id',
            'email_ticket_subject',
            'email_ticket_body',
            'show_start_time',
            'max_reservation_time',
            'reservation_phone',
            'alt_location_name',
            'ticket_code_type',
            'ticket_code_content',
            'ticket_pdf_template',
            'ticket_template_config',
            'presales_ticket_pdf_template',
            'presales_ticket_template_config',
            'voucher_use_template',
            'voucher_template',
            'voucher_template_config',
        ];
        
        $setClause = [];
        $params = [':id' => $id];
        
        foreach ($allowedFields as $field) {
            if (isset($data[$field])) {
                $placeholder = ':' . $field;
                $setClause[] = "$field = $placeholder";
                
                if (str_ends_with($field, '_template_config') && !is_string($data[$field])) {
                    $params[$placeholder] = json_encode($data[$field]);
                } elseif ($field === 'max_seats' || $field === 'seat_category_id') {
                    // Handle nullable integer fields
                    $params[$placeholder] = ($data[$field] === '' || $data[$field] === null) ? null : (int)$data[$field];
                } else {
                    $params[$placeholder] = $data[$field];
                }
            }
        }
        
        if (empty($setClause)) {
            return false; // Nothing to update
        }
        
        $setClause[] = "updated_at = NOW()";
        $sql = "UPDATE events SET " . implode(', ', $setClause) . " WHERE id = :id";
        
        $this->db->query($sql, $params);

        return true;
    }

    /**
     * Delete event
     */
    public function delete(int $id): bool
    {
        $this->db->query("DELETE FROM events WHERE id = :id", [':id' => $id]);
        return true;
    }

    /**
     * Get event dates
     */
    public function getDates(int $eventId): array
    {
        $stmt = $this->db->query(
            "SELECT * FROM event_dates WHERE event_id = :event_id ORDER BY date ASC",
            [':event_id' => $eventId]
        );
        return $stmt->fetchAll();
    }

    /**
     * Total bookable seat count from the seat plan.
     * Counts regular seats and wheelchair seats; table container elements
     * ('table') are not bookable seats and are excluded.
     */
    public function getSeatplanSeatCount(int $eventId): int
    {
        $stmt = $this->db->query(
            "SELECT COUNT(*) AS cnt
             FROM seat_plan_elements spe
             INNER JOIN seat_plan_rows spr ON spe.row_id = spr.id
             WHERE spr.event_id = :event_id
               AND spe.element_type IN ('seat', 'wheelchair')",
            [':event_id' => $eventId]
        );
        return (int)$stmt->fetchColumn();
    }

    /**
     * Event capacity used for occupancy figures ("Auslastung").
     * Rule: use the seat plan's seat count when the event has a seat plan,
     * otherwise fall back to the manually configured max_seats.
     * Returns null when neither is available.
     */
    public function getCapacity(array $event): ?int
    {
        if (!empty($event['use_seat_plan'])) {
            $seats = $this->getSeatplanSeatCount((int)$event['id']);
            if ($seats > 0) {
                return $seats;
            }
        }
        return isset($event['max_seats']) && $event['max_seats'] !== null && $event['max_seats'] !== ''
            ? (int)$event['max_seats']
            : null;
    }

    /**
     * Add event date
     */
    public function addDate(int $eventId, string $date, ?string $showStartTime = null, bool $isGeneralRehearsal = false): int
    {
        $sql = "INSERT INTO event_dates (event_id, date, show_start_time, is_general_rehearsal) VALUES (:event_id, :date, :show_start_time, :is_general_rehearsal)";
        $this->db->query($sql, [
            ':event_id' => $eventId,
            ':date' => $date,
            ':show_start_time' => $showStartTime,
            ':is_general_rehearsal' => $isGeneralRehearsal ? 1 : 0
        ]);
        return (int)$this->db->lastInsertId();
    }
    
    /**
     * Update event date
     */
    public function updateDate(int $dateId, array $data): bool
    {
        $allowedFields = ['date', 'show_start_time', 'is_general_rehearsal'];
        $setClause = [];
        $params = [':id' => $dateId];
        
        foreach ($allowedFields as $field) {
            if (isset($data[$field])) {
                $placeholder = ':' . $field;
                $setClause[] = "$field = $placeholder";
                // Convert boolean to int for database
                if ($field === 'is_general_rehearsal') {
                    $params[$placeholder] = $data[$field] ? 1 : 0;
                } else {
                    $params[$placeholder] = $data[$field];
                }
            }
        }
        
        if (empty($setClause)) {
            return false;
        }
        
        $sql = "UPDATE event_dates SET " . implode(', ', $setClause) . " WHERE id = :id";
        $this->db->query($sql, $params);
        return true;
    }
    
    /**
     * Get event date by ID
     */
    public function getDateById(int $dateId): ?array
    {
        $stmt = $this->db->query("SELECT * FROM event_dates WHERE id = :id", [':id' => $dateId]);
        $result = $stmt->fetch();
        return $result ?: null;
    }
    
    /**
     * Get event date by event_id and date
     */
    public function getDateByEventAndDate(int $eventId, string $date): ?array
    {
        $stmt = $this->db->query(
            "SELECT * FROM event_dates WHERE event_id = :event_id AND date = :date",
            [':event_id' => $eventId, ':date' => $date]
        );
        $result = $stmt->fetch();
        return $result ?: null;
    }

    /**
     * Remove event date
     */
    public function removeDate(int $dateId): bool
    {
        $this->db->query("DELETE FROM event_dates WHERE id = :id", [':id' => $dateId]);
        return true;
    }
}

