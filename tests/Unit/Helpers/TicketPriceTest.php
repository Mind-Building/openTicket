<?php

declare(strict_types=1);

namespace Tests\Unit\Helpers;

use App\Helpers\TicketPrice;
use PHPUnit\Framework\TestCase;

/**
 * TicketPrice::calculate() is the one price rule for tickets, mails, order details and PayPal.
 */
class TicketPriceTest extends TestCase
{
    private const CATEGORY = ['price' => '42.00', 'reduced_price' => '30.00', 'general_rehearsal_price' => '15.00'];

    private function calc(array $flags, ?array $category = self::CATEGORY, bool $rehearsal = false, bool $fnAllowed = true): array
    {
        return TicketPrice::calculate($flags, $category, $rehearsal, $fnAllowed, 0.1);
    }

    public function testNormalPrice(): void
    {
        $this->assertSame(['price' => 42.0, 'type' => TicketPrice::TYPE_NORMAL], $this->calc([]));
    }

    public function testReducedUsesCategoryReducedPriceNotHalf(): void
    {
        $this->assertSame(['price' => 30.0, 'type' => TicketPrice::TYPE_REDUCED], $this->calc(['is_reduced' => 1]));
    }

    public function testReducedWithoutReducedPriceFallsBackToNormal(): void
    {
        $category = ['price' => '42.00', 'reduced_price' => null];
        $this->assertSame(['price' => 42.0, 'type' => TicketPrice::TYPE_NORMAL], $this->calc(['is_reduced' => 1], $category));
    }

    public function testGeneralRehearsalTakesPrecedenceOverReduction(): void
    {
        $this->assertSame(['price' => 15.0, 'type' => TicketPrice::TYPE_REHEARSAL], $this->calc(['is_reduced' => 1], self::CATEGORY, true));
    }

    public function testRehearsalWithoutRehearsalPriceUsesNormalRules(): void
    {
        $category = ['price' => '42.00', 'reduced_price' => '30.00', 'general_rehearsal_price' => null];
        $this->assertSame(['price' => 30.0, 'type' => TicketPrice::TYPE_REDUCED], $this->calc(['is_reduced' => 1], $category, true));
    }

    public function testFnCardDiscount(): void
    {
        $result = $this->calc(['is_fn_card' => 1]);
        $this->assertSame(TicketPrice::TYPE_FN_CARD, $result['type']);
        $this->assertEqualsWithDelta(37.8, $result['price'], 0.0001);
    }

    public function testFnCardIgnoredWhenNotAllowed(): void
    {
        $this->assertSame(['price' => 42.0, 'type' => TicketPrice::TYPE_NORMAL], $this->calc(['is_fn_card' => 1], self::CATEGORY, false, false));
    }

    public function testCompanionIsFree(): void
    {
        $this->assertSame(['price' => 0.0, 'type' => TicketPrice::TYPE_COMPANION], $this->calc(['is_companion' => 1, 'is_reduced' => 1]));
    }

    public function testNoCategoryIsZero(): void
    {
        $this->assertSame(0.0, $this->calc([], null)['price']);
    }

    public function testTypeLabels(): void
    {
        $this->assertSame('Ermäßigt', TicketPrice::typeLabel(TicketPrice::TYPE_REDUCED));
        $this->assertSame('', TicketPrice::typeLabel(TicketPrice::TYPE_NORMAL));
    }
}
