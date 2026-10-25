<?php

declare(strict_types=1);

namespace Tests\Unit\Helpers;

use PHPUnit\Framework\TestCase;

/**
 * Tests for the HMAC token helpers used to authorise public ticket / voucher /
 * seatplan download links.
 *
 * Security-critical: a token must
 *   • be deterministic for the same set of IDs (independent of order),
 *   • differ between purposes (ticket ≠ voucher ≠ seatplan),
 *   • differ between ID sets,
 *   • refuse to operate when APP_SECRET is unset.
 */
class HmacTokenTest extends TestCase
{
    private ?string $previousSecret = null;

    protected function setUp(): void
    {
        // Use a fixed secret per test so tokens are reproducible across runs
        // and independent of whatever the local .env happens to contain.
        $this->previousSecret = $_ENV['APP_SECRET'] ?? null;
        $_ENV['APP_SECRET']   = 'test-secret-for-hmac-tokens';
    }

    protected function tearDown(): void
    {
        if ($this->previousSecret === null) {
            unset($_ENV['APP_SECRET']);
        } else {
            $_ENV['APP_SECRET'] = $this->previousSecret;
        }
    }

    // ── generateTicketDownloadToken ─────────────────────────────────

    public function testTicketTokenIsDeterministicForSameInput(): void
    {
        $a = generateTicketDownloadToken([1, 2, 3]);
        $b = generateTicketDownloadToken([1, 2, 3]);
        $this->assertSame($a, $b);
    }

    public function testTicketTokenIsOrderInvariant(): void
    {
        // The helper sorts internally so URLs survive reordering by the caller
        // (query strings, batch operations) without invalidating the token.
        $sorted   = generateTicketDownloadToken([1, 2, 3]);
        $reversed = generateTicketDownloadToken([3, 2, 1]);
        $shuffled = generateTicketDownloadToken([2, 1, 3]);
        $this->assertSame($sorted, $reversed);
        $this->assertSame($sorted, $shuffled);
    }

    public function testTicketTokenDiffersForDifferentIds(): void
    {
        $this->assertNotSame(
            generateTicketDownloadToken([1, 2, 3]),
            generateTicketDownloadToken([1, 2, 4])
        );
    }

    public function testTicketTokenCoercesStringIdsToIntegers(): void
    {
        // Many callers receive IDs from $_GET as strings; intval normalises them
        // so that '1' and 1 produce the same token.
        $this->assertSame(
            generateTicketDownloadToken([1, 2, 3]),
            generateTicketDownloadToken(['1', '2', '3'])
        );
    }

    public function testTicketTokenIsSha256HexFormat(): void
    {
        $token = generateTicketDownloadToken([42]);
        $this->assertSame(64, strlen($token), 'SHA-256 hex is 64 characters');
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $token);
    }

    public function testTicketTokenThrowsWhenSecretMissing(): void
    {
        unset($_ENV['APP_SECRET']);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/APP_SECRET/');
        generateTicketDownloadToken([1]);
    }

    public function testTicketTokenThrowsWhenSecretEmpty(): void
    {
        $_ENV['APP_SECRET'] = '';
        $this->expectException(\RuntimeException::class);
        generateTicketDownloadToken([1]);
    }

    // ── Cross-purpose separation ────────────────────────────────────

    public function testTokensDifferAcrossPurposes(): void
    {
        // Same IDs, different purposes → different tokens. This is the
        // critical property that prevents a ticket-download token from
        // accidentally working as a voucher-download token.
        $ids       = [10, 20, 30];
        $ticket    = generateTicketDownloadToken($ids);
        $voucher   = generateVoucherDownloadToken($ids);
        $seatplan  = generateSeatplanViewToken($ids);
        $this->assertNotSame($ticket, $voucher);
        $this->assertNotSame($ticket, $seatplan);
        $this->assertNotSame($voucher, $seatplan);
    }

    // ── generateVoucherDownloadToken & generateSeatplanViewToken ────

    public function testVoucherTokenIsDeterministicAndOrderInvariant(): void
    {
        $a = generateVoucherDownloadToken([7, 8, 9]);
        $b = generateVoucherDownloadToken([9, 7, 8]);
        $this->assertSame($a, $b);
    }

    public function testSeatplanTokenIsDeterministicAndOrderInvariant(): void
    {
        $a = generateSeatplanViewToken([100, 200]);
        $b = generateSeatplanViewToken([200, 100]);
        $this->assertSame($a, $b);
    }

    public function testVoucherTokenThrowsWhenSecretMissing(): void
    {
        unset($_ENV['APP_SECRET']);
        $this->expectException(\RuntimeException::class);
        generateVoucherDownloadToken([1]);
    }

    public function testSeatplanTokenThrowsWhenSecretMissing(): void
    {
        unset($_ENV['APP_SECRET']);
        $this->expectException(\RuntimeException::class);
        generateSeatplanViewToken([1]);
    }

    public function testTokensChangeWhenSecretChanges(): void
    {
        $tokenA = generateTicketDownloadToken([1]);
        $_ENV['APP_SECRET'] = 'a-different-secret';
        $tokenB = generateTicketDownloadToken([1]);
        $this->assertNotSame($tokenA, $tokenB);
    }
}
