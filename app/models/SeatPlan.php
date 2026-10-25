<?php
/**
 * Seat Plan Model
 */

namespace App\Models;

use App\Core\Database;

class SeatPlan
{
    private Database $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    /**
     * Get all rows for an event
     */
    public function getRows(int $eventId): array
    {
        $sql = "SELECT spr.*, sc.name as category_name, sc.sign as category_sign, sc.color as category_color
                FROM seat_plan_rows spr
                LEFT JOIN seat_categories sc ON spr.category_id = sc.id
                WHERE spr.event_id = :event_id
                ORDER BY spr.display_order ASC, spr.row_number ASC";
        
        $stmt = $this->db->query($sql, [':event_id' => $eventId]);
        $rows = $stmt->fetchAll();
        
        // Add display name for each row
        foreach ($rows as &$row) {
            $row['display_name'] = $row['row_name'] ?: "Reihe " . $row['row_number'];
        }
        
        return $rows;
    }

    /**
     * Get row with elements
     */
    public function getRowWithElements(int $rowId): ?array
    {
        $sql = "SELECT * FROM seat_plan_rows WHERE id = :id";
        $stmt = $this->db->query($sql, [':id' => $rowId]);
        $row = $stmt->fetch();
        
        if (!$row) {
            return null;
        }

        $sql = "SELECT spe.*, sc.name as category_name, sc.sign as category_sign
                FROM seat_plan_elements spe
                LEFT JOIN seat_categories sc ON spe.category_id = sc.id
                WHERE spe.row_id = :row_id
                ORDER BY spe.position ASC";
        
        $stmt = $this->db->query($sql, [':row_id' => $rowId]);
        $row['elements'] = $stmt->fetchAll();
        
        return $row;
    }

    /**
     * Create or update row
     */
    public function saveRow(int $eventId, int $rowNumber, ?int $categoryId = null, int $displayOrder = 0, ?string $rowName = null): int
    {
        // Check if row exists
        $sql = "SELECT id FROM seat_plan_rows WHERE event_id = :event_id AND `row_number` = :row_number";
        $stmt = $this->db->query($sql, [
            ':event_id' => $eventId,
            ':row_number' => $rowNumber
        ]);
        $existing = $stmt->fetch();

        if ($existing) {
            // Update
            $sql = "UPDATE seat_plan_rows SET category_id = :category_id, display_order = :display_order, row_name = :row_name WHERE id = :id";
            $this->db->query($sql, [
                ':id' => $existing['id'],
                ':category_id' => $categoryId,
                ':display_order' => $displayOrder,
                ':row_name' => $rowName
            ]);
            return (int)$existing['id'];
        } else {
            // Create
            $sql = "INSERT INTO seat_plan_rows (event_id, `row_number`, category_id, display_order, row_name)
                    VALUES (:event_id, :row_number, :category_id, :display_order, :row_name)";
            $this->db->query($sql, [
                ':event_id' => $eventId,
                ':row_number' => $rowNumber,
                ':category_id' => $categoryId,
                ':display_order' => $displayOrder,
                ':row_name' => $rowName
            ]);
            return (int)$this->db->lastInsertId();
        }
    }
    
    /**
     * Update row name
     */
    public function updateRowName(int $rowId, ?string $rowName): bool
    {
        $sql = "UPDATE seat_plan_rows SET row_name = :row_name WHERE id = :id";
        $this->db->query($sql, [
            ':id' => $rowId,
            ':row_name' => $rowName
        ]);
        return true;
    }

    /**
     * Delete row and all its elements
     */
    public function deleteRow(int $rowId): bool
    {
        // Delete elements first
        $this->db->query("DELETE FROM seat_plan_elements WHERE row_id = :row_id", [':row_id' => $rowId]);
        // Delete row
        $this->db->query("DELETE FROM seat_plan_rows WHERE id = :row_id", [':row_id' => $rowId]);
        return true;
    }

    /** @var bool|null Whether seat_plan_elements.is_stairs exists (database/migrate_stairs.sql) */
    private static ?bool $stairsColumn = null;

