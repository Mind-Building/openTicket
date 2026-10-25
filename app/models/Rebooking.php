<?php
/**
 * Rebooking Model
 *
 * Audit-Trail fuer Umbuchungen. Eine Umbuchung ueberschreibt event_date /
 * seat_identifier in der reservations-Zeile (siehe Reservation::rebook);
 * hier wird das Vorher->Nachher dauerhaft festgehalten, damit es je
 * Veranstaltung ausgewertet werden kann.
 */

namespace App\Models;

use App\Core\Database;

class Rebooking
{
    private Database $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    /**
     * Protokolliert eine einzelne Umbuchung.
     *
     * Idempotent ueber UNIQUE(reservation_id, created_at): Wird derselbe
     * Eintrag (z. B. beim Backfill) erneut eingespielt, wird er ignoriert.
     *
     * @param string|null $createdAt MySQL-Datetime; null => NOW() (Live-Umbuchung).
     * @return bool true, wenn eine neue Zeile geschrieben wurde (false = Duplikat ignoriert).
     */
    public function record(
        int $reservationId,
        int $eventId,
        string $oldDate,
        string $oldSeat,
        string $newDate,
        string $newSeat,
        string $channel,
        ?int $userId,
        ?string $username,
        ?string $createdAt = null
    ): bool {
        $sql = "INSERT IGNORE INTO rebookings
                    (reservation_id, event_id, old_event_date, old_seat_identifier,
                     new_event_date, new_seat_identifier, channel, user_id, username, created_at)
                VALUES
                    (:reservation_id, :event_id, :old_date, :old_seat,
                     :new_date, :new_seat, :channel, :user_id, :username, "
                     . ($createdAt === null ? "NOW()" : ":created_at") . ")";

        $params = [
            ':reservation_id' => $reservationId,
            ':event_id'       => $eventId,
            ':old_date'       => substr($oldDate, 0, 10),
            ':old_seat'       => $oldSeat,
            ':new_date'       => substr($newDate, 0, 10),
            ':new_seat'       => $newSeat,
            ':channel'        => $channel,
            ':user_id'        => $userId,
            ':username'       => $username,
        ];
        if ($createdAt !== null) {
            $params[':created_at'] = $createdAt;
        }

        $stmt = $this->db->query($sql, $params);
        return $stmt->rowCount() > 0;
    }

    /**
     * Protokolliert alle Umbuchungen eines rebook()-Durchlaufs.
     *
     * @param array $results Rueckgabe von Reservation::rebook()
     *                       ([{reservation_id, old_date, old_seat, new_date, new_seat}, ...])
     */
    public function recordBatch(
        int $eventId,
        string $channel,
        ?int $userId,
        ?string $username,
        array $results
    ): void {
        foreach ($results as $r) {
            $this->record(
                (int)$r['reservation_id'],
                $eventId,
                (string)$r['old_date'],
                (string)$r['old_seat'],
                (string)$r['new_date'],
                (string)$r['new_seat'],
                $channel,
                $userId,
                $username
            );
        }
    }

    /**
     * Alle Umbuchungen einer Veranstaltung, inkl. Kundendaten (sofern die
     * Reservierung noch existiert). Neueste zuerst.
     */
    public function getByEvent(int $eventId): array
    {
        $sql = "SELECT rb.*,
                       r.customer_id,
                       c.first_name, c.last_name, c.email
                FROM rebookings rb
                LEFT JOIN reservations r ON r.id = rb.reservation_id
                LEFT JOIN customers c    ON c.id = r.customer_id
                WHERE rb.event_id = :event_id
                ORDER BY rb.created_at DESC, rb.id DESC";
        return $this->db->query($sql, [':event_id' => $eventId])->fetchAll();
    }

    /**
     * Anzahl Umbuchungen je Veranstaltung (fuer die Auswahl-/Uebersicht).
     *
     * @return array<int,int> [event_id => count]
     */
    public function countsByEvent(): array
    {
        $sql = "SELECT event_id, COUNT(*) AS cnt FROM rebookings GROUP BY event_id";
        $out = [];
        foreach ($this->db->query($sql)->fetchAll() as $row) {
            $out[(int)$row['event_id']] = (int)$row['cnt'];
        }
        return $out;
    }

    /**
     * Liest `reservation_rebooked`-Eintraege aus allen *.log-Dateien eines
     * Verzeichnisses und traegt sie in die Tabelle ein (idempotent).
     *
     * Wird sowohl vom CLI-Skript als auch von der Admin-Oberflaeche genutzt.
     *
     * @return array{scanned:int,items:int,inserted:int,skipped:int,entries:array,errors:array}
     *   scanned = Log-Zeilen, items = einzelne Umbuchungen (Karten),
     *   inserted = neu geschrieben, skipped = bereits vorhanden (nur ohne dry-run belastbar).
     */
    public function backfillFromLogs(string $dir, bool $dryRun = false): array
    {
        $result = ['scanned' => 0, 'items' => 0, 'inserted' => 0, 'skipped' => 0, 'entries' => [], 'errors' => []];

        $dir = rtrim($dir, "/\\");
        if (!is_dir($dir)) {
            $result['errors'][] = "Log-Verzeichnis nicht gefunden: $dir";
            return $result;
        }

        // Alle *.log-Dateien einsammeln (inkl. rotierter Tagesdateien)
        $files = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            if ($file->isFile() && strtolower($file->getExtension()) === 'log') {
                $files[] = $file->getPathname();
            }
        }
        sort($files);

        // [ts] channel.LEVEL: Action: reservation_rebooked {json} []
        $pattern = '/^\[(?<ts>[^\]]+)\]\s+(?<channel>\w+)\.\w+:\s+Action:\s+reservation_rebooked\s+(?<json>\{.*\})\s+\[\]\s*$/';

        foreach ($files as $path) {
            $fh = fopen($path, 'r');
            if (!$fh) {
                continue;
            }
            while (($line = fgets($fh)) !== false) {
                $line = rtrim($line, "\r\n");
                if (strpos($line, 'reservation_rebooked') === false) {
                    continue;
                }
                if (!preg_match($pattern, $line, $m)) {
                    continue;
                }
                $result['scanned']++;

                $ctx = json_decode($m['json'], true);
                if (!is_array($ctx) || empty($ctx['rebookings']) || !is_array($ctx['rebookings'])) {
                    continue;
                }

                $channel   = $m['channel'];
                $createdAt = $m['ts'];
                $eventId   = (int)($ctx['event_id'] ?? 0);
                $userId    = isset($ctx['user_id']) && is_numeric($ctx['user_id']) ? (int)$ctx['user_id'] : null;
                $username  = isset($ctx['username']) && $ctx['username'] !== '' ? (string)$ctx['username'] : null;

                foreach ($ctx['rebookings'] as $rb) {
                    if (empty($rb['reservation_id'])) {
                        continue;
                    }
                    $result['items']++;
                    $result['entries'][] = [
                        'created_at'     => $createdAt,
                        'channel'        => $channel,
                        'reservation_id' => (int)$rb['reservation_id'],
                        'old_date'       => $rb['old_date'] ?? '',
                        'old_seat'       => $rb['old_seat'] ?? '',
                        'new_date'       => $rb['new_date'] ?? '',
                        'new_seat'       => $rb['new_seat'] ?? '',
                    ];

                    if ($dryRun) {
                        continue;
                    }
                    try {
                        $isNew = $this->record(
                            (int)$rb['reservation_id'],
                            $eventId,
                            (string)($rb['old_date'] ?? ''),
                            (string)($rb['old_seat'] ?? ''),
                            (string)($rb['new_date'] ?? ''),
                            (string)($rb['new_seat'] ?? ''),
                            $channel,
                            $userId,
                            $username,
                            $createdAt
                        );
                        $isNew ? $result['inserted']++ : $result['skipped']++;
                    } catch (\Throwable $e) {
                        $result['errors'][] = "Res #{$rb['reservation_id']}: " . $e->getMessage();
                    }
                }
            }
            fclose($fh);
        }

        return $result;
    }
}
