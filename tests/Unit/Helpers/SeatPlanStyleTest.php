<?php

declare(strict_types=1);

namespace Tests\Unit\Helpers;

use App\Helpers\SeatPlanStyle;
use PHPUnit\Framework\TestCase;

/**
 * Colours of distances and stairs per event (Veranstaltung bearbeiten → Sitzplan-Darstellung).
 */
class SeatPlanStyleTest extends TestCase
{
    public function testDefaultsWithoutEventSettings(): void
    {
        $this->assertSame([
            'distance_color' => null,
            'distance_border' => false,
            'stairs_color' => '#ffffff',
            'stairs_border' => true,
        ], SeatPlanStyle::forEvent(null));
    }

    public function testNormalDistanceUntouchedByDefault(): void
    {
        // Views keep their own distance styling when the event sets nothing
        $this->assertSame('', SeatPlanStyle::elementAttr(['type' => 'distance'], ['seatplan_distance_color' => null]));
    }

    public function testDefaultStairsWhiteWithBlackBorder(): void
    {
        $attr = SeatPlanStyle::elementAttr(['type' => 'distance', 'stairs' => 1], null);
        $this->assertStringContainsString('data-stairs="1"', $attr);
        $this->assertStringContainsString('background:#ffffff !important;', $attr);
        $this->assertStringContainsString('border:2px solid #000 !important;', $attr);
        $this->assertStringContainsString('color:#000 !important;', $attr);
    }

    public function testCustomDistanceColour(): void
    {
        $event = ['seatplan_distance_color' => '#1E90FF', 'seatplan_distance_border' => 0];
        $attr = SeatPlanStyle::elementAttr(['type' => 'distance'], $event);
        $this->assertStringContainsString('background:#1e90ff !important;', $attr);
        $this->assertStringContainsString('border:2px solid #1e90ff !important;', $attr);
    }

    public function testDistanceBorderOnlyUsesDefaultGrey(): void
    {
        $css = SeatPlanStyle::distanceCss(['seatplan_distance_border' => 1]);
        $this->assertStringContainsString('background:#6c757d', $css);
        $this->assertStringContainsString('border:2px solid #000', $css);
    }

    public function testStairsWithoutBorder(): void
    {
        $css = SeatPlanStyle::stairsCss(['seatplan_stairs_color' => '#000', 'seatplan_stairs_border' => 0]);
        $this->assertStringContainsString('background:#000000 !important;', $css);
        $this->assertStringContainsString('border:2px solid #000000 !important;', $css);
        $this->assertStringContainsString('color:#fff !important;', $css);
    }

    public function testInvalidColourFallsBackToDefault(): void
    {
        $this->assertNull(SeatPlanStyle::normalizeColor('red; background:url(x)'));
        $this->assertSame('#ffffff', SeatPlanStyle::forEvent(['seatplan_stairs_color' => 'javascript:x'])['stairs_color']);
    }

    public function testTextColourContrast(): void
    {
        $this->assertSame('#000', SeatPlanStyle::textColor('#ffff00'));
        $this->assertSame('#fff', SeatPlanStyle::textColor('#000080'));
    }

    public function testForJs(): void
    {
        $js = SeatPlanStyle::forJs(['seatplan_distance_color' => '#ff0000', 'seatplan_stairs_border' => 0]);
        $this->assertSame('#ff0000', $js['distanceColor']);
        $this->assertSame('#ffffff', $js['stairsColor']);
        $this->assertFalse($js['stairsBorder']);
        $this->assertFalse($js['distanceBorder']);
    }
}
