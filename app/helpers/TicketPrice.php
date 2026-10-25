<?php
/**
 * Ticket Price
 * Single source of truth for the price of one reservation (ticket).
 *
 * Rule (same order as the PayPal checkout, which charges the customer):
 *   1. wheelchair companion          -> 0 €
 *   2. general rehearsal date        -> category general_rehearsal_price (if set)
 *   3. reduced ticket                -> category reduced_price (if set)
 *   4. FN card (active + event allows it) -> price minus fn_card_factor
 *   5. otherwise                     -> category price
 */

namespace App\Helpers;

use App\Core\Config;
use App\Models\Event;
use App\Models\SeatCategory;

final class TicketPrice
{
    public const TYPE_NORMAL = 'normal';
    public const TYPE_COMPANION = 'companion';
    public const TYPE_REHEARSAL = 'rehearsal';
    public const TYPE_REDUCED = 'reduced';
    public const TYPE_FN_CARD = 'fn_card';

    /** @var array<int, ?array> */
    private static array $categoryCache = [];
    /** @var array<string, bool> */
    private static array $rehearsalCache = [];
    /** @var array<int, ?array> */
    private static array $eventCache = [];

    /**
     * Pure price calculation (no database access).
     *
     * @param array      $reservation Needs is_companion, is_reduced, is_fn_card (missing = 0)
     * @param array|null $category    Row from seat_categories (price, reduced_price, general_rehearsal_price)
     * @return array{price: float, type: string}
     */
    public static function calculate(
        array $reservation,
        ?array $category,
        bool $isGeneralRehearsal,
        bool $fnCardAllowed,
        float $fnCardFactor
    ): array {
        if (!empty($reservation['is_companion'])) {
            return ['price' => 0.0, 'type' => self::TYPE_COMPANION];
        }
        if ($category === null) {
            return ['price' => 0.0, 'type' => self::TYPE_NORMAL];
        }

        $price = (float)($category['price'] ?? 0);

        if ($isGeneralRehearsal && (float)($category['general_rehearsal_price'] ?? 0) > 0) {
            return ['price' => (float)$category['general_rehearsal_price'], 'type' => self::TYPE_REHEARSAL];
        }
        if (!empty($reservation['is_reduced']) && (float)($category['reduced_price'] ?? 0) > 0) {
            return ['price' => (float)$category['reduced_price'], 'type' => self::TYPE_REDUCED];
        }
        if (!empty($reservation['is_fn_card']) && $fnCardAllowed) {
            return ['price' => $price - ($price * $fnCardFactor), 'type' => self::TYPE_FN_CARD];
        }

        return ['price' => $price, 'type' => self::TYPE_NORMAL];
    }

    /**
     * Price of a stored reservation row (needs category_id, event_id, event_date and the flags).
     * Category, rehearsal flag and event are loaded and cached per request.
     *
     * @return array{price: float, type: string}
     */
    public static function forReservation(array $reservation): array
    {
        $category = self::category((int)($reservation['category_id'] ?? 0));
        $eventId = (int)($reservation['event_id'] ?? 0);
        $event = self::event($eventId);

        return self::calculate(
            $reservation,
            $category,
            self::isGeneralRehearsal($eventId, (string)($reservation['event_date'] ?? '')),
            Config::get('fn_card_active', false) && !empty($event['allow_fn_card']),
            (float)Config::get('fn_card_factor', 0.1)
        );
    }

    /**
     * Price types staff can choose for one seat when selling (normal, reduced, FN card), with prices.
     * Reduced needs reduced_prices_active + event.allow_reduced_prices + a category reduced_price,
     * FN card needs fn_card_active + event.allow_fn_card. On a general rehearsal with a rehearsal
     * price only that price applies. Companions have no choice (empty array).
     *
     * @param array $seat Needs category (or category_id), is_companion; event/date are passed separately
     * @return array<string, float> type => price, first entry is the default
     */
    public static function choices(array $seat, int $eventId, string $eventDate): array
    {
        if (!empty($seat['is_companion'])) {
            return [];
        }

        $base = [
            'category_id' => $seat['category_id'] ?? $seat['category'] ?? null,
            'event_id' => $eventId,
            'event_date' => $eventDate,
        ];
        $event = self::event($eventId);

        $normal = self::forReservation($base);
        $choices = [$normal['type'] => $normal['price']];
        if ($normal['type'] === self::TYPE_REHEARSAL) {
            return $choices;
        }

        if (Config::get('reduced_prices_active', false) && !empty($event['allow_reduced_prices'])) {
            $reduced = self::forReservation($base + ['is_reduced' => 1]);
            if ($reduced['type'] === self::TYPE_REDUCED) {
                $choices[self::TYPE_REDUCED] = $reduced['price'];
            }
        }

        $fnCard = self::forReservation($base + ['is_fn_card' => 1]);
        if ($fnCard['type'] === self::TYPE_FN_CARD) {
            $choices[self::TYPE_FN_CARD] = $fnCard['price'];
        }

        return $choices;
    }

    /**
     * Why no reduced price can be offered for this event/date, or null when it can (for some category).
     */
    public static function reducedUnavailableReason(int $eventId, string $eventDate, array $categoryIds): ?string
    {
        $event = self::event($eventId);
        if (!Config::get('reduced_prices_active', false)) {
            return 'Ermäßigungen sind in den Einstellungen deaktiviert.';
        }
        if (empty($event['allow_reduced_prices'])) {
            return 'Für diese Veranstaltung ist keine Ermäßigung freigegeben (Veranstaltung bearbeiten).';
        }
        if (self::isGeneralRehearsal($eventId, $eventDate)) {
            return 'Generalprobe: es gilt der Generalprobenpreis.';
        }
        foreach (array_unique($categoryIds) as $categoryId) {
            $category = self::category((int)$categoryId);
            if ($category && (float)($category['reduced_price'] ?? 0) > 0) {
                return null;
            }
        }
        return 'Die Kategorien der gewählten Plätze haben keinen Ermäßigungspreis (Sitzplan → Kategorien).';
    }

    /**
     * Short label for the price type, e.g. for the ticket ({price_type}); empty for the normal price.
     */
    public static function typeLabel(string $type): string
    {
        return match ($type) {
            self::TYPE_COMPANION => 'Begleitperson',
            self::TYPE_REHEARSAL => 'Generalprobe',
            self::TYPE_REDUCED => 'Ermäßigt',
            self::TYPE_FN_CARD => 'FN-Card',
            default => '',
        };
    }

    private static function category(int $categoryId): ?array
    {
        if ($categoryId <= 0) {
            return null;
        }
        if (!array_key_exists($categoryId, self::$categoryCache)) {
            self::$categoryCache[$categoryId] = (new SeatCategory())->getById($categoryId);
        }
        return self::$categoryCache[$categoryId];
    }

    private static function event(int $eventId): ?array
    {
        if ($eventId <= 0) {
            return null;
        }
        if (!array_key_exists($eventId, self::$eventCache)) {
            self::$eventCache[$eventId] = (new Event())->getById($eventId);
        }
        return self::$eventCache[$eventId];
    }

    private static function isGeneralRehearsal(int $eventId, string $eventDate): bool
    {
        if ($eventId <= 0 || $eventDate === '') {
            return false;
        }
        $key = $eventId . '|' . $eventDate;
        if (!array_key_exists($key, self::$rehearsalCache)) {
            $date = (new Event())->getDateByEventAndDate($eventId, $eventDate);
            self::$rehearsalCache[$key] = $date && (int)($date['is_general_rehearsal'] ?? 0) === 1;
        }
        return self::$rehearsalCache[$key];
    }
}
