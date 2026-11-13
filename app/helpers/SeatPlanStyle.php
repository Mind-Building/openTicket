<?php
/**
 * Seat Plan Style
 * Colours of distances (Abstandshalter) and stairs in the seat plan, set per event
 * (events.seatplan_distance_color / _border, seatplan_stairs_color / _border -
 * database/migrate_event_display.sql). Without settings everything looks as before:
 * distances in the view's own grey, stairs white with a black border.
 */

namespace App\Helpers;

final class SeatPlanStyle
{
    public const DEFAULT_DISTANCE_COLOR = '#6c757d';
    public const DEFAULT_STAIRS_COLOR = '#ffffff';
    private const BORDER = '2px solid #000';

    /**
     * Resolved settings of an event (null event / missing columns = defaults).
     *
     * @return array{distance_color: ?string, distance_border: bool, stairs_color: string, stairs_border: bool}
     *         distance_color null = not customised (each view keeps its own distance styling)
     */
    public static function forEvent(?array $event): array
    {
        return [
            'distance_color' => self::normalizeColor($event['seatplan_distance_color'] ?? null),
            'distance_border' => !empty($event['seatplan_distance_border']),
            'stairs_color' => self::normalizeColor($event['seatplan_stairs_color'] ?? null) ?? self::DEFAULT_STAIRS_COLOR,
            'stairs_border' => !isset($event['seatplan_stairs_border']) || (int)$event['seatplan_stairs_border'] === 1,
        ];
    }

    /**
     * '#rrggbb' (lower case) or null for anything that is not a hex colour.
     */
    public static function normalizeColor(mixed $color): ?string
    {
        $color = strtolower(trim((string)$color));
        if (preg_match('/^#([0-9a-f]{3})$/', $color, $m)) {
            $color = '#' . $m[1][0] . $m[1][0] . $m[1][1] . $m[1][1] . $m[1][2] . $m[1][2];
        }
        return preg_match('/^#[0-9a-f]{6}$/', $color) ? $color : null;
    }

    /**
     * Black or white text, whichever is readable on the given background.
     */
    public static function textColor(string $hex): string
    {
        $hex = self::normalizeColor($hex) ?? '#ffffff';
        $r = hexdec(substr($hex, 1, 2));
        $g = hexdec(substr($hex, 3, 2));
        $b = hexdec(substr($hex, 5, 2));
        return (0.299 * $r + 0.587 * $g + 0.114 * $b) > 150 ? '#000' : '#fff';
    }

    /**
     * Inline CSS (with !important, wins over the views' stylesheets) for a normal distance,
     * or '' when the event does not customise distances.
     */
    public static function distanceCss(?array $event): string
    {
        $style = self::forEvent($event);
        if ($style['distance_color'] === null && !$style['distance_border']) {
            return '';
        }
        $bg = $style['distance_color'] ?? self::DEFAULT_DISTANCE_COLOR;
        return self::css($bg, $style['distance_border']);
    }

    /**
     * Inline CSS for a distance marked as stairs.
     */
    public static function stairsCss(?array $event): string
    {
        $style = self::forEvent($event);
        return self::css($style['stairs_color'], $style['stairs_border']);
    }

    /**
     * Inline CSS for the stairs box in a legend (no !important needed).
     */
    public static function stairsLegendCss(?array $event): string
    {
        $style = self::forEvent($event);
        return 'background:' . $style['stairs_color'] . ';border:'
            . ($style['stairs_border'] ? self::BORDER : '2px solid ' . $style['stairs_color']) . ';';
    }

    /**
     * Extra attributes for a distance element in the server-rendered seat plan views.
     */
    public static function elementAttr(array $element, ?array $event): string
    {
        if (!empty($element['stairs'])) {
            return ' title="Treppe" data-stairs="1" style="' . self::stairsCss($event) . '"';
        }
        $css = self::distanceCss($event);
        return $css === '' ? '' : ' style="' . $css . '"';
    }

    /**
     * Settings for seat plans drawn in JavaScript (resolved colours, text colours included).
     */
    public static function forJs(?array $event): array
    {
        $style = self::forEvent($event);
        $distance = $style['distance_color'] ?? self::DEFAULT_DISTANCE_COLOR;
        return [
            'distanceColor' => $distance,
            'distanceText' => self::textColor($distance),
            'distanceBorder' => $style['distance_border'],
            'stairsColor' => $style['stairs_color'],
            'stairsText' => self::textColor($style['stairs_color']),
            'stairsBorder' => $style['stairs_border'],
        ];
    }

    private static function css(string $bg, bool $border): string
    {
        return 'background:' . $bg . ' !important;'
            . 'border:' . ($border ? self::BORDER : '2px solid ' . $bg) . ' !important;'
            . 'color:' . self::textColor($bg) . ' !important;';
    }
}