    /** Until the migration has run, distances simply cannot be marked as stairs. */
    public function hasStairsColumn(): bool
    {
        if (self::$stairsColumn === null) {
            self::$stairsColumn = (bool)$this->db->query("SHOW COLUMNS FROM seat_plan_elements LIKE 'is_stairs'")->fetch();
        }
        return self::$stairsColumn;
    }

    /**
     * Add element to row
     */
    public function addElement(int $rowId, array $data): int
    {
        $sql = "INSERT INTO seat_plan_elements
                (row_id, element_type, element_number, table_number, position, table_seat_area, table_seat_number, alt_row, alt_seat, category_id, consumes_number" . ($this->hasStairsColumn() ? ', is_stairs' : '') . ")
                VALUES (:row_id, :element_type, :element_number, :table_number, :position, :table_seat_area, :table_seat_number, :alt_row, :alt_seat, :category_id, :consumes_number" . ($this->hasStairsColumn() ? ', :is_stairs' : '') . ")";

        $this->db->query($sql, [
            ':row_id' => $rowId,
            ':element_type' => $data['element_type'],
            ':element_number' => $data['element_number'] ?? null,
            ':table_number' => $data['table_number'] ?? null,
            ':position' => $data['position'] ?? 0,
            ':table_seat_area' => $data['table_seat_area'] ?? null,
            ':table_seat_number' => $data['table_seat_number'] ?? null,
            ':alt_row' => self::normalizeAltValue($data['alt_row'] ?? null),
            ':alt_seat' => self::normalizeAltValue($data['alt_seat'] ?? null),
            ':category_id' => $data['category_id'] ?? null,
            ':consumes_number' => !empty($data['consumes_number']) ? 1 : 0,
        ] + ($this->hasStairsColumn() ? [':is_stairs' => !empty($data['is_stairs']) ? 1 : 0] : []));

        return (int)$this->db->lastInsertId();
    }

    /**
     * Update element
     */
    public function updateElement(int $elementId, array $data): bool
    {
        $sql = "UPDATE seat_plan_elements SET
                element_type = :element_type,
                element_number = :element_number,
                table_number = :table_number,
                position = :position,
                table_seat_area = :table_seat_area,
                table_seat_number = :table_seat_number,
                alt_row = :alt_row,
                alt_seat = :alt_seat,
                category_id = :category_id,
                consumes_number = :consumes_number" . ($this->hasStairsColumn() ? ',
                is_stairs = :is_stairs' : '') . "
                WHERE id = :id";

        $this->db->query($sql, [
            ':id' => $elementId,
            ':element_type' => $data['element_type'],
            ':element_number' => $data['element_number'] ?? null,
            ':table_number' => $data['table_number'] ?? null,
            ':position' => $data['position'] ?? 0,
            ':table_seat_area' => $data['table_seat_area'] ?? null,
            ':table_seat_number' => $data['table_seat_number'] ?? null,
            ':alt_row' => self::normalizeAltValue($data['alt_row'] ?? null),
            ':alt_seat' => self::normalizeAltValue($data['alt_seat'] ?? null),
            ':category_id' => $data['category_id'] ?? null,
            ':consumes_number' => !empty($data['consumes_number']) ? 1 : 0,
        ] + ($this->hasStairsColumn() ? [':is_stairs' => !empty($data['is_stairs']) ? 1 : 0] : []));

        return true;
    }

    /**
     * Update category for all seats in a row
     * This includes both regular seats and table seats
     */
    public function updateRowSeatsCategory(int $rowId, ?int $categoryId): int
    {
        // Get all seat-like elements in this row (regular seats, table seats, wheelchair seats).
        // Table seats have element_type = 'seat' and table_number IS NOT NULL.
        $sql = "SELECT id FROM seat_plan_elements
                WHERE row_id = :row_id
                AND element_type IN ('seat', 'wheelchair')";
        $stmt = $this->db->query($sql, [':row_id' => $rowId]);
        $seatElements = $stmt->fetchAll();
        
        $updatedCount = 0;
        foreach ($seatElements as $seat) {
            $updateSql = "UPDATE seat_plan_elements SET category_id = :category_id WHERE id = :id";
            $this->db->query($updateSql, [
                ':id' => $seat['id'],
                ':category_id' => $categoryId
            ]);
            $updatedCount++;
        }
        
        return $updatedCount;
    }

