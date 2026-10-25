<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Core\Config;
use App\Services\EmailService;
use ReflectionMethod;
use Tests\Support\DatabaseTestCase;

/**
 * Tests for the template-rendering helpers inside App\Services\EmailService.
 *
 * The mail service has two distinct surfaces:
 *   1) actually sending mail (SMTP / PHP mail()) – exercised manually and via
 *      admin/api/admin_send_test_email.php, not covered here,
 *   2) building the HTML body from templates – pure string manipulation that
 *      is easy to break silently. These tests pin that contract.
 *
 * The helpers under test are private; we reach them through Reflection so
 * the tests run without exposing a public test surface on the service.
 *
 * Config is hit during placeholder/logo rendering, so we extend
 * DatabaseTestCase to get a clean DB + transaction-rollback per test.
 */
class EmailServiceTemplateTest extends DatabaseTestCase
{
    private EmailService $service;

    protected function setUp(): void
    {
        parent::setUp();
        Config::clearCache();
        // Constructor reads several Config keys; they all default sensibly.
        $this->service = new EmailService();
    }

    protected function tearDown(): void
    {
        Config::clearCache();
        parent::tearDown();
    }

    private function invoke(string $method, array $args): mixed
    {
        $m = new ReflectionMethod(EmailService::class, $method);
        $m->setAccessible(true);
        return $m->invokeArgs($this->service, $args);
    }

    // ── formatSeatIdentifier() ─────────────────────────────────────

    public function testFormatSeatIdentifierForRegularSeat(): void
    {
        $this->assertSame('Reihe 3, Sitz 12', $this->invoke('formatSeatIdentifier', ['seat_3_12']));
        $this->assertSame('Reihe 1, Sitz 1',  $this->invoke('formatSeatIdentifier', ['seat_1_1']));
        $this->assertSame('Reihe 10, Sitz 5', $this->invoke('formatSeatIdentifier', ['seat_10_5']));
    }

    public function testFormatSeatIdentifierHidesNoSeatplanInternalIds(): void
    {
        // no_seatplan_* identifiers are internal placeholders for table-only
        // / free-seating events – the customer must not see them rendered.
        $this->assertSame('', $this->invoke('formatSeatIdentifier', ['no_seatplan_xyz']));
        $this->assertSame('', $this->invoke('formatSeatIdentifier', ['no_seatplan_abc_123']));
    }

    public function testFormatSeatIdentifierReturnsUnmatchedFormatAsIs(): void
    {
        // Unrecognised formats fall through unchanged – e.g. tickets typed
        // manually at the box office.
        $this->assertSame('VIP-A1',       $this->invoke('formatSeatIdentifier', ['VIP-A1']));
        $this->assertSame('whatever',     $this->invoke('formatSeatIdentifier', ['whatever']));
    }

    public function testFormatSeatIdentifierForWheelchairSeat(): void
    {
        // Wheelchair seats must render as a real label, not the raw identifier.
        $this->assertSame('Reihe 1, Sitz 28 (Rollstuhl)', $this->invoke('formatSeatIdentifier', ['wheelchair_1_28']));
        $this->assertSame('Reihe 10, Sitz 3 (Rollstuhl)', $this->invoke('formatSeatIdentifier', ['wheelchair_10_3']));
    }

    // ── reservationPrice() ─────────────────────────────────────────
    // Delegates to App\Helpers\TicketPrice; the price rule itself is covered by TicketPriceTest.

    public function testReservationPriceIsZeroForCompanion(): void
    {
        // The wheelchair companion is always free – even on a paid category.
        $this->assertSame(0.0, $this->invoke('reservationPrice', [['category_price' => 25.0, 'is_companion' => 1]]));
    }

    public function testBuildSeatListMarksCompanionAsFree(): void
    {
        $html = $this->invoke('buildSeatList', [[
            ['seat_identifier' => 'seat_1_1', 'category_name' => 'A', 'is_companion' => 1],
        ]]);
        $this->assertStringContainsString('Begleitperson kostenfrei', $html);
    }

    // ── buildSeatList() ────────────────────────────────────────────

    public function testBuildSeatListWrapsInUl(): void
    {
        $html = $this->invoke('buildSeatList', [[
            ['seat_identifier' => 'seat_1_1'],
        ]]);
        $this->assertStringStartsWith('<ul>', $html);
        $this->assertStringEndsWith('</ul>', $html);
        $this->assertStringContainsString('<li>Reihe 1, Sitz 1</li>', $html);
    }

    public function testBuildSeatListSortsNumericallyByRowThenSeat(): void
    {
        // The critical property – lexicographic sort would put "10" before
        // "2". The current regex extracts row/seat as integers, so the order
        // must be: row 1, row 2, row 10, then within row 10 the seats 5 → 12.
        $reservations = [
            ['seat_identifier' => 'seat_10_5'],
            ['seat_identifier' => 'seat_2_3'],
            ['seat_identifier' => 'seat_10_12'],
            ['seat_identifier' => 'seat_1_1'],
        ];
        $html = $this->invoke('buildSeatList', [$reservations]);

        $expected = ['Reihe 1, Sitz 1', 'Reihe 2, Sitz 3', 'Reihe 10, Sitz 5', 'Reihe 10, Sitz 12'];
        $positions = array_map(fn($label) => strpos($html, $label), $expected);
        // Each label must appear AND in strictly ascending position.
        foreach ($positions as $i => $pos) {
            $this->assertNotFalse($pos, "Label '{$expected[$i]}' is missing from the rendered list");
        }
        $sorted = $positions;
        sort($sorted);
        $this->assertSame($sorted, $positions, 'Seats were not sorted in the expected order');
    }

