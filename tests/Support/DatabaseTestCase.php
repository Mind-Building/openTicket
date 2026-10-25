<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Core\Database;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Base class for tests that need the real database.
 *
 * Each test runs inside its own MySQL transaction that is rolled back in
 * tearDown(). Net effect:
 *   • test code can INSERT/UPDATE/DELETE freely,
 *   • the next test starts with the exact same baseline (schema + seeded
 *     reservation_statuses rows from schema.sql – nothing else),
 *   • the suite is fast (no truncate/reseed between tests).
 *
 * Application code under test MUST NOT call beginTransaction()/commit() of
 * its own – MySQL doesn't support real nested transactions. If a production
 * model needs them, swap it out via a test seam or extend this class to
 * use SAVEPOINTs.
 */
abstract class DatabaseTestCase extends TestCase
{
    protected PDO $pdo;

    protected function setUp(): void
    {
        // Sanity-check the env – the same guardrail runs in tests/bootstrap.php,
        // but a developer running a single test class might bypass that path.
        $dbName = $_ENV['DB_NAME'] ?? '';
        if (stripos($dbName, 'test') === false) {
            self::fail(
                "DatabaseTestCase refused to start: DB_NAME=\"{$dbName}\" does not "
                . "contain \"test\". Check phpunit.xml or your shell env."
            );
        }

        $this->pdo = Database::getInstance()->getConnection();
        $this->pdo->beginTransaction();
    }

