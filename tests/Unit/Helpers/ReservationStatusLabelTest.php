<?php

declare(strict_types=1);

namespace Tests\Unit\Helpers;

use PHPUnit\Framework\TestCase;

/**
 * Tests for reservationStatusLabel() – the single source of truth for
 * human-readable reservation status names.
 *
 * Every UI surface that shows status labels routes through this helper,
 * so a broken mapping silently breaks reports, the voucher overview and
 * the chart legends.
 */
class ReservationStatusLabelTest extends TestCase
{
    /**
     * @return iterable<string, array{int, string}>
     */
    public static function knownStatusProvider(): iterable
    {
        // These mirror the rows in `reservation_statuses` (schema.sql) and
        // act as a guard against either side drifting out of sync.
        yield 'not_redeemed'        => [0,  'Nicht eingelöst'];
        yield 'in_cart'             => [1,  'Im Warenkorb'];
        yield 'paid_paypal'         => [2,  'Bezahlt (PayPal)'];
        yield 'reserved_presales'   => [3,  'Reserviert (Vorverkauf)'];
        yield 'paid_presales'       => [4,  'Bezahlt & Abgeholt'];
        yield 'cash_payment'        => [5,  'Barzahlung'];
        yield 'reserved_online'     => [6,  'Reserviert (Online)'];
        yield 'printed_box_office'  => [7,  'Gedruckt (Abendkasse)'];
        yield 'paid_voucher'        => [8,  'Bezahlt (Gutschein)'];
        yield 'voucher_redeemed'    => [9,  'Gutschein eingelöst'];
        yield 'fn_card_presales'    => [10, 'FN-Karte'];
        yield 'vip'                 => [11, 'VIP'];
        yield 'paypal_started'      => [20, 'PayPal gestartet'];
        yield 'paypal_error'        => [91, 'FEHLER: PayPal ohne Kundendaten'];
    }

    /**
     * @dataProvider knownStatusProvider
     */
    public function testKnownStatusIdsReturnExpectedLabel(int $id, string $expected): void
    {
        $this->assertSame($expected, reservationStatusLabel($id));
    }

    public function testUnknownStatusIdReturnsFallbackContainingTheId(): void
    {
        $label = reservationStatusLabel(999);
        // The exact text isn't load-bearing, but it must
        //   • not match any known label (so callers can spot it),
        //   • include the original ID for debugging.
        $this->assertStringContainsString('999', $label);
        $this->assertStringContainsString('Unbekannt', $label);
    }

    public function testUnknownStatusIdReturnsFallbackForNegativeId(): void
    {
        $label = reservationStatusLabel(-1);
        $this->assertStringContainsString('-1', $label);
    }
}
