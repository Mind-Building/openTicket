<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Models\Reservation;
use Tests\Support\DatabaseTestCase;

/**
 * Integration tests for the Reservation model.
 *
 * Covers the read-paths and the state-transition write-paths that do NOT
 * open their own database transaction:
 *   • isSeatAvailable() – the seat-locking predicate used by checkout
 *   • update(), updateStatus(), delete() – status-machine transitions
 *   • getById(), getBySession(), getByVoucherId() – fetch helpers
 *   • getReservedSeats() – seat-plan rendering source
 *   • getFnCardCount() – FN-card quota check
 *   • cleanupExpiredCartItems() – cron-job behaviour
 *
 * Reservation::create() and Reservation::rebook() are intentionally NOT
 * covered here because they call beginTransaction()/commit() internally;
 * MySQL does not support real nested transactions, so calling them from
 * within the test-wrapper transaction would throw. They need a different
 * test fixture (truncate-based) and live in their own future test class.
 */
class ReservationTest extends DatabaseTestCase
{
    private Reservation $model;

    protected function setUp(): void
    {
        parent::setUp();
        $this->model = new Reservation();
    }

    // ── isSeatAvailable() ──────────────────────────────────────────

    public function testSeatWithoutReservationIsAvailable(): void
    {
        $event = $this->createEvent();
        $this->assertTrue(
            $this->model->isSeatAvailable($event['id'], '2030-01-01', 'A1')
        );
    }

    public function testSeatHeldInCartRecentlyIsUnavailable(): void
    {
        $event = $this->createEvent();
        $this->createReservation([
            'event_id'        => $event['id'],
            'event_date'      => '2030-01-01',
            'seat_identifier' => 'A1',
            'status_id'       => 1, // in_cart
            // created_at defaults to NOW → still within the 15-min cart window
        ]);

        $this->assertFalse(
            $this->model->isSeatAvailable($event['id'], '2030-01-01', 'A1')
        );
    }

    public function testSeatHeldInCartBeyondLifetimeIsAvailable(): void
    {
        // The seat-lock predicate considers an in_cart row stale once its
        // created_at is older than CART_LIFETIME_MINUTES.
        $event = $this->createEvent();
        $this->createReservation([
            'event_id'        => $event['id'],
            'event_date'      => '2030-01-01',
            'seat_identifier' => 'A1',
            'status_id'       => 1,
            'created_at'      => date('Y-m-d H:i:s', time() - (Reservation::CART_LIFETIME_MINUTES + 5) * 60),
        ]);

        $this->assertTrue(
            $this->model->isSeatAvailable($event['id'], '2030-01-01', 'A1')
        );
    }

    public function testSeatStillBlocksRightBeforeLifetimeEnds(): void
    {
        // Regression guard: the block window and the cleanup window must be the
        // same value. When they diverged (block 15 min, delete 20 min) a row in
        // between existed but no longer blocked, so two sessions could hold one
        // seat - there is no unique key on (event_id, event_date, seat_identifier).
        $event = $this->createEvent();
        $this->createReservation([
            'event_id'        => $event['id'],
            'event_date'      => '2030-01-01',
            'seat_identifier' => 'A1',
            'status_id'       => 1,
            'created_at'      => date('Y-m-d H:i:s', time() - (Reservation::CART_LIFETIME_MINUTES - 1) * 60),
        ]);

        $this->assertFalse(
            $this->model->isSeatAvailable($event['id'], '2030-01-01', 'A1')
        );
    }

    public function testPaidSeatIsUnavailableRegardlessOfAge(): void
    {
        $event = $this->createEvent();
        $this->createReservation([
            'event_id'        => $event['id'],
            'event_date'      => '2030-01-01',
            'seat_identifier' => 'A1',
            'status_id'       => 2, // paid_paypal
            'created_at'      => date('Y-m-d H:i:s', time() - 365 * 86400), // year-old
        ]);

        $this->assertFalse(
            $this->model->isSeatAvailable($event['id'], '2030-01-01', 'A1')
        );
    }

