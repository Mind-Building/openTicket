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

    public function testPercentageDiscountOnNormalPrice(): void
    {
        $result = $this->calc(['discount_percent' => '10.00', 'discount_label' => 'Gruppe 10 %']);
        $this->assertSame(TicketPrice::TYPE_DISCOUNT, $result['type']);
        $this->assertSame(37.8, $result['price']);
        $this->assertSame('Gruppe 10 %', TicketPrice::label($result));
    }

    public function testFiftyPercentDiscountIgnoresReducedFlag(): void
    {
        $result = $this->calc(['discount_percent' => 50, 'discount_label' => '50 % Rabatt', 'is_reduced' => 1]);
        $this->assertSame(21.0, $result['price']);
        $this->assertSame('50 % Rabatt', TicketPrice::label($result));
    }

    public function testDiscountWithoutLabelGetsGeneratedLabel(): void
    {
        $this->assertSame('15 % Rabatt', TicketPrice::label($this->calc(['discount_percent' => 15])));
        $this->assertSame('12,5 % Rabatt', TicketPrice::label($this->calc(['discount_percent' => 12.5])));
    }

    public function testCompanionAndRehearsalWinOverDiscount(): void
    {
        $this->assertSame(TicketPrice::TYPE_COMPANION, $this->calc(['is_companion' => 1, 'discount_percent' => 10])['type']);
        $this->assertSame(TicketPrice::TYPE_REHEARSAL, $this->calc(['discount_percent' => 10], self::CATEGORY, true)['type']);
    }

    public function testInvalidDiscountPercentIsIgnored(): void
    {
        $this->assertSame(TicketPrice::TYPE_NORMAL, $this->calc(['discount_percent' => 0])['type']);
        $this->assertSame(TicketPrice::TYPE_NORMAL, $this->calc(['discount_percent' => 150])['type']);
    }

    public function testLabelFallsBackToTypeLabel(): void
    {
        $this->assertSame('Ermäßigt', TicketPrice::label(['price' => 30.0, 'type' => TicketPrice::TYPE_REDUCED]));
        $this->assertSame('', TicketPrice::label(['price' => 42.0, 'type' => TicketPrice::TYPE_NORMAL]));
    }

    public function testParseDiscountsDefaultsWhenNeverSaved(): void
    {
        $discounts = TicketPrice::parseDiscounts(null);
        $this->assertSame([
            'discount:0' => ['label' => '50 % Rabatt', 'percent' => 50.0],
            'discount:1' => ['label' => 'Gruppe 10 %', 'percent' => 10.0],
            'discount:2' => ['label' => 'Gruppe 15 %', 'percent' => 15.0],
        ], $discounts);
    }

    public function testParseDiscountsSkipsInvalidEntriesAndKeepsIndexes(): void
    {
        $json = json_encode([
            ['label' => 'Gruppe 10 %', 'percent' => 10],
            ['label' => '', 'percent' => 20],
            ['label' => 'Zu viel', 'percent' => 120],
            ['label' => 'Komma', 'percent' => '7,5'],
        ]);
        $this->assertSame([
            'discount:0' => ['label' => 'Gruppe 10 %', 'percent' => 10.0],
            'discount:3' => ['label' => 'Komma', 'percent' => 7.5],
        ], TicketPrice::parseDiscounts($json));
    }

    public function testParseDiscountsEmptyListMeansNoDiscounts(): void
    {
        $this->assertSame([], TicketPrice::parseDiscounts('[]'));
    }
}