    /**
     * Delete element
     */
    public function deleteElement(int $elementId): bool
    {
        $this->db->query("DELETE FROM seat_plan_elements WHERE id = :id", [':id' => $elementId]);
        return true;
    }

    /**
     * Get complete seat plan structure for an event (JSON format)
     */
    public function getSeatPlanStructure(int $eventId): array
    {
        $rows = $this->getRows($eventId);
        $structure = [];

        foreach ($rows as $row) {
            $rowData = [
                'id' => $row['id'],
                'type' => 'row',
                'no' => $row['row_number'],
                'row_name' => $row['row_name'] ?? null,
                'display_name' => $row['row_name'] ?: "Reihe " . $row['row_number'],
                'category' => $row['category_id'],
                'category_name' => $row['category_name'] ?? null,
                'category_sign' => $row['category_sign'] ?? null,
                'content' => []
            ];

            // Row 0 is the stage. It has no stored elements: element_type has no 'stage' value
            // (non-strict MySQL used to store '' instead, strict mode rejects the insert).
            if ((int)$row['row_number'] === 0) {
                $rowData['content'][] = ['id' => null, 'type' => 'stage', 'no' => 1];
                $structure[] = $rowData;
                continue;
            }

            // Get elements for this row
            $elements = $this->getRowWithElements($row['id'])['elements'] ?? [];

            foreach ($elements as $element) {
                // Skip table seats - they are loaded separately with the table
                if ($element['element_type'] === 'seat' && $element['table_number'] !== null) {
                    continue;
                }
                
                $elementData = [
                    'id' => $element['id'],
                    'type' => $element['element_type'],
                    'position' => $element['position']
                ];

                if ($element['element_type'] === 'seat' || $element['element_type'] === 'wheelchair') {
                    $elementData['no'] = $element['element_number'];
                    if ($element['category_id']) {
                        $elementData['category'] = $element['category_id'];
                    }
                    $elementData['alt_row'] = $element['alt_row'] ?? null;
                    $elementData['alt_seat'] = $element['alt_seat'] ?? null;
                } elseif ($element['element_type'] === 'table') {
                    $elementData['no'] = $element['table_number'];
                    // Get table seats and structure them as seats[area] = [{number, category}, ...]
                    $tableSeats = $this->getTableSeats($element['id']);
                    if (!empty($tableSeats['left']) || !empty($tableSeats['right']) || !empty($tableSeats['back']) || !empty($tableSeats['front'])) {
                        $elementData['seats'] = $tableSeats;
                    }
                } elseif ($element['element_type'] === 'distance' || $element['element_type'] === 'spacer') {
                    $elementData['no'] = $element['element_number'] ?? $element['position'];
                    $elementData['consumes_number'] = !empty($element['consumes_number']) ? 1 : 0;
                    $elementData['stairs'] = !empty($element['is_stairs']) ? 1 : 0;
                }

                $rowData['content'][] = $elementData;
            }

            $structure[] = $rowData;
        }

        return $structure;
    }

