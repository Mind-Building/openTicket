<?php
/**
 * Log Cleanup Service
 *
 * Removes old log entries from the daily log files in logging/ (customer-, admin-,
 * presales-YYYY-MM-DD.log). Two modes:
 *
 *  - MODE_INACTIVE: removes every entry that belongs to an event which is not active
 *    (inactive or deleted). Entries without an event reference stay.
 *  - MODE_OLDER:    removes everything in files older than the age limit (3 months),
 *    except entries of active events.
 *
 * Entries of ACTIVE events are never removed. An entry belongs to an event via its
 * context field event_id or via the event of the reservations it names
 * (reservation_ids, reservation_id, failed_id). Today's files are skipped because the
 * logger is still appending to them. A file that ends up empty is deleted.
 */

namespace App\Services;

use App\Core\Database;

class LogCleanupService
{
    public const MODE_INACTIVE = 'inactive';
    public const MODE_OLDER    = 'older';
    public const MAX_AGE_MONTHS = 3;

    private const RESERVATION_FIELDS = ['reservation_ids', 'reservation_id', 'failed_id'];

    /** @var array<int, true>|null */
    private ?array $activeEventIds;
    /** @var callable(int[]): array<int, int> reservation id => event id */
    private $reservationEventLookup;

    /**
     * @param array<int, true>|null $activeEventIds         null = load from DB
     * @param callable|null         $reservationEventLookup null = load from DB
     */
    public function __construct(
        private string $logDir,
        ?array $activeEventIds = null,
        ?callable $reservationEventLookup = null
    ) {
        $this->activeEventIds = $activeEventIds;
        $this->reservationEventLookup = $reservationEventLookup ?? [$this, 'lookupReservationEvents'];
    }

    /** Cutoff date (Y-m-d): files of an earlier day count as "older than 3 months". */
    public static function cutoffDate(string $today): string
    {
        return date('Y-m-d', strtotime($today . ' -' . self::MAX_AGE_MONTHS . ' months'));
    }

    /**
     * Dry run: what would be removed.
     *
     * @return array{files: array<int, array{file: string, total: int, removed: int, action: string}>, removed_entries: int, deleted_files: int, rewritten_files: int}
     */
    public function plan(string $mode, string $today): array
    {
        return $this->process($mode, $today, false);
    }

    /** Same as plan(), but actually rewrites / deletes the files. */
    public function execute(string $mode, string $today): array
    {
        return $this->process($mode, $today, true);
    }

    private function process(string $mode, string $today, bool $write): array
    {
        if (!in_array($mode, [self::MODE_INACTIVE, self::MODE_OLDER], true)) {
            throw new \InvalidArgumentException('Unbekannter Modus: ' . $mode);
        }
        $cutoff = self::cutoffDate($today);

        $candidates = [];
        foreach (glob($this->logDir . '/*.log') ?: [] as $file) {
            if (!preg_match('/^(admin|customer|presales)-(\d{4}-\d{2}-\d{2})\.log$/', basename($file), $m)) {
                continue;
            }
            $date = $m[2];
            if ($date >= $today) {
                continue; // still being written
            }
            if ($mode === self::MODE_OLDER && $date >= $cutoff) {
                continue;
            }
            $candidates[] = $file;
        }
        sort($candidates);

        // Parse all candidate files first, so reservations can be resolved in one query
        $parsed = [];
        $reservationIds = [];
        foreach ($candidates as $file) {
            $entries = $this->readEntries($file);
            $parsed[$file] = $entries;
            foreach ($entries as $entry) {
                foreach ($entry['reservations'] as $rid) {
                    $reservationIds[$rid] = true;
                }
            }
        }
        $reservationEvents = $reservationIds
            ? ($this->reservationEventLookup)(array_keys($reservationIds))
            : [];
        $active = $this->activeEventIds ??= $this->loadActiveEventIds();

        $result = ['files' => [], 'removed_entries' => 0, 'deleted_files' => 0, 'rewritten_files' => 0];

        foreach ($parsed as $file => $entries) {
            $keep = '';
            $removed = 0;
            foreach ($entries as $entry) {
                $events = $entry['events'];
                foreach ($entry['reservations'] as $rid) {
                    if (isset($reservationEvents[$rid])) {
                        $events[$reservationEvents[$rid]] = true;
                    }
                }
                $hasActive = (bool)array_intersect_key($events, $active);

                $remove = match ($mode) {
                    // only entries that belong to events, none of them active
                    self::MODE_INACTIVE => $events && !$hasActive,
                    // everything except entries of active events
                    self::MODE_OLDER    => !$hasActive,
                };
                if ($remove) {
                    $removed++;
                } else {
                    $keep .= $entry['text'];
                }
            }

            if ($removed === 0) {
                continue;
            }

            $action = trim($keep) === '' ? 'delete' : 'rewrite';
            if ($write) {
                $ok = $action === 'delete'
                    ? @unlink($file)
                    : @file_put_contents($file, $keep, LOCK_EX) !== false;
                if (!$ok) {
                    throw new \RuntimeException('Datei konnte nicht geändert werden: ' . basename($file));
                }
            }

            $result['files'][] = [
                'file'    => basename($file),
                'total'   => count($entries),
                'removed' => $removed,
                'action'  => $action,
            ];
            $result['removed_entries'] += $removed;
            $result[$action === 'delete' ? 'deleted_files' : 'rewritten_files']++;
        }

        return $result;
    }