    public function testSeatOnDifferentDateIsIndependent(): void
    {
        $event = $this->createEvent();
        $this->createReservation([
            'event_id'        => $event['id'],
            'event_date'      => '2030-01-01',
            'seat_identifier' => 'A1',
            'status_id'       => 2,
        ]);

        $this->assertTrue(
            $this->model->isSeatAvailable($event['id'], '2030-01-02', 'A1')
        );
    }

    // ── update() / updateStatus() ─────────────────────────────────

    public function testUpdateChangesAllowedFields(): void
    {
        $res = $this->createReservation(['status_id' => 1]);

        $this->model->update($res['id'], [
            'status_id'  => 2,
            'is_reduced' => 1,
        ]);

        $after = $this->model->getById($res['id']);
        $this->assertSame(2, (int)$after['status_id']);
        $this->assertSame(1, (int)$after['is_reduced']);
        $this->assertSame($res['seat_identifier'], $after['seat_identifier']);
    }

    public function testUpdateStatusOnlyTouchesStatusField(): void
    {
        $res = $this->createReservation([
            'status_id'  => 1,
            'is_reduced' => 1,
        ]);

        $this->model->updateStatus($res['id'], 6);

        $after = $this->model->getById($res['id']);
        $this->assertSame(6, (int)$after['status_id']);
        $this->assertSame(1, (int)$after['is_reduced'], 'is_reduced must not be reset by updateStatus');
    }

    public function testUpdateIgnoresUnknownFields(): void
    {
        // The update() method has an explicit whitelist of fields it touches.
        // Passing garbage must not throw, must not insert SQL.
        $res = $this->createReservation(['status_id' => 1]);

        $this->model->update($res['id'], [
            'status_id' => 2,
            'this_field_does_not_exist' => 'ignored',
            'evil; DROP TABLE users; --' => 1,
        ]);

        $after = $this->model->getById($res['id']);
        $this->assertSame(2, (int)$after['status_id']);
    }

    // ── delete() ───────────────────────────────────────────────────

    public function testDeleteRemovesTheRow(): void
    {
        $res = $this->createReservation();
        $this->assertNotNull($this->model->getById($res['id']));

        $this->model->delete($res['id']);

        $this->assertNull($this->model->getById($res['id']));
    }

    // ── getById() / getBySession() ────────────────────────────────

    public function testGetByIdReturnsNullForUnknownId(): void
    {
        $this->assertNull($this->model->getById(999_999_999));
    }

    public function testGetBySessionReturnsOnlyCartItems(): void
    {
        // getBySession() is the cart view: it returns only status_id = 1 rows
        // (in_cart) for the given session. Paid/reserved rows are out of scope –
        // those are surfaced via getAllBySession() instead.
        $event = $this->createEvent();
        $this->createReservation([
            'event_id'  => $event['id'],
            'session_id'=> 'sess_A',
            'status_id' => 2, // paid – must NOT appear
            'seat_identifier' => 'PAID',
        ]);
        $this->createReservation([
            'event_id'  => $event['id'],
            'session_id'=> 'sess_A',
            'status_id' => 1, // in_cart – must appear
            'seat_identifier' => 'CART',
        ]);
        $this->createReservation([
            'event_id'  => $event['id'],
            'session_id'=> 'sess_OTHER',
            'status_id' => 1, // different session – must not appear
            'seat_identifier' => 'OTHER_CART',
        ]);

        $rows = $this->model->getBySession('sess_A');
        $this->assertCount(1, $rows);
        $this->assertSame('CART', $rows[0]['seat_identifier']);
        $this->assertSame(1, (int)$rows[0]['status_id']);
    }

    public function testGetAllBySessionReturnsEverythingForThatSession(): void
    {
        // The "All" variant ignores the status filter and returns all rows.
        $event = $this->createEvent();
        $this->createReservation([
            'event_id'  => $event['id'],
            'session_id'=> 'sess_X',
            'status_id' => 2,
            'seat_identifier' => 'X1',
        ]);
        $this->createReservation([
            'event_id'  => $event['id'],
            'session_id'=> 'sess_X',
            'status_id' => 1,
            'seat_identifier' => 'X2',
        ]);

        $rows = $this->model->getAllBySession('sess_X');
        $this->assertCount(2, $rows);
    }

    // ── getReservedSeats() ─────────────────────────────────────────