    /**
     * Get table seats grouped by area
     */
    private function getTableSeats(int $tableElementId): array
    {
        // Get table element to find row_id and table_number
        $tableSql = "SELECT row_id, table_number FROM seat_plan_elements WHERE id = :id";
        $tableStmt = $this->db->query($tableSql, [':id' => $tableElementId]);
        $table = $tableStmt->fetch();
        
        if (!$table || !$table['table_number']) {
            return ['left' => [], 'right' => [], 'back' => [], 'front' => []];
        }
        
        // Get all seats for this table (seats with same row_id, table_number, and element_type = 'seat')
        // Order by seat number (continuous numbering across all areas)
        // Only get seats with valid seat numbers (not null)
        $sql = "SELECT * FROM seat_plan_elements 
                WHERE row_id = :row_id
                AND table_number = :table_number
                AND element_type = 'seat'
                AND table_seat_area IS NOT NULL
                AND table_seat_number IS NOT NULL
                ORDER BY table_seat_number ASC";
        
        $stmt = $this->db->query($sql, [
            ':row_id' => $table['row_id'],
            ':table_number' => $table['table_number']
        ]);
        
        $seats = $stmt->fetchAll();
        
        // Group by area (numbers are continuous across all areas)
        $result = ['left' => [], 'right' => [], 'back' => [], 'front' => []];
        
        foreach ($seats as $seat) {
            $area = $seat['table_seat_area'];
            if (in_array($area, ['left', 'right', 'back', 'front'])) {
                $result[$area][] = [
                    'number' => (int)$seat['table_seat_number'],
                    'category' => $seat['category_id'] ? (int)$seat['category_id'] : null,
                    'alt_row' => $seat['alt_row'] ?? null,
                    'alt_seat' => $seat['alt_seat'] ?? null
                ];
            }
        }
        
        return $result;
    }

    /**
     * Get table seat element IDs for a specific table (for deletion)
     */
    public function getTableSeatsForTable(int $rowId, int $tableNumber): array
    {
        $sql = "SELECT id FROM seat_plan_elements 
                WHERE row_id = :row_id
                AND table_number = :table_number
                AND element_type = 'seat'
                AND table_seat_area IS NOT NULL";
        
        $stmt = $this->db->query($sql, [
            ':row_id' => $rowId,
            ':table_number' => $tableNumber
        ]);
        
        $seats = $stmt->fetchAll();
        return array_column($seats, 'id');
    }

    /**
     * Convert seat identifier to user-friendly display name
     * Examples:
     * - seat_1_5 -> "Reihe 1, Platz 5"
     * - table_3 -> "Tisch 3"
     * - tableseat_3_2 -> "Tisch 3, Platz 2"
     */
    public function getSeatDisplayName(int $eventId, string $seatIdentifier): string
    {
        // Events ohne Sitzplan: no_seatplan_TIMESTAMP_INDEX_RANDOM oder no_seatplan_rebook_ID_TIMESTAMP
        if (str_starts_with($seatIdentifier, 'no_seatplan_')) {
            $parts = explode('_', $seatIdentifier);
            if (isset($parts[2]) && $parts[2] !== 'rebook' && isset($parts[3]) && is_numeric($parts[3])) {
                return 'Ticket ' . ((int)$parts[3] + 1);
            }
            return 'Ticket';
        }

        // Parse seat identifier
        if (strpos($seatIdentifier, 'seat_') === 0 || strpos($seatIdentifier, 'wheelchair_') === 0) {
            // Regular seat or wheelchair: seat_ROW_SEAT / wheelchair_ROW_SEAT
            $isWheelchair = strpos($seatIdentifier, 'wheelchair_') === 0;
            $parts = explode('_', $seatIdentifier);
            if (count($parts) >= 3) {
                $rowNumber = $parts[1];
                $seat = $parts[2];

                // Get row name if available
                $sql = "SELECT row_name, `row_number` FROM seat_plan_rows WHERE event_id = :event_id AND `row_number` = :row_number";
                $stmt = $this->db->query($sql, [
                    ':event_id' => $eventId,
                    ':row_number' => (int)$rowNumber
                ]);
                $row = $stmt->fetch();
                $rowName = $row ? ($row['row_name'] ?: "Reihe " . $row['row_number']) : "Reihe $rowNumber";

                $prefix = $isWheelchair ? 'Rollstuhlplatz, ' : '';
                return $prefix . "$rowName, Platz $seat";
            }
        } elseif (strpos($seatIdentifier, 'tableseat_') === 0) {
            // Table seat: tableseat_TABLE_SEAT
            $parts = explode('_', $seatIdentifier);
            if (count($parts) >= 3) {
                $table = $parts[1];
                $seat = $parts[2];
                return "Tisch $table, Platz $seat";
            }
        } elseif (strpos($seatIdentifier, 'table_') === 0) {
            // Table: table_TABLE
            $parts = explode('_', $seatIdentifier);
            if (count($parts) >= 2) {
                $table = $parts[1];
                return "Tisch $table";
            }
        }
        
        // Fallback: return identifier as-is
        return $seatIdentifier;
    }

