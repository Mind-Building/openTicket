<?php
/**
 * Database Migrator
 *
 * Brings the database of an installation (the one configured in its .env) up to date with the
 * code. Every migration file in database/ is listed here with a check against the actual schema,
 * so it also recognises changes that were applied by hand earlier. Pending migrations are run in
 * order; the run stops at the first error.
 *
 * Used by database/update_database.php (CLI) and public/admin/database_update.php (web).
 * New migration: add the .sql file to database/ and an entry to migrations() below.
 */

namespace App\Services;

use App\Core\Database;
use PDO;

class DatabaseMigrator
{
    private PDO $pdo;
    private string $dbName;
    private string $migrationDir;

    public function __construct(?PDO $pdo = null, ?string $migrationDir = null)
    {
        $this->pdo = $pdo ?? Database::getInstance()->getConnection();
        $this->dbName = (string)$this->pdo->query('SELECT DATABASE()')->fetchColumn();
        $this->migrationDir = $migrationDir ?? dirname(__DIR__, 2) . '/database';
    }

    /**
     * All migrations in the order they have to be applied.
     *
     * @return array<int, array{id: string, file: string, description: string, applied: callable}>
     */
    public function migrations(): array
    {
        return [
            [
                'id' => 'payment_requests',
                'file' => 'payment_requests_migration.sql',
                'description' => 'Zahlungsanforderungen (Tabelle payment_requests)',
                'applied' => fn() => $this->tableExists('payment_requests'),
            ],
            [
                'id' => 'vip_status',
                'file' => 'migrate_vip_status.sql',
                'description' => 'Reservierungsstatus VIP (Status 11)',
                'applied' => fn() => (bool)$this->pdo->query('SELECT COUNT(*) FROM reservation_statuses WHERE id = 11')->fetchColumn(),
            ],
            [
                'id' => 'presales_template',
                'file' => 'migrate_presales_template.sql',
                'description' => 'Eigenes Ticket-Template für den Vorverkauf',
                'applied' => fn() => $this->columnExists('events', 'presales_ticket_pdf_template')
                    && $this->columnExists('events', 'presales_ticket_template_config'),
            ],
            [
                'id' => 'wheelchair',
                'file' => 'migrate_wheelchair.sql',
                'description' => 'Rollstuhlplätze, Begleitpersonen, nummerierte Abstände',
                'applied' => fn() => str_contains($this->columnType('seat_plan_elements', 'element_type'), "'wheelchair'")
                    && $this->columnExists('reservations', 'is_companion')
                    && $this->columnExists('seat_plan_elements', 'consumes_number'),
            ],
            [
                'id' => 'rate_limits',
                'file' => 'migrate_rate_limits.sql',
                'description' => 'Schutz vor zu vielen Anfragen (Tabelle rate_limits)',
                'applied' => fn() => $this->tableExists('rate_limits'),
            ],
            [
                'id' => 'rebookings',
                'file' => 'migrate_rebookings.sql',
                'description' => 'Umbuchungs-Historie (Tabelle rebookings)',
                'applied' => fn() => $this->tableExists('rebookings'),
            ],
            [
                'id' => 'device_token_hash',
                'file' => 'migrate_device_token_hash.sql',
                'description' => 'Geräte-Tokens gehasht speichern (Spalte token mit 100 Zeichen)',
                'applied' => fn() => !$this->tableExists('device_tokens') || $this->columnLength('device_tokens', 'token') >= 100,
            ],
            [
                'id' => 'alternative_seats',
                'file' => 'migrate_alternative_seats.sql',
                'description' => 'Alternativbelegung (Ausweichspielstätte)',
                'applied' => fn() => $this->columnExists('seat_plan_elements', 'alt_row')
                    && $this->columnExists('seat_plan_elements', 'alt_seat')
                    && $this->columnExists('events', 'alt_location_name'),
            ],
            [
                'id' => 'ticket_code',
                'file' => 'migrate_ticket_code.sql',
                'description' => 'QR-Code / Strichcode auf dem Ticket',
                'applied' => fn() => $this->columnExists('events', 'ticket_code_type')
                    && $this->columnExists('events', 'ticket_code_content'),
            ],
            [
                'id' => 'fn_card_number',
                'file' => 'migrate_fn_card_number.sql',
                'description' => 'FN-Card-Nummer an der Reservierung, Limit pro Karte',
                'applied' => fn() => $this->columnExists('reservations', 'fn_card_number'),
            ],
            [
                'id' => 'stairs',
                'file' => 'migrate_stairs.sql',
                'description' => 'Abstände als Treppe',
                'applied' => fn() => $this->columnExists('seat_plan_elements', 'is_stairs'),
            ],
        ];
    }

