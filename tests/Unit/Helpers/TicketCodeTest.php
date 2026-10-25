<?php

declare(strict_types=1);

namespace Tests\Unit\Helpers;

use App\Helpers\TicketCode;
use PHPUnit\Framework\TestCase;

class TicketCodeTest extends TestCase
{
    private const RESERVATION = [
        'id' => 1234,
        'event_id' => 2,
        'event_date' => '2026-10-02',
        'seat_identifier' => 'seat_5_12',
    ];

    private ?string $secretBackup = null;

    protected function setUp(): void
    {
        $this->secretBackup = $_ENV['APP_SECRET'] ?? null;
        $_ENV['APP_SECRET'] = 'test-secret';
    }

    protected function tearDown(): void
    {
        if ($this->secretBackup === null) {
            unset($_ENV['APP_SECRET']);
        } else {
            $_ENV['APP_SECRET'] = $this->secretBackup;
        }
    }

    public function testEmptyPatternUsesTicketNumber(): void
    {
        $this->assertSame('T-00001234', TicketCode::content('', self::RESERVATION));
    }

    public function testAllTokensAreReplaced(): void
    {
        $content = TicketCode::content('{event_id}|{event_date}|{reservation_id}|{row}/{seat}|{seat_identifier}', self::RESERVATION);
        $this->assertSame('2|2026-10-02|1234|5/12|seat_5_12', $content);
    }

    public function testFreeTextIsKept(): void
    {
        $this->assertSame('FREISCHUETZ-T-00001234', TicketCode::content('FREISCHUETZ-{ticket_number}', self::RESERVATION));
    }

    public function testTableSeatGivesTableAndSeat(): void
    {
        $content = TicketCode::content('{row}-{seat}', ['seat_identifier' => 'tableseat_3_2'] + self::RESERVATION);
        $this->assertSame('3-2', $content);
    }

    public function testCheckCodeIsStableAndSecretBound(): void
    {
        $check = TicketCode::checkCode(1234);
        $this->assertMatchesRegularExpression('/^[0-9A-F]{8}$/', $check);
        $this->assertSame($check, TicketCode::checkCode(1234));
        $this->assertNotSame($check, TicketCode::checkCode(1235));

        $_ENV['APP_SECRET'] = 'other-secret';
        $this->assertNotSame($check, TicketCode::checkCode(1234));
    }

    public function testCode128IsAsciiOnly(): void
    {
        $content = TicketCode::content('Freischütz €{reservation_id}', self::RESERVATION, TicketCode::TYPE_CODE128);
        $this->assertSame('Freischuetz 1234', $content);
    }

    public function testQrKeepsUnicode(): void
    {
        $this->assertSame('Freischütz', TicketCode::content('Freischütz', self::RESERVATION, TicketCode::TYPE_QR));
    }
}
