<?php

declare(strict_types=1);

namespace Tests\Unit\Helpers;

use PHPUnit\Framework\TestCase;
use App\Helpers\UrlHelper;
use ReflectionMethod;

/**
 * Tests for App\Helpers\UrlHelper – the central URL builder.
 *
 * We test the private `detectScheme()` directly via reflection because that
 * keeps the test independent of the database-backed Config preference path
 * (which is exercised in an integration test, not here).
 */
class UrlHelperTest extends TestCase
{
    /** @var array<string, mixed> snapshot of relevant $_SERVER keys */
    private array $serverBackup = [];

    protected function setUp(): void
    {
        // Capture only the keys we plan to mutate so unrelated state survives.
        foreach (['HTTPS', 'HTTP_X_FORWARDED_PROTO', 'HTTP_X_FORWARDED_SSL', 'HTTP_HOST', 'SERVER_NAME'] as $k) {
            $this->serverBackup[$k] = $_SERVER[$k] ?? null;
            unset($_SERVER[$k]);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->serverBackup as $k => $v) {
            if ($v === null) {
                unset($_SERVER[$k]);
            } else {
                $_SERVER[$k] = $v;
            }
        }
    }

    private function invokeDetectScheme(): string
    {
        $m = new ReflectionMethod(UrlHelper::class, 'detectScheme');
        $m->setAccessible(true);
        return (string)$m->invoke(null);
    }

    private function invokeDetectHost(): string
    {
        $m = new ReflectionMethod(UrlHelper::class, 'detectHost');
        $m->setAccessible(true);
        return (string)$m->invoke(null);
    }

    // ── detectScheme() ──────────────────────────────────────────────

    public function testDirectHttpsTriggersHttps(): void
    {
        $_SERVER['HTTPS'] = 'on';
        $this->assertSame('https', $this->invokeDetectScheme());
    }

    public function testHttpsOffYieldsHttp(): void
    {
        $_SERVER['HTTPS'] = 'off';
        $this->assertSame('http', $this->invokeDetectScheme());
    }

    public function testEmptyHttpsYieldsHttp(): void
    {
        $_SERVER['HTTPS'] = '';
        $this->assertSame('http', $this->invokeDetectScheme());
    }

    public function testXForwardedProtoHttpsTriggersHttps(): void
    {
        // Reverse proxy (Cloudflare, nginx, AWS ALB) terminates TLS – PHP only
        // sees HTTP, so we must honour this header to build correct links.
        $_SERVER['HTTP_X_FORWARDED_PROTO'] = 'https';
        $this->assertSame('https', $this->invokeDetectScheme());
    }

    public function testXForwardedProtoIsCaseInsensitive(): void
    {
        $_SERVER['HTTP_X_FORWARDED_PROTO'] = 'HTTPS';
        $this->assertSame('https', $this->invokeDetectScheme());
    }

    public function testXForwardedSslOnTriggersHttps(): void
    {
        $_SERVER['HTTP_X_FORWARDED_SSL'] = 'on';
        $this->assertSame('https', $this->invokeDetectScheme());
    }

    public function testNoIndicatorsFallsBackToHttp(): void
    {
        $this->assertSame('http', $this->invokeDetectScheme());
    }

    public function testDirectHttpsWinsOverConflictingForwardedHeader(): void
    {
        // If PHP itself sees HTTPS, the proxy header should not be able to
        // downgrade the detection.
        $_SERVER['HTTPS'] = 'on';
        $_SERVER['HTTP_X_FORWARDED_PROTO'] = 'http';
        $this->assertSame('https', $this->invokeDetectScheme());
    }

    // ── detectHost() ────────────────────────────────────────────────

    public function testDetectHostPrefersHttpHost(): void
    {
        $_SERVER['HTTP_HOST']   = 'tickets.example.de';
        $_SERVER['SERVER_NAME'] = 'fallback.example.de';
        $this->assertSame('tickets.example.de', $this->invokeDetectHost());
    }

    public function testDetectHostFallsBackToServerName(): void
    {
        $_SERVER['SERVER_NAME'] = 'fallback.example.de';
        $this->assertSame('fallback.example.de', $this->invokeDetectHost());
    }

    public function testDetectHostFallsBackToLocalhostWhenNothingSet(): void
    {
        $this->assertSame('localhost', $this->invokeDetectHost());
    }
}