    /**
     * Status of every migration.
     *
     * @return array<int, array{id: string, file: string, description: string, applied: bool, error: ?string}>
     */
    public function status(): array
    {
        $result = [];
        foreach ($this->migrations() as $migration) {
            $error = null;
            try {
                $applied = (bool)($migration['applied'])();
            } catch (\Throwable $e) {
                // e.g. a base table is missing - the migration itself will tell
                $applied = false;
                $error = $e->getMessage();
            }
            $result[] = [
                'id' => $migration['id'],
                'file' => $migration['file'],
                'description' => $migration['description'],
                'applied' => $applied,
                'error' => $error,
            ];
        }
        return $result;
    }

    /** @return string[] ids of migrations that still have to run */
    public function pending(): array
    {
        return array_column(array_filter($this->status(), fn($m) => !$m['applied']), 'id');
    }

    /**
     * Run all pending migrations in order. Stops at the first error.
     *
     * @return array<int, array{id: string, description: string, ok: bool, message: string}>
     */
    public function run(): array
    {
        $log = [];
        foreach ($this->migrations() as $migration) {
            try {
                if (($migration['applied'])()) {
                    continue;
                }
            } catch (\Throwable $e) {
                // check could not run (e.g. table missing) -> try the migration
            }

            try {
                $this->executeFile($migration['file']);
                $ok = (bool)($migration['applied'])();
                $log[] = [
                    'id' => $migration['id'],
                    'description' => $migration['description'],
                    'ok' => $ok,
                    'message' => $ok ? 'eingespielt' : 'ausgeführt, Prüfung danach aber nicht erfolgreich',
                ];
                if (!$ok) {
                    break;
                }
            } catch (\Throwable $e) {
                $log[] = [
                    'id' => $migration['id'],
                    'description' => $migration['description'],
                    'ok' => false,
                    'message' => $e->getMessage(),
                ];
                break;
            }
        }
        return $log;
    }

    public function databaseName(): string
    {
        return $this->dbName;
    }

    /**
     * Execute a migration file statement by statement. Understands DELIMITER blocks
     * (stored procedures), which PDO cannot run as one string.
     */
    private function executeFile(string $file): void
    {
        $path = $this->migrationDir . '/' . $file;
        if (!is_file($path)) {
            throw new \RuntimeException('Migrationsdatei fehlt: database/' . $file);
        }
        foreach (self::splitStatements((string)file_get_contents($path)) as $statement) {
            $this->executeStatement($statement);
        }
    }

