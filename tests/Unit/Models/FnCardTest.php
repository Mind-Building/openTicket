<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Core\Config;
use App\Helpers\SalePriceChoices;
use App\Helpers\TicketPrice;
use App\Models\Reservation;
use Tests\Support\DatabaseTestCase;

/**
 * FN card: the card number is stored on the reservation and the limit (setting max_fn_card)
 * applies per card number and event - not to all FN card tickets of the event.
 */
class FnCardTest extends DatabaseTestCase
{
    private array $settingsBackup = [];

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['fn_card_active', 'max_fn_card', 'fn_card_factor'] as $key) {
            $this->settingsBackup[$key] = Config::get($key);
        }
        Config::set('fn_card_active', true, 'boolean');
        Config::set('max_fn_card', 2, 'integer');
        Config::set('fn_card_factor', '0.1', 'string');
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        Config::set('fn_card_active', (bool)$this->settingsBackup['fn_card_active'], 'boolean');
        Config::set('max_fn_card', (int)($this->settingsBackup['max_fn_card'] ?? 2), 'integer');
        Config::set('fn_card_factor', (string)($this->settingsBackup['fn_card_factor'] ?? '0.1'), 'string');
    }

    /** @return array{0: int, 1: int} event id and category id (FN card allowed, 40 €) */
    private function fnEvent(): array
    {
        $event = $this->createEvent();
        $this->pdo->prepare('UPDATE events SET allow_fn_card = 1 WHERE id = ?')->execute([$event['id']]);
        $category = $this->createSeatCategory(['event_id' => $event['id'], 'price' => 40.00]);
        return [(int)$event['id'], (int)$category['id']];
    }

    private function soldFnTicket(int $eventId, int $categoryId, string $number, string $date = '+30 days'): int
    {
        $reservation = $this->createReservation([
            'event_id' => $eventId,
            'category_id' => $categoryId,
            'event_date' => date('Y-m-d', strtotime($date)),
            'status_id' => 2,
            'is_fn_card' => 1,
        ]);
        (new Reservation())->update((int)$reservation['id'], ['fn_card_number' => $number]);
        return (int)$reservation['id'];
    }

    public function testNumberIsStoredAndPriceDiscounted(): void
    {
        [$eventId, $categoryId] = $this->fnEvent();
        $id = $this->soldFnTicket($eventId, $categoryId, '12345678');

        $stored = (new Reservation())->getById($id);
        self::assertSame('12345678', $stored['fn_card_number']);
        self::assertEqualsWithDelta(36.0, TicketPrice::forReservation($stored)['price'], 0.001);
    }

    public function testLimitCountsOnlyTheSameCardInTheSameEvent(): void
    {
        [$eventId, $categoryId] = $this->fnEvent();
        [$otherEventId, $otherCategoryId] = $this->fnEvent();
        $model = new Reservation();

        $this->soldFnTicket($eventId, $categoryId, '11111111');
        $this->soldFnTicket($eventId, $categoryId, '11111111', '+31 days'); // other date, same event
        $this->soldFnTicket($eventId, $categoryId, '22222222');
        $this->soldFnTicket($otherEventId, $otherCategoryId, '11111111');

        self::assertSame(2, $model->getFnCardCount('11111111', $eventId));
        self::assertSame(1, $model->getFnCardCount('22222222', $eventId));
        self::assertSame(0, $model->getFnCardCount('33333333', $eventId));
    }

    public function testValidNumberFormat(): void
    {
        self::assertTrue(Reservation::isValidFnCardNumber('12345678'));
        self::assertFalse(Reservation::isValidFnCardNumber('1234567'));
        self::assertFalse(Reservation::isValidFnCardNumber('1234567a'));
        self::assertFalse(Reservation::isValidFnCardNumber(''));
    }

    public function testSaleRejectsCardOverTheLimit(): void
    {
        [$eventId, $categoryId] = $this->fnEvent();
        $this->soldFnTicket($eventId, $categoryId, '11111111');
        $date = date('Y-m-d', strtotime('+30 days'));
        $seats = [['id' => 'seat_1_1', 'category' => $categoryId], ['id' => 'seat_1_2', 'category' => $categoryId]];

        // 1 already sold + 2 in this order > 2
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('höchstens 2 Tickets');
        SalePriceChoices::apply($seats, ['fn_card', 'fn_card'], $eventId, $date, ['11111111', '11111111']);
    }

    public function testSaleAcceptsCardWithinTheLimitAndKeepsNumber(): void
    {
        [$eventId, $categoryId] = $this->fnEvent();
        $date = date('Y-m-d', strtotime('+30 days'));
        $seats = [['id' => 'seat_1_1', 'category' => $categoryId], ['id' => 'seat_1_2', 'category' => $categoryId]];

        $result = SalePriceChoices::apply($seats, ['fn_card', 'normal'], $eventId, $date, ['11111111', '']);

        self::assertSame(1, $result[0]['is_fn_card']);
        self::assertSame('11111111', $result[0]['fn_card_number']);
        self::assertSame(0, $result[1]['is_fn_card']);
        self::assertNull($result[1]['fn_card_number']);
    }

    public function testSaleRequiresValidNumberForFnCard(): void
    {
        [$eventId, $categoryId] = $this->fnEvent();
        $this->expectException(\InvalidArgumentException::class);
        SalePriceChoices::apply([['id' => 'seat_1_1', 'category' => $categoryId]], ['fn_card'], $eventId, date('Y-m-d', strtotime('+30 days')), ['123']);
    }
}