    public function testGetReservedSeatsIncludesOnlyActiveReservations(): void
    {
        $event = $this->createEvent();

        // Paid seat – should appear
        $this->createReservation([
            'event_id'  => $event['id'],
            'event_date'=> '2030-01-01',
            'status_id' => 2,
            'seat_identifier' => 'A1',
        ]);
        // VIP – should appear (any status > 1)
        $this->createReservation([
            'event_id'  => $event['id'],
            'event_date'=> '2030-01-01',
            'status_id' => 11,
            'seat_identifier' => 'A2',
        ]);
        // Long-stale cart entry – should NOT appear
        $this->createReservation([
            'event_id'  => $event['id'],
            'event_date'=> '2030-01-01',
            'status_id' => 1,
            'seat_identifier' => 'A3',
            'created_at'=> date('Y-m-d H:i:s', time() - 60 * 60),
        ]);

        // getReservedSeats() returns a map [seat_identifier => status_id].
        $seats = $this->model->getReservedSeats($event['id'], '2030-01-01');
        $this->assertCount(2, $seats);
        $this->assertArrayHasKey('A1', $seats);
        $this->assertArrayHasKey('A2', $seats);
        $this->assertArrayNotHasKey('A3', $seats, 'Stale cart row must not appear');
        $this->assertSame(2,  (int)$seats['A1']);
        $this->assertSame(11, (int)$seats['A2']);
    }

    // ── getFnCardCount() ───────────────────────────────────────────

    // Per-card counting (fn_card_number) is covered by Tests\Unit\Models\FnCardTest.

    // ── touchCartItems() ──────────────────────────────────────────

    public function testTouchRefreshesOwnCartItemAndSavesItFromCleanup(): void
    {
        $event = $this->createEvent();
        $sessionId = 'sess_touch_' . substr(md5(uniqid('', true)), 0, 12);

        // Old enough that the very next cleanup would delete it
        $item = $this->createReservation([
            'event_id'        => $event['id'],
            'event_date'      => '2030-01-01',
            'seat_identifier' => 'T1',
            'session_id'      => $sessionId,
            'status_id'       => 1,
            'created_at'      => date('Y-m-d H:i:s', time() - (Reservation::CART_LIFETIME_MINUTES + 5) * 60),
        ]);

        $result = $this->model->touchCartItems($sessionId);

        $this->assertSame(1, $result['refreshed']);
        $this->assertSame([], $result['conflicts']);

        $this->model->cleanupExpiredCartItems();
        $this->assertNotNull($this->model->getById($item['id']));
    }

    public function testTouchDropsSeatTakenOverByAnotherSession(): void
    {
        // The dangerous case: our row aged past the lifetime, another session
        // legitimately took the seat, and now we come back. Reviving our row
        // would leave two blocking rows for one seat.
        $event = $this->createEvent();
        $sessionId = 'sess_touch_' . substr(md5(uniqid('', true)), 0, 12);

        $ours = $this->createReservation([
            'event_id'        => $event['id'],
            'event_date'      => '2030-01-01',
            'seat_identifier' => 'T2',
            'session_id'      => $sessionId,
            'status_id'       => 1,
            'created_at'      => date('Y-m-d H:i:s', time() - (Reservation::CART_LIFETIME_MINUTES + 5) * 60),
        ]);
        $theirs = $this->createReservation([
            'event_id'        => $event['id'],
            'event_date'      => '2030-01-01',
            'seat_identifier' => 'T2',
            'session_id'      => 'sess_other_party',
            'status_id'       => 1,
        ]);

        $result = $this->model->touchCartItems($sessionId);

        $this->assertSame(0, $result['refreshed']);
        $this->assertCount(1, $result['conflicts']);
        $this->assertSame('T2', $result['conflicts'][0]['seat']);

        // Ours is gone, theirs untouched
        $this->assertNull($this->model->getById($ours['id']));
        $this->assertNotNull($this->model->getById($theirs['id']));
    }