    // ---------------------------------------------------------------
    // Alternativbelegung (z. B. Tribüne -> Ausweichspielstätte)
    // ---------------------------------------------------------------

    /**
     * Normalize a value entered for an alternative row/seat.
     * Strips the German label prefixes ("Reihe 1" -> "1", "Platz 13" -> "13")
     * so that only the bare designation is stored. Free text ("Balkon links")
     * is kept as-is.
     */
    public static function normalizeAltValue(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim(preg_replace('/\s+/u', ' ', (string)$value));
        if ($value === '') {
            return null;
        }

        $value = preg_replace('/^(reihe|row|platz|sitz|seat|nr\.?|no\.?)\s*[:.]?\s*/iu', '', $value);
        $value = trim($value);

        return $value === '' ? null : $value;
    }

    /**
     * Format an alternative seat for display: "Reihe 1, Platz 13".
     * Purely numeric parts get the German label, free text is printed as-is.
     */
    public static function formatAlternativeSeat(?string $altRow, ?string $altSeat): string
    {
        $altRow = self::normalizeAltValue($altRow);
        $altSeat = self::normalizeAltValue($altSeat);

        $parts = [];
        if ($altRow !== null) {
            $parts[] = is_numeric($altRow) ? 'Reihe ' . $altRow : $altRow;
        }
        if ($altSeat !== null) {
            $parts[] = is_numeric($altSeat) ? 'Platz ' . $altSeat : $altSeat;
        }

        return implode(', ', $parts);
    }

    /**
     * Look up the alternative assignment for a seat identifier.
     * Returns ['row' => ?string, 'seat' => ?string] or null when the seat has none.
     */
    public function getAlternativeSeat(int $eventId, string $seatIdentifier): ?array
    {
        $parts = explode('_', $seatIdentifier);

        if ((str_starts_with($seatIdentifier, 'seat_') || str_starts_with($seatIdentifier, 'wheelchair_')) && count($parts) >= 3) {
            $sql = "SELECT spe.alt_row, spe.alt_seat
                    FROM seat_plan_elements spe
                    INNER JOIN seat_plan_rows spr ON spe.row_id = spr.id
                    WHERE spr.event_id = :event_id
                      AND spr.row_number = :row_number
                      AND spe.element_type IN ('seat', 'wheelchair')
                      AND spe.table_number IS NULL
                      AND spe.element_number = :element_number
                    LIMIT 1";
            $stmt = $this->db->query($sql, [
                ':event_id' => $eventId,
                ':row_number' => (int)$parts[1],
                ':element_number' => (int)$parts[2],
            ]);
        } elseif (str_starts_with($seatIdentifier, 'tableseat_') && count($parts) >= 3) {
            $sql = "SELECT spe.alt_row, spe.alt_seat
                    FROM seat_plan_elements spe
                    INNER JOIN seat_plan_rows spr ON spe.row_id = spr.id
                    WHERE spr.event_id = :event_id
                      AND spe.element_type = 'seat'
                      AND spe.table_number = :table_number
                      AND spe.table_seat_number = :table_seat_number
                    LIMIT 1";
            $stmt = $this->db->query($sql, [
                ':event_id' => $eventId,
                ':table_number' => (int)$parts[1],
                ':table_seat_number' => (int)$parts[2],
            ]);
        } else {
            return null;
        }

        $element = $stmt->fetch();
        if (!$element) {
            return null;
        }

        $altRow = self::normalizeAltValue($element['alt_row'] ?? null);
        $altSeat = self::normalizeAltValue($element['alt_seat'] ?? null);

        if ($altRow === null && $altSeat === null) {
            return null;
        }

        return ['row' => $altRow, 'seat' => $altSeat];
    }