    protected function tearDown(): void
    {
        if ($this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
    }

    // ── Minimal factories ──────────────────────────────────────────
    //
    // These exist so common test data can be inserted in a single line,
    // and so the column names live in one place. Extend as new tests
    // need new fixtures; resist the urge to mirror full model APIs here.

    /**
     * Insert a user and return the row including the generated id.
     *
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    protected function createUser(array $overrides = []): array
    {
        $unique = uniqid('', true);
        $defaults = [
            'username'      => 'test_' . substr(md5($unique), 0, 8),
            'email'         => null,
            'password_hash' => password_hash('test_password_123', PASSWORD_DEFAULT),
            'role'          => 'admin',
            'active'        => 1,
        ];
        $row = array_merge($defaults, $overrides);

        $stmt = $this->pdo->prepare(
            "INSERT INTO users (username, email, password_hash, role, active)
             VALUES (:username, :email, :password_hash, :role, :active)"
        );
        $stmt->execute([
            ':username'      => $row['username'],
            ':email'         => $row['email'],
            ':password_hash' => $row['password_hash'],
            ':role'          => $row['role'],
            ':active'        => $row['active'],
        ]);
        $row['id'] = (int)$this->pdo->lastInsertId();
        return $row;
    }

    /**
     * Insert a customer and return the row including the generated id.
     *
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    protected function createCustomer(array $overrides = []): array
    {
        $unique = uniqid('', true);
        $defaults = [
            'first_name'  => 'Test',
            'last_name'   => 'Customer',
            'email'       => 'test_' . substr(md5($unique), 0, 8) . '@example.invalid',
            'street'      => 'Teststr.',
            'house_number'=> '1',
            'postal_code' => '12345',
            'city'        => 'Testhausen',
        ];
        $row = array_merge($defaults, $overrides);

        $stmt = $this->pdo->prepare(
            "INSERT INTO customers (first_name, last_name, email, street, house_number, postal_code, city)
             VALUES (:first_name, :last_name, :email, :street, :house_number, :postal_code, :city)"
        );
        $stmt->execute([
            ':first_name'   => $row['first_name'],
            ':last_name'    => $row['last_name'],
            ':email'        => $row['email'],
            ':street'       => $row['street'],
            ':house_number' => $row['house_number'],
            ':postal_code'  => $row['postal_code'],
            ':city'         => $row['city'],
        ]);
        $row['id'] = (int)$this->pdo->lastInsertId();
        return $row;
    }

    /**
     * Insert an event and return the row including the generated id.
     *
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    protected function createEvent(array $overrides = []): array
    {
        $defaults = [
            'name'                => 'Testevent ' . substr(md5(uniqid('', true)), 0, 6),
            'active'              => 1,
            'sell_tickets'        => 1,
            'show_start_time'     => '20:00:00',
        ];
        $row = array_merge($defaults, $overrides);

        $stmt = $this->pdo->prepare(
            "INSERT INTO events (name, active, sell_tickets, show_start_time)
             VALUES (:name, :active, :sell_tickets, :show_start_time)"
        );
        $stmt->execute([
            ':name'            => $row['name'],
            ':active'          => $row['active'],
            ':sell_tickets'    => $row['sell_tickets'],
            ':show_start_time' => $row['show_start_time'],
        ]);
        $row['id'] = (int)$this->pdo->lastInsertId();
        return $row;
    }

    /**
     * Insert a reservation and return the row including the generated id.
     *
     * If no event_id is supplied, a fresh event is auto-created so the FK
     * constraint is satisfied. Pass `created_at` to backdate a row for
     * cleanup/expiry tests.
     *
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    protected function createReservation(array $overrides = []): array
    {
        if (!isset($overrides['event_id'])) {
            $event = $this->createEvent();
            $overrides['event_id'] = $event['id'];
        }

        $defaults = [
            'session_id'      => 'sess_' . substr(md5(uniqid('', true)), 0, 16),
            'customer_id'     => null,
            'event_date'      => date('Y-m-d'),
            'seat_identifier' => 'A' . random_int(1, 9999),
            'category_id'     => null,
            'status_id'       => 1,
            'is_reduced'      => 0,
            'is_fn_card'      => 0,
            'is_honor_card'   => 0,
            'is_companion'    => 0,
            'voucher_id'      => null,
            'created_at'      => null, // null → use DB default (NOW)
        ];
        $row = array_merge($defaults, $overrides);

        $columns = ['session_id', 'customer_id', 'event_id', 'event_date',
                    'seat_identifier', 'category_id', 'status_id',
                    'is_reduced', 'is_fn_card', 'is_honor_card', 'is_companion', 'voucher_id'];
        $placeholders = array_map(fn($c) => ":$c", $columns);

        $sql = "INSERT INTO reservations (" . implode(', ', $columns)
             . ($row['created_at'] !== null ? ', created_at' : '')
             . ") VALUES (" . implode(', ', $placeholders)
             . ($row['created_at'] !== null ? ', :created_at' : '') . ")";

        $stmt = $this->pdo->prepare($sql);
        $params = [];
        foreach ($columns as $c) {
            $params[":$c"] = $row[$c];
        }
        if ($row['created_at'] !== null) {
            $params[':created_at'] = $row['created_at'];
        }
        $stmt->execute($params);

        $row['id'] = (int)$this->pdo->lastInsertId();
        return $row;
    }

    /**
     * Insert a seat category (auto-creates an event if event_id is omitted).
     *
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    protected function createSeatCategory(array $overrides = []): array
    {
        if (!isset($overrides['event_id'])) {
            $event = $this->createEvent();
            $overrides['event_id'] = $event['id'];
        }

        $defaults = [
            'name'  => 'Test-Kategorie',
            'sign'  => 'T',
            'price' => 20.00,
        ];
        $row = array_merge($defaults, $overrides);

        $stmt = $this->pdo->prepare(
            "INSERT INTO seat_categories (event_id, name, sign, price)
             VALUES (:event_id, :name, :sign, :price)"
        );
        $stmt->execute([
            ':event_id' => $row['event_id'],
            ':name'     => $row['name'],
            ':sign'     => $row['sign'],
            ':price'    => $row['price'],
        ]);
        $row['id'] = (int)$this->pdo->lastInsertId();
        return $row;
    }

    /**
     * Insert a voucher directly (bypasses Voucher::create() and its code
     * generator). Pass `voucher_code` explicitly when a test needs a
     * specific code – e.g. to simulate an old md5-style legacy code or
     * to exercise the uniqueness constraint.
     *
     * Auto-creates an event + category if event_id/category_id are missing.
     *
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    protected function createVoucher(array $overrides = []): array
    {
        if (!isset($overrides['category_id'])) {
            // Auto-create a category; reuse caller's event_id if supplied so
            // the FK chain (voucher.event_id → category.event_id) stays consistent.
            $categoryOverrides = isset($overrides['event_id'])
                ? ['event_id' => $overrides['event_id']]
                : [];
            $category = $this->createSeatCategory($categoryOverrides);
            $overrides['event_id']    ??= $category['event_id'];
            $overrides['category_id']   = $category['id'];
        } elseif (!isset($overrides['event_id'])) {
            // category_id given but no event_id → look up the event_id from the category
            $stmt = $this->pdo->prepare("SELECT event_id FROM seat_categories WHERE id = :id");
            $stmt->execute([':id' => $overrides['category_id']]);
            $overrides['event_id'] = (int)$stmt->fetchColumn();
        }

        $unique   = uniqid('', true);
        $defaults = [
            'voucher_code'    => 'TEST' . strtoupper(substr(md5($unique), 0, 8)),
            'hash'            => hash('sha256', $unique),
            'customer_id'     => null,
            'session_id'      => 'sess_' . substr(md5($unique), 0, 16),
            'purchase_intent' => 'buy',
            'quantity'        => 1,
            'status_id'       => 0,
            'is_reduced'      => 0,
            'is_fn_card'      => 0,
        ];
        $row = array_merge($defaults, $overrides);

        $stmt = $this->pdo->prepare(
            "INSERT INTO vouchers
             (voucher_code, hash, customer_id, session_id, purchase_intent, event_id, category_id, quantity, status_id, is_reduced, is_fn_card)
             VALUES
             (:voucher_code, :hash, :customer_id, :session_id, :purchase_intent, :event_id, :category_id, :quantity, :status_id, :is_reduced, :is_fn_card)"
        );
        $stmt->execute([
            ':voucher_code'    => $row['voucher_code'],
            ':hash'            => $row['hash'],
            ':customer_id'     => $row['customer_id'],
            ':session_id'      => $row['session_id'],
            ':purchase_intent' => $row['purchase_intent'],
            ':event_id'        => $row['event_id'],
            ':category_id'     => $row['category_id'],
            ':quantity'        => $row['quantity'],
            ':status_id'       => $row['status_id'],
            ':is_reduced'      => $row['is_reduced'],
            ':is_fn_card'      => $row['is_fn_card'],
        ]);
        $row['id'] = (int)$this->pdo->lastInsertId();
        return $row;
    }

    /**
     * Convenience: how many rows currently exist in a table inside the
     * test transaction. Useful to assert "before/after" counts.
     */
    protected function rowCount(string $table, string $whereClause = '1=1', array $params = []): int
    {
        $stmt = $this->pdo->prepare("SELECT COUNT(*) AS cnt FROM `{$table}` WHERE {$whereClause}");
        $stmt->execute($params);
        return (int)($stmt->fetch()['cnt'] ?? 0);
    }
}
