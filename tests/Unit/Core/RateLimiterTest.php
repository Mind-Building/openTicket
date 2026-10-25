<?php

declare(strict_types=1);

namespace Tests\Unit\Core;

use App\Core\RateLimiter;
use Tests\Support\DatabaseTestCase;

/**
 * Integration tests for App\Core\RateLimiter.
 *
 * Covers the contract that the login flow (fix #4) and the public API
 * guards rely on:
 *   • a fresh key is allowed,
 *   • record() increments attempts,
 *   • exceeding max attempts trips a block,
 *   • reset() clears state,
 *   • a stale window (first_attempt older than $window) resets the counter,
 *   • a stale block (blocked_until in the past) auto-releases,
 *   • keys are independent of one another.
 *
 * Tests that need "expired" timestamps backdate the underlying row directly
 * via PDO – the alternative (sleeping in real time) would make the suite
 * slow and timing-flaky.
 */
class RateLimiterTest extends DatabaseTestCase
{
    public function testFirstCheckOnNewKeyIsAllowed(): void
    {
        $r = RateLimiter::check('test:fresh:' . uniqid(), 5, 900);
        $this->assertTrue($r['allowed']);
        $this->assertSame(4, $r['remaining']); // max - 1 (because the current request counts)
        $this->assertFalse($r['blocked']);
    }

    public function testRecordPersistsAttempts(): void
    {
        $key = 'test:record:' . uniqid();

        RateLimiter::record($key);
        RateLimiter::record($key);
        RateLimiter::record($key);

        $row = $this->pdo->prepare("SELECT attempts FROM rate_limits WHERE `key` = :k");
        $row->execute([':k' => $key]);
        $this->assertSame(3, (int)$row->fetchColumn());
    }

    public function testCheckReflectsRecordedAttempts(): void
    {
        $key = 'test:reflect:' . uniqid();
        RateLimiter::record($key);
        RateLimiter::record($key);

        // 2 attempts recorded, max is 5 → next would be the 3rd, so 2 remaining
        // before exhausting the budget.
        $r = RateLimiter::check($key, 5, 900);
        $this->assertTrue($r['allowed']);
        $this->assertSame(2, $r['remaining']);
    }

    public function testReachingMaxAttemptsBlocksTheKey(): void
    {
        $key = 'test:block:' . uniqid();
        for ($i = 0; $i < 5; $i++) {
            RateLimiter::record($key);
        }

        $r = RateLimiter::check($key, 5, 900);
        $this->assertFalse($r['allowed']);
        $this->assertTrue($r['blocked']);
        $this->assertSame(0, $r['remaining']);

        // The row now has blocked_until set in the future.
        $row = $this->pdo->prepare("SELECT blocked_until FROM rate_limits WHERE `key` = :k");
        $row->execute([':k' => $key]);
        $blockedUntil = $row->fetchColumn();
        $this->assertNotNull($blockedUntil);
        $this->assertGreaterThan(time(), strtotime($blockedUntil));
    }

    public function testResetClearsTheKey(): void
    {
        $key = 'test:reset:' . uniqid();
        for ($i = 0; $i < 5; $i++) {
            RateLimiter::record($key);
        }
        RateLimiter::check($key, 5, 900); // triggers the block

        RateLimiter::reset($key);

        $row = $this->pdo->prepare("SELECT COUNT(*) FROM rate_limits WHERE `key` = :k");
        $row->execute([':k' => $key]);
        $this->assertSame(0, (int)$row->fetchColumn());

        // And a fresh check on the same key is back to "allowed".
        $r = RateLimiter::check($key, 5, 900);
        $this->assertTrue($r['allowed']);
        $this->assertSame(4, $r['remaining']);
    }

    public function testWindowExpiresAndCounterResets(): void
    {
        // After $window seconds the counter is supposed to reset – record()
        // sets first_attempt to NOW(), so we backdate it manually past the
        // window to simulate the passage of time.
        $key = 'test:window:' . uniqid();
        RateLimiter::record($key);
        RateLimiter::record($key);
        RateLimiter::record($key);

        $this->pdo->prepare("UPDATE rate_limits SET first_attempt = DATE_SUB(NOW(), INTERVAL 2 HOUR) WHERE `key` = :k")
            ->execute([':k' => $key]);

        $r = RateLimiter::check($key, 5, 900);
        $this->assertTrue($r['allowed']);
        $this->assertSame(4, $r['remaining'], 'After the window expires, attempts roll back to fresh');
    }

    public function testBlockExpiresAndKeyAutoReleases(): void
    {
        // A blocked key with blocked_until in the past should auto-clear
        // on the next check. Without this, an attacker could DoS a known
        // username permanently after a single burst.
        $key = 'test:blockexp:' . uniqid();
        for ($i = 0; $i < 5; $i++) {
            RateLimiter::record($key);
        }
        RateLimiter::check($key, 5, 900); // sets blocked_until

        $this->pdo->prepare("UPDATE rate_limits SET blocked_until = DATE_SUB(NOW(), INTERVAL 1 HOUR) WHERE `key` = :k")
            ->execute([':k' => $key]);

        $r = RateLimiter::check($key, 5, 900);
        $this->assertTrue($r['allowed']);
        $this->assertFalse($r['blocked']);
    }

    public function testDifferentKeysAreIndependent(): void
    {
        $keyA = 'test:indep:A:' . uniqid();
        $keyB = 'test:indep:B:' . uniqid();
        for ($i = 0; $i < 5; $i++) {
            RateLimiter::record($keyA);
        }

        // keyA is now exhausted, but keyB must be unaffected.
        $a = RateLimiter::check($keyA, 5, 900);
        $b = RateLimiter::check($keyB, 5, 900);
        $this->assertFalse($a['allowed']);
        $this->assertTrue($b['allowed']);
    }

    // ── getClientIp() – pure $_SERVER reading, no DB ──────────────

    public function testGetClientIpPrefersCloudflareConnectingIp(): void
    {
        $backup = $_SERVER;
        try {
            $_SERVER = [
                'HTTP_CF_CONNECTING_IP' => '203.0.113.10',
                'HTTP_X_FORWARDED_FOR'  => '198.51.100.20',
                'REMOTE_ADDR'           => '192.0.2.30',
            ];
            $this->assertSame('203.0.113.10', RateLimiter::getClientIp());
        } finally {
            $_SERVER = $backup;
        }
    }

    public function testGetClientIpHandlesCommaSeparatedForwardedFor(): void
    {
        $backup = $_SERVER;
        try {
            $_SERVER = [
                'HTTP_X_FORWARDED_FOR' => '203.0.113.10, 198.51.100.20, 192.0.2.30',
                'REMOTE_ADDR'          => '127.0.0.1',
            ];
            $this->assertSame('203.0.113.10', RateLimiter::getClientIp());
        } finally {
            $_SERVER = $backup;
        }
    }

    public function testGetClientIpSkipsPrivateRangesAndFallsBackToRemoteAddr(): void
    {
        $backup = $_SERVER;
        try {
            // The forwarded chain only carries private IPs (RFC1918), which
            // FILTER_FLAG_NO_PRIV_RANGE rejects → falls back to REMOTE_ADDR.
            $_SERVER = [
                'HTTP_X_FORWARDED_FOR' => '10.0.0.5',
                'REMOTE_ADDR'          => '192.0.2.30',
            ];
            $this->assertSame('192.0.2.30', RateLimiter::getClientIp());
        } finally {
            $_SERVER = $backup;
        }
    }
}
