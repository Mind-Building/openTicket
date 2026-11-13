<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\EmailService;
use PHPUnit\Framework\TestCase;

/**
 * "Ihre Reservierung" always shows the total, also with templates saved without {price}.
 */
class ReservationEmailTotalTest extends TestCase
{
    public function testTemplateWithPriceIsUnchanged(): void
    {
        $template = '<p>{seat_list}</p><p>Preis: {price}</p>';
        $this->assertSame($template, EmailService::ensureTotalPrice($template));
    }

    public function testTotalInsertedAfterSeatList(): void
    {
        $result = EmailService::ensureTotalPrice("<div>\n{seat_list}\n<p>Mit freundlichen Grüßen</p></div>");
        $this->assertStringContainsString("{seat_list}\n<p><strong>Gesamtsumme:</strong> {price}</p>", $result);
        $this->assertSame(1, substr_count($result, '{price}'));
    }

    public function testTotalInsertedBeforeGreetingWithoutSeatList(): void
    {
        $result = EmailService::ensureTotalPrice('<p>Hallo</p><p>Mit freundlichen Grüßen<br>Team</p>');
        $this->assertStringStartsWith('<p>Hallo</p><p><strong>Gesamtsumme:</strong> {price}</p>', $result);
    }

    public function testTotalAppendedAsLastResort(): void
    {
        $result = EmailService::ensureTotalPrice('Hallo');
        $this->assertStringEndsWith('<p><strong>Gesamtsumme:</strong> {price}</p>', $result);
    }
}
