<?php

namespace Tests\Unit\Services;

use App\Services\LogCleanupService;
use PHPUnit\Framework\TestCase;

/**
 * Log cleanup: entries of active events are never removed; inactive-event entries
 * can be removed everywhere, everything else only in files older than 3 months.
 */
class LogCleanupServiceTest extends TestCase
{
    private string $dir;

    /** Event 1 is active, event 2 is inactive. Reservation 10 → event 1, 20 → event 2. */
    private function service(): LogCleanupService
    {
        return new LogCleanupService(
            $this->dir,
            [1 => true],
            fn(array $ids) => array_intersect_key([10 => 1, 20 => 2], array_flip($ids))
        );
    }

    private static function line(string $dt, array $context): string
    {
        return '[' . $dt . '] customer.INFO: Action: test ' . json_encode($context) . ' []' . "\n";
    }

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/logcleanup_' . uniqid();
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $f) {
            unlink($f);
        }
        rmdir($this->dir);
    }

    public function testInactiveModeRemovesOnlyEntriesOfInactiveEvents(): void
    {
        $file = $this->dir . '/customer-2026-10-01.log';
        file_put_contents($file,
            self::line('2026-10-01 10:00:00', ['event_id' => 1])            // active → keep
            . self::line('2026-10-01 10:01:00', ['event_id' => 2])          // inactive → remove
            . "  #0 stack trace line\n"                                     // belongs to previous entry
            . self::line('2026-10-01 10:02:00', ['reservation_ids' => [20]]) // inactive via reservation → remove
            . self::line('2026-10-01 10:03:00', ['reservation_ids' => [10, 20]]) // also active → keep
            . self::line('2026-10-01 10:04:00', ['username' => 'admin'])    // no event → keep
        );

        $result = $this->service()->execute(LogCleanupService::MODE_INACTIVE, '2026-11-05');

        self::assertSame(2, $result['removed_entries']);
        self::assertSame(1, $result['rewritten_files']);
        $content = file_get_contents($file);
        self::assertStringContainsString('10:00:00', $content);
        self::assertStringContainsString('10:03:00', $content);
        self::assertStringContainsString('10:04:00', $content);
        self::assertStringNotContainsString('10:01:00', $content);
        self::assertStringNotContainsString('stack trace', $content);
        self::assertStringNotContainsString('10:02:00', $content);
    }

    public function testOlderModeKeepsActiveEventsAndSkipsRecentFiles(): void
    {
        $old = $this->dir . '/admin-2026-07-01.log';
        file_put_contents($old,
            self::line('2026-07-01 10:00:00', ['event_id' => 1])
            . self::line('2026-07-01 10:01:00', ['username' => 'admin'])
        );
        $oldEmpty = $this->dir . '/presales-2026-06-01.log';
        file_put_contents($oldEmpty, self::line('2026-06-01 10:00:00', ['event_id' => 2]));
        $recent = $this->dir . '/admin-2026-09-01.log';
        file_put_contents($recent, self::line('2026-09-01 10:00:00', ['username' => 'admin']));

        $plan = $this->service()->plan(LogCleanupService::MODE_OLDER, '2026-11-05');
        self::assertSame(2, $plan['removed_entries']);
        self::assertFileExists($oldEmpty, 'plan() must not change anything');

        $this->service()->execute(LogCleanupService::MODE_OLDER, '2026-11-05');

        self::assertFileDoesNotExist($oldEmpty);
        self::assertSame(self::line('2026-07-01 10:00:00', ['event_id' => 1]), file_get_contents($old));
        self::assertFileExists($recent);
    }

    public function testTodaysFileIsNeverTouched(): void
    {
        $today = $this->dir . '/customer-2026-11-05.log';
        file_put_contents($today, self::line('2026-11-05 10:00:00', ['event_id' => 2]));

        $result = $this->service()->execute(LogCleanupService::MODE_INACTIVE, '2026-11-05');

        self::assertSame(0, $result['removed_entries']);
        self::assertFileExists($today);
    }

    public function testCutoffIsThreeMonths(): void
    {
        self::assertSame('2026-08-05', LogCleanupService::cutoffDate('2026-11-05'));
    }
}
