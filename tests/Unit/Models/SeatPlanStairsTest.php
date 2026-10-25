<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Models\SeatPlan;
use Tests\Support\DatabaseTestCase;

/**
 * A distance can be marked as stairs (is_stairs); the seat plan structure delivers it as
 * "stairs" so all views can draw it white with a black border.
 */
class SeatPlanStairsTest extends DatabaseTestCase
{
    public function testStairsFlagIsStoredAndDelivered(): void
    {
        $seatPlan = new SeatPlan();
        $event = $this->createEvent();
        $rowId = $seatPlan->saveRow((int)$event['id'], 1, null, 1);
        $seatPlan->addElement($rowId, ['element_type' => 'seat', 'element_number' => 1, 'position' => 0]);
        $gapId = $seatPlan->addElement($rowId, ['element_type' => 'distance', 'element_number' => 2, 'position' => 1]);
        $stairsId = $seatPlan->addElement($rowId, ['element_type' => 'distance', 'element_number' => 3, 'position' => 2, 'is_stairs' => 1]);

        $content = $seatPlan->getSeatPlanStructure((int)$event['id'])[0]['content'];
        self::assertSame(0, $content[1]['stairs']);
        self::assertSame(1, $content[2]['stairs']);
        self::assertArrayNotHasKey('stairs', $content[0]); // seats have no stairs flag

        // Back to a normal distance
        $seatPlan->updateElement($stairsId, ['element_type' => 'distance', 'element_number' => 3, 'position' => 2, 'is_stairs' => 0]);
        $content = $seatPlan->getSeatPlanStructure((int)$event['id'])[0]['content'];
        self::assertSame(0, $content[2]['stairs']);
        self::assertGreaterThan(0, $gapId);
    }

    public function testStairsAttributesOnlyForStairs(): void
    {
        self::assertSame('', seatplanStairsAttr(['type' => 'distance', 'stairs' => 0]));
        self::assertStringContainsString('border:2px solid #000', seatplanStairsAttr(['type' => 'distance', 'stairs' => 1]));
    }
}