    public function testTouchIgnoresPaidRowsAndOtherSessions(): void
    {
        $event = $this->createEvent();
        $sessionId = 'sess_touch_' . substr(md5(uniqid('', true)), 0, 12);

        // Paid row of the same session: not a cart item, must not be refreshed
        $paid = $this->createReservation([
            'event_id'        => $event['id'],
            'event_date'      => '2030-01-01',
            'seat_identifier' => 'T3',
            'session_id'      => $sessionId,
            'status_id'       => 2,
            'created_at'      => date('Y-m-d H:i:s', time() - 365 * 86400),
        ]);
        // Cart row of a different session: out of scope
        $foreign = $this->createReservation([
            'event_id'        => $event['id'],
            'event_date'      => '2030-01-01',
            'seat_identifier' => 'T4',
            'session_id'      => 'sess_someone_else',
            'status_id'       => 1,
        ]);

        $result = $this->model->touchCartItems($sessionId);

        $this->assertSame(0, $result['refreshed']);
        $this->assertSame([], $result['conflicts']);
        $this->assertNotNull($this->model->getById($paid['id']));
        $this->assertNotNull($this->model->getById($foreign['id']));
    }

    public function testTouchOnEmptyCartIsHarmless(): void
    {
        $result = $this->model->touchCartItems('sess_nothing_here');

        $this->assertSame(['refreshed' => 0, 'conflicts' => []], $result);
    }

    // ── cleanupExpiredCartItems() ─────────────────────────────────

    public function testCleanupRemovesOldCartItemsOnly(): void
    {
        $event = $this->createEvent();

        // Old in_cart row → should be deleted (> 20 min)
        $oldCart = $this->createReservation([
            'event_id'  => $event['id'],
            'status_id' => 1,
            'seat_identifier' => 'OLD',
            'created_at'=> date('Y-m-d H:i:s', time() - 25 * 60),
        ]);
        // Recent in_cart row → should be kept
        $recentCart = $this->createReservation([
            'event_id'  => $event['id'],
            'status_id' => 1,
            'seat_identifier' => 'NEW',
        ]);
        // Old paid row → should be kept (cleanup only touches status_id = 1)
        $oldPaid = $this->createReservation([
            'event_id'  => $event['id'],
            'status_id' => 2,
            'seat_identifier' => 'PAID',
            'created_at'=> date('Y-m-d H:i:s', time() - 365 * 86400),
        ]);

        $deleted = $this->model->cleanupExpiredCartItems();

        $this->assertGreaterThanOrEqual(1, $deleted);
        $this->assertNull($this->model->getById($oldCart['id']));
        $this->assertNotNull($this->model->getById($recentCart['id']));
        $this->assertNotNull($this->model->getById($oldPaid['id']));
    }

    // ── getGrouped(): Standardansicht nur aktive Veranstaltungen ───

    public function testGetGroupedHidesOrdersOfInactiveEventsWhenRequested(): void
    {
        $activeEvent = $this->createEvent(['active' => 1]);
        $inactiveEvent = $this->createEvent(['active' => 0]);

        $activeOrder = $this->createReservation([
            'event_id'  => $activeEvent['id'],
            'status_id' => 4,
        ]);
        $oldOrder = $this->createReservation([
            'event_id'  => $inactiveEvent['id'],
            'status_id' => 4,
        ]);

        $activeOnly = $this->model->getGrouped(['active_events_only' => true]);
        $activeOnlyEventIds = array_column($activeOnly, 'event_id');
        $this->assertContains((int)$activeEvent['id'], array_map('intval', $activeOnlyEventIds));
        $this->assertNotContains((int)$inactiveEvent['id'], array_map('intval', $activeOnlyEventIds));

        // Ohne den Filter sind auch die Bestellungen beendeter Veranstaltungen dabei
        $allEventIds = array_map('intval', array_column($this->model->getGrouped(), 'event_id'));
        $this->assertContains((int)$inactiveEvent['id'], $allEventIds);

        // Gezielte Suche nach der Veranstaltung liefert die alte Bestellung weiterhin
        $byEvent = $this->model->getGrouped(['event_id' => $inactiveEvent['id']]);
        $this->assertCount(1, $byEvent);
        $this->assertSame((int)$oldOrder['id'], (int)$byEvent[0]['first_id']);
        $this->assertNotSame((int)$activeOrder['id'], (int)$byEvent[0]['first_id']);
    }
}
