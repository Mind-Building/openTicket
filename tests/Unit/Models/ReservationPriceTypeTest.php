<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Core\Config;
use App\Helpers\TicketPrice;
use App\Models\Reservation;
use Tests\Support\DatabaseTestCase;

/**
 * The price type a customer picks at checkout must be stored on the reservation - it is what
 * PayPal charges (TicketPrice::forReservation()). Before, a chosen reduction only changed the
 * displayed price and PayPal still charged the normal price.
 */
class ReservationPriceTypeTest extends DatabaseTestCase
{
    private mixed $reducedSettingBackup;

    protected function setUp(): void
    {
        parent::setUp();
        $this->reducedSettingBackup = Config::get('reduced_prices_active', false);
        Config::set('reduced_prices_active', true, 'boolean');
    }

    protected function tearDown(): void
    {
        parent::tearDown(); // rolls back the DB; restore the cached setting as well
        Config::set('reduced_prices_active', (bool)$this->reducedSettingBackup, 'boolean');
    }

    /** @return array{0: array, 1: array} reservation row and the event */
    private function cartSeat(bool $eventAllowsReduction, ?float $reducedPrice): array
    {
        $event = $this->createEvent();
        $this->pdo->prepare('UPDATE events SET allow_reduced_prices = ? WHERE id = ?')
            ->execute([$eventAllowsReduction ? 1 : 0, $event['id']]);
        $category = $this->createSeatCategory(['event_id' => $event['id'], 'price' => 42.00]);
        $this->pdo->prepare('UPDATE seat_categories SET reduced_price = ? WHERE id = ?')
            ->execute([$reducedPrice, $category['id']]);

        $reservation = $this->createReservation([
            'event_id' => $event['id'],
            'category_id' => $category['id'],
            'event_date' => date('Y-m-d', strtotime('+30 days')),
        ]);
        return [(new Reservation())->getById((int)$reservation['id']), $event];
    }

    public function testChosenReductionIsStoredAndCharged(): void
    {
        [$item] = $this->cartSeat(true, 30.00);
        $model = new Reservation();

        self::assertSame(1, $model->applyCustomerPriceTypes([$item], [$item['id'] => 'reduced']));

        $stored = $model->getById((int)$item['id']);
        self::assertSame(1, (int)$stored['is_reduced']);
        self::assertSame(30.0, TicketPrice::forReservation($stored)['price']);
    }

    public function testSwitchingBackToNormalClearsTheReduction(): void
    {
        [$item] = $this->cartSeat(true, 30.00);
        $model = new Reservation();
        $model->applyCustomerPriceTypes([$item], [$item['id'] => 'reduced']);

        $item = $model->getById((int)$item['id']);
        $model->applyCustomerPriceTypes([$item], [(string)$item['id'] => 'normal']);

        self::assertSame(0, (int)$model->getById((int)$item['id'])['is_reduced']);
    }

    public function testReductionIsRejectedWhenTheEventDoesNotAllowIt(): void
    {
        [$item] = $this->cartSeat(false, 30.00);
        $model = new Reservation();

        self::assertSame(0, $model->applyCustomerPriceTypes([$item], [$item['id'] => 'reduced']));
        self::assertSame(0, (int)$model->getById((int)$item['id'])['is_reduced']);
    }

    public function testReductionIsRejectedWithoutReducedPrice(): void
    {
        [$item] = $this->cartSeat(true, null);
        $model = new Reservation();

        $model->applyCustomerPriceTypes([$item], [$item['id'] => 'reduced']);
        self::assertSame(0, (int)$model->getById((int)$item['id'])['is_reduced']);
    }

    public function testFnCardChosenButNotValidatedIsReported(): void
    {
        [$item] = $this->cartSeat(true, 30.00);
        $model = new Reservation();

        // Customer picked FN card, but the number check never set is_fn_card
        self::assertSame([(int)$item['id']], $model->unvalidatedFnCardItems([$item], [$item['id'] => 'fncard']));

        // After the check (is_fn_card = 1) the order may go to PayPal
        $model->update((int)$item['id'], ['is_fn_card' => 1]);
        $validated = $model->getById((int)$item['id']);
        self::assertSame([], $model->unvalidatedFnCardItems([$validated], [$item['id'] => 'fncard']));

        // Other price types are never blocked
        self::assertSame([], $model->unvalidatedFnCardItems([$item], [$item['id'] => 'reduced']));
    }

    public function testItemsWithoutChoiceAreLeftAlone(): void
    {
        [$item] = $this->cartSeat(true, 30.00);
        $model = new Reservation();

        self::assertSame(0, $model->applyCustomerPriceTypes([$item], [999999 => 'reduced']));
    }
}
