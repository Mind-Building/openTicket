<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Models\Event;
use Tests\Support\DatabaseTestCase;

/**
 * Eine Veranstaltung gilt als "vorüber", sobald sie mindestens einen Termin hat
 * und alle Termine vor dem heutigen Tag liegen. Solche Veranstaltungen dürfen im
 * Kundenbereich weder Tickets noch Gutscheine verkaufen und werden vom Cron
 * automatisch deaktiviert.
 */
class EventFinishedTest extends DatabaseTestCase
{
    private Event $events;

    protected function setUp(): void
    {
        parent::setUp();
        $this->events = new Event();
    }

    private function addDate(int $eventId, string $date): void
    {
        $this->events->addDate($eventId, $date);
    }

    public function testEventWithOnlyPastDatesIsFinished(): void
    {
        $event = $this->createEvent();
        $this->addDate((int)$event['id'], date('Y-m-d', strtotime('-10 days')));
        $this->addDate((int)$event['id'], date('Y-m-d', strtotime('-1 day')));

        self::assertTrue($this->events->isFinished((int)$event['id']));
        self::assertSame(date('Y-m-d', strtotime('-1 day')), $this->events->getLastDate((int)$event['id']));
    }

    public function testEventIsNotFinishedOnTheDayOfTheLastPerformance(): void
    {
        // Am Veranstaltungstag regelt die Bestellfrist (isEventDateBookable) die Buchbarkeit
        $event = $this->createEvent();
        $this->addDate((int)$event['id'], date('Y-m-d', strtotime('-3 days')));
        $this->addDate((int)$event['id'], date('Y-m-d'));

        self::assertFalse($this->events->isFinished((int)$event['id']));
    }

    public function testEventWithFutureDateIsNotFinished(): void
    {
        $event = $this->createEvent();
        $this->addDate((int)$event['id'], date('Y-m-d', strtotime('-3 days')));
        $this->addDate((int)$event['id'], date('Y-m-d', strtotime('+7 days')));

        self::assertFalse($this->events->isFinished((int)$event['id']));
    }

    public function testEventWithoutAnyDateIsNeverFinished(): void
    {
        // z. B. reine Gutschein-Veranstaltungen ohne Termine
        $event = $this->createEvent();

        self::assertFalse($this->events->isFinished((int)$event['id']));
        self::assertNull($this->events->getLastDate((int)$event['id']));
    }

    public function testGetByIdAndGetAllExposeIsFinishedFlag(): void
    {
        $past = $this->createEvent();
        $this->addDate((int)$past['id'], date('Y-m-d', strtotime('-2 days')));
        $upcoming = $this->createEvent();
        $this->addDate((int)$upcoming['id'], date('Y-m-d', strtotime('+2 days')));

        self::assertSame(1, (int)$this->events->getById((int)$past['id'])['is_finished']);
        self::assertSame(0, (int)$this->events->getById((int)$upcoming['id'])['is_finished']);

        $ids = array_column($this->events->getAll(true, true), 'id');
        self::assertNotContains($past['id'], $ids);
        self::assertContains($upcoming['id'], $ids);

        // Ohne den Filter bleibt die vergangene Veranstaltung enthalten (Admin-Listen)
        $allIds = array_column($this->events->getAll(), 'id');
        self::assertContains($past['id'], $allIds);
    }

    public function testDeactivateFinishedEventsOnlyTouchesFinishedActiveEvents(): void
    {
        $finished = $this->createEvent(['active' => 1]);
        $this->addDate((int)$finished['id'], date('Y-m-d', strtotime('-1 day')));

        $upcoming = $this->createEvent(['active' => 1]);
        $this->addDate((int)$upcoming['id'], date('Y-m-d', strtotime('+1 day')));

        $withoutDates = $this->createEvent(['active' => 1]);

        $deactivated = $this->events->deactivateFinishedEvents();
        $deactivatedIds = array_column($deactivated, 'id');

        self::assertContains((int)$finished['id'], $deactivatedIds);
        self::assertNotContains((int)$upcoming['id'], $deactivatedIds);
        self::assertNotContains((int)$withoutDates['id'], $deactivatedIds);

        self::assertSame(0, (int)$this->events->getById((int)$finished['id'])['active']);
        self::assertSame(1, (int)$this->events->getById((int)$upcoming['id'])['active']);
        self::assertSame(1, (int)$this->events->getById((int)$withoutDates['id'])['active']);

        // Zweiter Lauf hat nichts mehr zu tun
        $second = array_column($this->events->deactivateFinishedEvents(), 'id');
        self::assertNotContains((int)$finished['id'], $second);
    }
}
