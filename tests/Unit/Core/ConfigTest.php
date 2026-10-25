<?php

declare(strict_types=1);

namespace Tests\Unit\Core;

use App\Core\Config;
use Tests\Support\DatabaseTestCase;

/**
 * Integration tests for App\Core\Config.
 *
 * Config caches the whole configurations table in a static array on first
 * read. That cache is the most likely source of subtle bugs: a wrong
 * cache-invalidation breaks features in non-obvious ways (e.g. PayPal
 * suddenly disabled, email templates frozen on the previous request, etc.).
 *
 * Every test starts by calling clearCache() so test isolation does not
 * depend on PHPUnit-process restarts.
 */
class ConfigTest extends DatabaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Drop the static cache so tests cannot read each other's writes.
        // The DB transaction rollback hides the rows themselves, but PHP
        // statics survive across tests in the same process.
        Config::clearCache();
    }

    protected function tearDown(): void
    {
        Config::clearCache();
        parent::tearDown();
    }

    // ── get() ──────────────────────────────────────────────────────

    public function testGetReturnsDefaultForUnknownKey(): void
    {
        $this->assertSame('fallback-value', Config::get('definitely_not_in_db', 'fallback-value'));
        $this->assertNull(Config::get('definitely_not_in_db'));
    }

    public function testGetReadsExistingDbRow(): void
    {
        $this->pdo->prepare("INSERT INTO configurations (`key`, `value`, `type`) VALUES (:k, :v, 'string')")
            ->execute([':k' => 'cfg_test_string', ':v' => 'hello world']);

        $this->assertSame('hello world', Config::get('cfg_test_string'));
    }

    // ── set() ──────────────────────────────────────────────────────

    public function testSetUpsertsAndPersistsToDatabase(): void
    {
        Config::set('cfg_test_upsert', 'first');
        $row = $this->pdo->prepare("SELECT `value` FROM configurations WHERE `key` = :k");
        $row->execute([':k' => 'cfg_test_upsert']);
        $this->assertSame('first', $row->fetchColumn());

        // Second set with same key → UPDATE path (ON DUPLICATE KEY) must hit.
        Config::set('cfg_test_upsert', 'second');
        $row->execute([':k' => 'cfg_test_upsert']);
        $this->assertSame('second', $row->fetchColumn());

        // And no duplicate row was inserted.
        $count = $this->pdo->prepare("SELECT COUNT(*) FROM configurations WHERE `key` = :k");
        $count->execute([':k' => 'cfg_test_upsert']);
        $this->assertSame(1, (int)$count->fetchColumn());
    }

    public function testSetUpdatesCacheImmediately(): void
    {
        Config::set('cfg_test_cache_update', 'v1');
        $this->assertSame('v1', Config::get('cfg_test_cache_update'));

        Config::set('cfg_test_cache_update', 'v2');
        $this->assertSame('v2', Config::get('cfg_test_cache_update'),
            'set() must refresh the cache so the next get() reflects the new value');
    }

    // ── type handling ─────────────────────────────────────────────

    public function testIntegerTypeIsCoercedOnRead(): void
    {
        Config::set('cfg_test_int', 42, 'integer');
        $value = Config::get('cfg_test_int');
        $this->assertSame(42, $value);
        $this->assertIsInt($value);
    }

    public function testBooleanTypeIsCoercedOnRead(): void
    {
        Config::set('cfg_test_bool_true',  true,  'boolean');
        Config::set('cfg_test_bool_false', false, 'boolean');

        $this->assertTrue(Config::get('cfg_test_bool_true'));
        $this->assertFalse(Config::get('cfg_test_bool_false'));
    }

    public function testBooleanTypeRoundTripsThroughTheDatabase(): void
    {
        Config::set('cfg_test_bool_persist', true, 'boolean');
        Config::clearCache(); // force a fresh load from DB

        $this->assertTrue(Config::get('cfg_test_bool_persist'));
    }

    public function testJsonTypeIsDecodedOnRead(): void
    {
        $payload = ['allowed' => ['admin', 'presales'], 'flags' => ['x' => 1]];
        Config::set('cfg_test_json', $payload, 'json');

        $loaded = Config::get('cfg_test_json');
        $this->assertSame($payload, $loaded);
    }

    public function testJsonTypeRoundTripsThroughTheDatabase(): void
    {
        $payload = ['nested' => ['key' => 'val', 'n' => 7]];
        Config::set('cfg_test_json_persist', $payload, 'json');
        Config::clearCache();

        $this->assertSame($payload, Config::get('cfg_test_json_persist'));
    }

    // ── clearCache() ──────────────────────────────────────────────

    public function testClearCacheForcesReloadFromDatabase(): void
    {
        // Warm the cache so subsequent direct DB writes are not picked up
        // automatically (the whole point of clearCache).
        Config::get('cfg_test_clearcache');
        $this->assertNull(Config::get('cfg_test_clearcache'));

        $this->pdo->prepare("INSERT INTO configurations (`key`, `value`, `type`) VALUES (:k, 'after-clear', 'string')")
            ->execute([':k' => 'cfg_test_clearcache']);

        // Without clearCache(), the stale cache would still report null:
        $this->assertNull(Config::get('cfg_test_clearcache'),
            'A direct DB write must not be visible through the cache');

        Config::clearCache();
        $this->assertSame('after-clear', Config::get('cfg_test_clearcache'));
    }
}
