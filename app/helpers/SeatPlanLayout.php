<?php
/**
 * Seat Plan Layout Helper
 * Layout figures for seat plans that scale their seats to the available width
 * (see public/libs/css/seatplan-fit.css).
 */

namespace App\Helpers;

class SeatPlanLayout
{
    /**
     * Number of seat-sized slots of the widest row.
     *
     * Seats, wheelchair seats and distances take one slot each. A table takes as many
     * slots as its widest side (front/back, at least 2 for the table itself) plus one
     * slot per left/right seat column.
     *
     * @param array $seatPlan Structure from SeatPlan::getSeatPlanStructure()
     */
    public static function maxSlots(array $seatPlan): int
    {
        $max = 1;

        foreach ($seatPlan as $row) {
            if (($row['type'] ?? 'row') !== 'row' || (int)($row['no'] ?? 0) === 0) {
                continue; // stage row
            }

            $slots = 0;
            foreach ($row['content'] ?? [] as $element) {
                $type = $element['type'] ?? '';
                if (in_array($type, ['seat', 'wheelchair', 'distance', 'spacer'], true)) {
                    $slots++;
                } elseif ($type === 'table') {
                    $seats = $element['seats'] ?? [];
                    $slots += max(count($seats['front'] ?? []), count($seats['back'] ?? []), 2)
                        + (empty($seats['left']) ? 0 : 1)
                        + (empty($seats['right']) ? 0 : 1);
                }
            }

            $max = max($max, $slots);
        }

        return $max;
    }
}