    /**
     * Splits a log file into entries. An entry starts with "[YYYY-MM-DD HH:MM:SS]";
     * following lines without that prefix (e.g. stack traces) belong to it.
     *
     * @return array<int, array{text: string, events: array<int, true>, reservations: int[]}>
     */
    private function readEntries(string $file): array
    {
        $handle = @fopen($file, 'r');
        if (!$handle) {
            return [];
        }
        $entries = [];
        $current = null;
        while (($line = fgets($handle)) !== false) {
            if (preg_match('/^\[\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\] /', $line) || $current === null) {
                if ($current !== null) {
                    $entries[] = $current;
                }
                $current = ['text' => $line] + $this->extractReferences($line);
            } else {
                $current['text'] .= $line;
            }
        }
        if ($current !== null) {
            $entries[] = $current;
        }
        fclose($handle);
        return $entries;
    }

    /**
     * Event and reservation IDs referenced in the JSON context of a log line.
     *
     * @return array{events: array<int, true>, reservations: int[]}
     */
    public function extractReferences(string $line): array
    {
        $refs = ['events' => [], 'reservations' => []];
        if (!preg_match('/ (\{.*\}) \[\]\s*$/', $line, $m)) {
            return $refs;
        }
        $context = json_decode($m[1], true);
        if (!is_array($context)) {
            return $refs;
        }
        if (isset($context['event_id']) && is_numeric($context['event_id']) && (int)$context['event_id'] > 0) {
            $refs['events'][(int)$context['event_id']] = true;
        }
        foreach (self::RESERVATION_FIELDS as $field) {
            if (!isset($context[$field])) {
                continue;
            }
            $values = is_array($context[$field]) ? $context[$field] : explode(',', (string)$context[$field]);
            foreach ($values as $value) {
                if (is_scalar($value) && ctype_digit(trim((string)$value)) && (int)$value > 0) {
                    $refs['reservations'][] = (int)$value;
                }
            }
        }
        $refs['reservations'] = array_values(array_unique($refs['reservations']));
        return $refs;
    }

    /** @return array<int, true> */
    private function loadActiveEventIds(): array
    {
        $rows = Database::getInstance()->query('SELECT id FROM events WHERE active = 1')->fetchAll();
        $ids = [];
        foreach ($rows as $row) {
            $ids[(int)$row['id']] = true;
        }
        return $ids;
    }

    /**
     * @param int[] $reservationIds
     * @return array<int, int> reservation id => event id
     */
    private function lookupReservationEvents(array $reservationIds): array
    {
        $map = [];
        $db = Database::getInstance();
        foreach (array_chunk($reservationIds, 500) as $chunk) {
            $params = [];
            foreach ($chunk as $i => $id) {
                $params[':r' . $i] = $id;
            }
            $rows = $db->query(
                'SELECT id, event_id FROM reservations WHERE id IN (' . implode(',', array_keys($params)) . ')',
                $params
            )->fetchAll();
            foreach ($rows as $row) {
                $map[(int)$row['id']] = (int)$row['event_id'];
            }
        }
        return $map;
    }
}