    /**
     * MySQL (unlike MariaDB) has no "ADD COLUMN/INDEX IF NOT EXISTS". Such ALTER TABLE
     * statements are run clause by clause: existing columns/indexes are skipped, the rest is
     * executed without "IF NOT EXISTS" - works on MySQL and MariaDB alike.
     */
    private function executeStatement(string $statement): void
    {
        if (!preg_match('/^ALTER\s+TABLE\s+`?(\w+)`?\s+(.*)$/is', $statement, $m) || !preg_match('/\bIF\s+NOT\s+EXISTS\b/i', $m[2])) {
            $this->pdo->exec($statement);
            return;
        }

        $table = $m[1];
        foreach (self::alterClauses($m[2]) as $clause) {
            if (preg_match('/^ADD\s+(?:COLUMN\s+)?IF\s+NOT\s+EXISTS\s+`?(\w+)`?\s+(.*)$/is', $clause, $c)) {
                if (!$this->columnExists($table, $c[1])) {
                    $this->pdo->exec('ALTER TABLE `' . $table . '` ADD COLUMN `' . $c[1] . '` ' . $c[2]);
                }
            } elseif (preg_match('/^ADD\s+(INDEX|KEY|UNIQUE\s+INDEX|UNIQUE\s+KEY)\s+IF\s+NOT\s+EXISTS\s+`?(\w+)`?\s+(.*)$/is', $clause, $c)) {
                if (!$this->indexExists($table, $c[2])) {
                    $this->pdo->exec('ALTER TABLE `' . $table . '` ADD ' . strtoupper(preg_replace('/\s+/', ' ', $c[1])) . ' `' . $c[2] . '` ' . $c[3]);
                }
            } else {
                $this->pdo->exec('ALTER TABLE `' . $table . '` ' . $clause);
            }
        }
    }

    /**
     * Split the clause list of an ALTER TABLE at top-level commas (not inside parentheses or quotes).
     *
     * @return string[]
     */
    public static function alterClauses(string $clauses): array
    {
        $result = [];
        $depth = 0;
        $quote = null;
        $current = '';
        $length = strlen($clauses);
        for ($i = 0; $i < $length; $i++) {
            $ch = $clauses[$i];
            if ($quote !== null) {
                if ($ch === $quote) {
                    $quote = null;
                }
            } elseif ($ch === "'" || $ch === '"' || $ch === '`') {
                $quote = $ch;
            } elseif ($ch === '(') {
                $depth++;
            } elseif ($ch === ')') {
                $depth--;
            } elseif ($ch === ',' && $depth === 0) {
                $result[] = trim($current);
                $current = '';
                continue;
            }
            $current .= $ch;
        }
        if (trim($current) !== '') {
            $result[] = trim($current);
        }
        return $result;
    }

    private function indexExists(string $table, string $index): bool
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND INDEX_NAME = ?');
        $stmt->execute([$this->dbName, $table, $index]);
        return (bool)$stmt->fetchColumn();
    }

    /**
     * Split SQL into single statements; skips comment lines and handles "DELIMITER $$".
     *
     * @return string[]
     */
    public static function splitStatements(string $sql): array
    {
        $statements = [];
        $delimiter = ';';
        $buffer = '';

        foreach (preg_split('/\R/', $sql) as $line) {
            $trimmed = trim($line);
            if ($buffer === '' && ($trimmed === '' || str_starts_with($trimmed, '--') || str_starts_with($trimmed, '#'))) {
                continue;
            }
            if (preg_match('/^DELIMITER\s+(\S+)\s*$/i', $trimmed, $m)) {
                $delimiter = $m[1];
                continue;
            }
            $buffer .= $line . "\n";
            if (str_ends_with(rtrim($buffer), $delimiter)) {
                $statement = trim(substr(rtrim($buffer), 0, -strlen($delimiter)));
                if ($statement !== '') {
                    $statements[] = $statement;
                }
                $buffer = '';
            }
        }

        if (trim($buffer) !== '') {
            $statements[] = trim($buffer);
        }
        return $statements;
    }

    private function tableExists(string $table): bool
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?');
        $stmt->execute([$this->dbName, $table]);
        return (bool)$stmt->fetchColumn();
    }

    private function columnExists(string $table, string $column): bool
    {
        return $this->columnInfo($table, $column) !== null;
    }

    private function columnType(string $table, string $column): string
    {
        return (string)($this->columnInfo($table, $column)['COLUMN_TYPE'] ?? '');
    }

    private function columnLength(string $table, string $column): int
    {
        return (int)($this->columnInfo($table, $column)['CHARACTER_MAXIMUM_LENGTH'] ?? 0);
    }

    private function columnInfo(string $table, string $column): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT COLUMN_TYPE, CHARACTER_MAXIMUM_LENGTH FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?'
        );
        $stmt->execute([$this->dbName, $table, $column]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }
}