    /**
     * Get every seat of an event as a flat list, keyed for import matching.
     * Key format: "ROWNUMBER|SEATNUMBER" for regular seats,
     * "T:TABLENUMBER|SEATNUMBER" for table seats.
     */
    public function getSeatIndex(int $eventId): array
    {
        $sql = "SELECT spe.id, spe.element_type, spe.element_number, spe.table_number,
                       spe.table_seat_number, spe.alt_row, spe.alt_seat, spe.category_id,
                       spr.id AS row_id, spr.row_number, spr.row_name
                FROM seat_plan_elements spe
                INNER JOIN seat_plan_rows spr ON spe.row_id = spr.id
                WHERE spr.event_id = :event_id
                  AND spe.element_type IN ('seat', 'wheelchair')";

        $stmt = $this->db->query($sql, [':event_id' => $eventId]);
        $index = [];

        foreach ($stmt->fetchAll() as $element) {
            if ($element['table_number'] !== null && $element['table_seat_number'] !== null) {
                $key = 'T:' . (int)$element['table_number'] . '|' . (int)$element['table_seat_number'];
            } elseif ($element['element_number'] !== null) {
                $key = (int)$element['row_number'] . '|' . (int)$element['element_number'];
            } else {
                continue;
            }
            $index[$key] = $element;
        }

        return $index;
    }

    /**
     * Write only the alternative assignment of a single element.
     */
    public function updateAlternativeSeat(int $elementId, ?string $altRow, ?string $altSeat): bool
    {
        $this->db->query(
            "UPDATE seat_plan_elements SET alt_row = :alt_row, alt_seat = :alt_seat WHERE id = :id",
            [
                ':id' => $elementId,
                ':alt_row' => self::normalizeAltValue($altRow),
                ':alt_seat' => self::normalizeAltValue($altSeat),
            ]
        );

        return true;
    }

    /**
     * Position at which a new seat with this number belongs in the row: before the first regular
     * or wheelchair seat with a higher number (in position order), or at the end. All elements
     * from that position on are shifted by one, so the returned position is free to use.
     */
    public function makeRoomForSeat(int $rowId, int $seatNumber): int
    {
        $elements = $this->db->query(
            "SELECT position, element_type, element_number, table_number FROM seat_plan_elements
             WHERE row_id = :row_id ORDER BY position ASC",
            [':row_id' => $rowId]
        )->fetchAll();

        $endPosition = 0;
        $insertAt = null;
        foreach ($elements as $element) {
            $endPosition = max($endPosition, (int)$element['position'] + 1);
            $isRowSeat = in_array($element['element_type'], ['seat', 'wheelchair'], true) && $element['table_number'] === null;
            if ($insertAt === null && $isRowSeat && (int)$element['element_number'] > $seatNumber) {
                $insertAt = (int)$element['position'];
            }
        }

        if ($insertAt === null) {
            return $endPosition;
        }

        $this->db->query(
            "UPDATE seat_plan_elements SET position = position + 1 WHERE row_id = :row_id AND position >= :position",
            [':row_id' => $rowId, ':position' => $insertAt]
        );
        return $insertAt;
    }

    /**
     * Write only the category of a single element.
     */
    public function updateElementCategory(int $elementId, ?int $categoryId): bool
    {
        $this->db->query(
            "UPDATE seat_plan_elements SET category_id = :category_id WHERE id = :id",
            [':id' => $elementId, ':category_id' => $categoryId]
        );

        return true;
    }

    /**
     * Remove all alternative assignments of an event.
     */
    public function clearAlternativeSeats(int $eventId): int
    {
        $stmt = $this->db->query(
            "UPDATE seat_plan_elements spe
             INNER JOIN seat_plan_rows spr ON spe.row_id = spr.id
             SET spe.alt_row = NULL, spe.alt_seat = NULL
             WHERE spr.event_id = :event_id
               AND (spe.alt_row IS NOT NULL OR spe.alt_seat IS NOT NULL)",
            [':event_id' => $eventId]
        );

        return $stmt->rowCount();
    }
}