    public function testBuildSeatListAppendsCategoryNameInParens(): void
    {
        $html = $this->invoke('buildSeatList', [[
            ['seat_identifier' => 'seat_1_1', 'category_name' => 'Loge'],
        ]]);
        $this->assertStringContainsString('<li>Reihe 1, Sitz 1 (Loge)</li>', $html);
    }

    public function testBuildSeatListSkipsNoSeatplanRows(): void
    {
        $html = $this->invoke('buildSeatList', [[
            ['seat_identifier' => 'seat_1_1'],
            ['seat_identifier' => 'no_seatplan_pseudo_id'],
            ['seat_identifier' => 'seat_2_3'],
        ]]);
        $this->assertStringContainsString('Reihe 1, Sitz 1', $html);
        $this->assertStringContainsString('Reihe 2, Sitz 3', $html);
        $this->assertStringNotContainsString('no_seatplan', $html);
        $this->assertSame(2, substr_count($html, '<li>'));
    }

    // ── replaceEmailPlaceholders() ─────────────────────────────────

    public function testReplacePlaceholdersSubstitutesProvidedValues(): void
    {
        $tpl = 'Hallo {customer_name}, {event_name} am {event_date}.';
        $out = $this->invoke('replaceEmailPlaceholders', [$tpl, [
            'customer_name' => 'Max',
            'event_name'    => 'Theaterabend',
            'event_date'    => '15.05.2026',
        ]]);
        $this->assertStringContainsString('Hallo Max, Theaterabend am 15.05.2026.', $out);
    }

    public function testReplacePlaceholdersReplacesAllOccurrencesOfSameKey(): void
    {
        // str_replace replaces every match, so a name appearing twice (e.g.
        // greeting + footer) gets both substituted.
        $tpl = 'Hallo {customer_name}. Mit freundlichem Gruß an {customer_name}.';
        $out = $this->invoke('replaceEmailPlaceholders', [$tpl, ['customer_name' => 'Max']]);
        $this->assertSame(2, substr_count($out, 'Max'));
        $this->assertStringNotContainsString('{customer_name}', $out);
    }

    public function testReplacePlaceholdersLeavesUnknownTokensInPlace(): void
    {
        // Unknown tokens are left literally in the output. This is the
        // intentional contract – it makes typos visible in the rendered
        // mail instead of silently dropping content.
        $tpl = 'Code: {voucher_code_does_not_exist}';
        $out = $this->invoke('replaceEmailPlaceholders', [$tpl, ['something_else' => 'x']]);
        $this->assertStringContainsString('{voucher_code_does_not_exist}', $out);
    }

    public function testReplacePlaceholdersInjectsCompanyName(): void
    {
        Config::set('company_name', 'Theater AG');
        $tpl = '{company_name} schreibt:';
        $out = $this->invoke('replaceEmailPlaceholders', [$tpl, []]);
        $this->assertStringContainsString('Theater AG schreibt:', $out);
    }

    public function testReplacePlaceholdersAutoInjectsLogoBeforeFirstHeading(): void
    {
        // If a configured logo exists but the template has no {logo_html}
        // placeholder, the renderer auto-injects the logo before the first
        // <h1>/<h2>/... inside any .header div. This guarantees existing
        // templates from before the logo feature still get branding.
        Config::set('company_logo',     'images/logo.png');
        Config::set('ticket_url_start', 'https://tickets.example.de');

        $tpl = '<div class="header"><h1>Willkommen</h1></div>Body…';
        $out = $this->invoke('replaceEmailPlaceholders', [$tpl, []]);

        $this->assertStringContainsString('<img', $out, 'Logo <img> tag must be injected');
        $this->assertStringContainsString('logo.png', $out);
        // Logo must appear BEFORE the heading, not after.
        $this->assertLessThan(
            strpos($out, '<h1'),
            strpos($out, '<img'),
            'Logo must be injected before the first <h1>'
        );
    }

    public function testReplacePlaceholdersDoesNotDuplicateLogoWhenPlaceholderPresent(): void
    {
        // If the template explicitly contains {logo_html}, the placeholder
        // is filled and the auto-inject path must NOT add a second copy.
        Config::set('company_logo',     'images/logo.png');
        Config::set('ticket_url_start', 'https://tickets.example.de');

        $tpl = '{logo_html}<div class="header"><h1>Welcome</h1></div>';
        $out = $this->invoke('replaceEmailPlaceholders', [$tpl, []]);

        $imgCount = substr_count($out, '<img');
        $this->assertSame(1, $imgCount, 'Logo must be present exactly once, not duplicated');
    }

    // ── buildLogoHtml() ────────────────────────────────────────────

    public function testBuildLogoHtmlReturnsEmptyWhenNoLogoConfigured(): void
    {
        Config::set('company_logo', '');
        $this->assertSame('', $this->invoke('buildLogoHtml', []));
    }

    public function testBuildLogoHtmlBuildsAbsoluteUrlFromTicketUrlStart(): void
    {
        Config::set('company_logo',     'images/logo.png');
        Config::set('ticket_url_start', 'https://tickets.example.de');

        $html = $this->invoke('buildLogoHtml', []);
        $this->assertStringContainsString('<img', $html);
        // ensurePublicInUrl() may rewrite the base; the path component must
        // at least be present unchanged.
        $this->assertStringContainsString('logo.png', $html);
        $this->assertStringContainsString('tickets.example.de', $html);
    }
}
