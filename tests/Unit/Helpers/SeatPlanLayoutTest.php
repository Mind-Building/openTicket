<?php

namespace Tests\Unit\Helpers;

use App\Helpers\SeatPlanLayout;
use PHPUnit\Framework\TestCase;

class SeatPlanLayoutTest extends TestCase
{
    public function testEmptyPlanHasOneSlot(): void
    {
        $this->assertSame(1, SeatPlanLayout::maxSlots([]));
    }

    public function testSeatsWheelchairsAndDistancesCountOneSlotEach(): void
    {
        $plan = [
            ['type' => 'row', 'no' => 1, 'content' => [
                ['type' => 'seat', 'no' => 1],
                ['type' => 'distance'],
                ['type' => 'wheelchair', 'no' => 2],
                ['type' => 'spacer'],
            ]],
            ['type' => 'row', 'no' => 2, 'content' => [
                ['type' => 'seat', 'no' => 1],
            ]],
        ];

        $this->assertSame(4, SeatPlanLayout::maxSlots($plan));
    }

    public function testStageRowIsIgnored(): void
    {
        $plan = [
            ['type' => 'row', 'no' => 0, 'content' => array_fill(0, 10, ['type' => 'stage'])],
            ['type' => 'row', 'no' => 1, 'content' => [['type' => 'seat', 'no' => 1]]],
        ];

        $this->assertSame(1, SeatPlanLayout::maxSlots($plan));
    }

    public function testTableTakesWidestSidePlusSideColumns(): void
    {
        $plan = [
            ['type' => 'row', 'no' => 1, 'content' => [
                ['type' => 'table', 'no' => 1, 'seats' => [
                    'front' => [['number' => 1], ['number' => 2], ['number' => 3]],
                    'back'  => [['number' => 4]],
                    'left'  => [['number' => 5], ['number' => 6]],
                ]],
                ['type' => 'table', 'no' => 2], // no seats: table itself = 2 slots
            ]],
        ];

        $this->assertSame((3 + 1) + 2, SeatPlanLayout::maxSlots($plan));
    }
}
