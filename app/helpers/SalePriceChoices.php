<?php
/**
 * Sale Price Choices
 * Step 3 of admin/presales sell: one price type per seat (Normal / Ermäßigt / FN-Card / Rabatte).
 * Prices and allowed types come from App\Helpers\TicketPrice, the same rule as PayPal and the ticket.
 */

namespace App\Helpers;

use App\Core\Config;
use App\Models\Reservation;
use App\Models\SeatPlan;

class SalePriceChoices
{
    /**
     * HTML block with a price-type select per seat and a live total (posts price_type[index]).
     */
    public static function render(array $seats, int $eventId, string $eventDate): string
    {
        $seats = self::normalize($seats);
        $seatPlanModel = new SeatPlan();
        $rows = '';
        $total = 0.0;
        $anyReduced = false;
        $categoryIds = [];

        foreach ($seats as $index => $seat) {
            $categoryIds[] = (int)($seat['category'] ?? 0);
            $label = !empty($seat['label'])
                ? $seat['label']
                : $seatPlanModel->getSeatDisplayName($eventId, (string)($seat['id'] ?? ''));
            $choices = self::choices($seat, $eventId, $eventDate);

            if (empty($choices)) {
                // Wheelchair companion: always free, nothing to choose
                $rows .= '<tr><td>' . htmlspecialchars($label) . ' <span class="badge bg-info">Begleitperson</span></td>'
                    . '<td class="text-muted small">kostenfrei</td>'
                    . '<td class="text-end price-choice-cell" data-price="0">0,00 €</td></tr>';
                continue;
            }

            $selected = self::discountKey($seat)
                ?? (!empty($seat['is_reduced']) ? TicketPrice::TYPE_REDUCED
                : (!empty($seat['is_fn_card']) ? TicketPrice::TYPE_FN_CARD : null));
            if ($selected === null || !isset($choices[$selected])) {
                $selected = array_key_first($choices);
            }
            $anyReduced = $anyReduced || isset($choices[TicketPrice::TYPE_REDUCED]);
            $total += $choices[$selected];

            $options = '';
            foreach ($choices as $type => $price) {
                $name = TicketPrice::choiceLabel((string)$type);
                $options .= '<option value="' . $type . '" data-price="' . $price . '"' . ($type === $selected ? ' selected' : '') . '>'
                    . htmlspecialchars($name) . ' – ' . self::money($price) . '</option>';
            }

            $rows .= '<tr><td>' . htmlspecialchars($label) . '</td>'
                . '<td style="width: 260px;"><select class="form-select form-select-sm price-choice" name="price_type[' . $index . ']"'
                . (count($choices) === 1 ? ' disabled' : '') . ' onchange="updatePriceChoices()">' . $options . '</select>'
                . (isset($choices[TicketPrice::TYPE_FN_CARD])
                    ? '<input type="text" class="form-control form-control-sm mt-1 fn-card-input" name="fn_card_number[' . $index . ']"'
                      . ' placeholder="FN-Card-Nummer (8 Ziffern)" inputmode="numeric" maxlength="8" pattern="[0-9]{8}"'
                      . ' value="' . htmlspecialchars((string)($seat['fn_card_number'] ?? '')) . '"'
                      . ($selected === TicketPrice::TYPE_FN_CARD ? ' required' : ' style="display:none" disabled') . '>'
                    : '')
                . '</td>'
                . '<td class="text-end price-choice-cell" style="width: 100px;" data-price="' . $choices[$selected] . '">'
                . self::money($choices[$selected]) . '</td></tr>';
        }

        $hint = '';
        if (!$anyReduced) {
            $reason = TicketPrice::reducedUnavailableReason($eventId, $eventDate, $categoryIds);
            if ($reason !== null) {
                $hint = '<div class="form-text"><i class="bi bi-info-circle"></i> Keine Ermäßigung möglich: ' . htmlspecialchars($reason) . '</div>';
            }
        }

        return '
                <div class="mb-4">
                    <label class="form-label"><strong>Plätze &amp; Preise:</strong></label>
                    <table class="table table-sm align-middle mb-1">
                        <tbody>' . $rows . '</tbody>
                        <tfoot><tr><th colspan="2" class="text-end">Gesamt</th><th class="text-end" id="price-choices-total">' . self::money($total) . '</th></tr></tfoot>
                    </table>' . $hint . '
                </div>
                <script>
                function updatePriceChoices() {
                    var total = 0;
                    document.querySelectorAll(".price-choice-cell").forEach(function (cell) {
                        var select = cell.parentNode.querySelector(".price-choice");
                        var price = select ? parseFloat(select.options[select.selectedIndex].dataset.price) : parseFloat(cell.dataset.price);
                        var fnInput = cell.parentNode.querySelector(".fn-card-input");
                        if (fnInput) {
                            var isFn = select.value === "fn_card";
                            fnInput.style.display = isFn ? "" : "none";
                            fnInput.disabled = !isFn;
                            fnInput.required = isFn;
                        }
                        cell.textContent = price.toFixed(2).replace(".", ",") + " €";
                        total += price;
                    });
                    document.getElementById("price-choices-total").textContent = total.toFixed(2).replace(".", ",") + " €";
                }
                </script>';
    }

    /**
     * Apply the posted price types to the seats; anything not offered for a seat falls back to normal.
     */
    public static function apply(array $seats, array $postedTypes, int $eventId, string $eventDate, array $postedFnNumbers = []): array
    {
        $seats = self::normalize($seats);
        $fnCardUses = []; // FN card number => seats of this order using it
        $priceTypesAllowed = self::priceTypesAllowed();
        foreach ($seats as $index => $seat) {
            if (!isset($postedTypes[$index])) {
                if (!$priceTypesAllowed) {
                    // Never trust price flags from the seats JSON when only the normal price is allowed
                    $seat['is_reduced'] = 0;
                    $seat['is_fn_card'] = 0;
                    $seat['fn_card_number'] = null;
                    $seat['discount_label'] = null;
                    $seat['discount_percent'] = null;
                    $seats[$index] = $seat;
                }
                continue;
            }
            $choices = self::choices($seat, $eventId, $eventDate);
            $type = isset($choices[$postedTypes[$index]]) ? (string)$postedTypes[$index] : null;
            $seat['is_reduced'] = $type === TicketPrice::TYPE_REDUCED ? 1 : 0;
            $seat['is_fn_card'] = $type === TicketPrice::TYPE_FN_CARD ? 1 : 0;
            // Percentage discount: label and percent are stored on the reservation
            $discount = $type !== null ? (TicketPrice::discounts()[$type] ?? null) : null;
            $seat['discount_label'] = $discount['label'] ?? null;
            $seat['discount_percent'] = $discount['percent'] ?? null;
            $seat['fn_card_number'] = null;
            if ($seat['is_fn_card']) {
                $number = trim((string)($postedFnNumbers[$index] ?? ''));
                if (!Reservation::isValidFnCardNumber($number)) {
                    throw new \InvalidArgumentException('Bitte für jeden FN-Card-Platz eine gültige FN-Card-Nummer (8 Ziffern) eingeben.');
                }
                $seat['fn_card_number'] = $number;
                $fnCardUses[$number] = ($fnCardUses[$number] ?? 0) + 1;
            }
            $seats[$index] = $seat;
        }

        // Limit per FN card and event (setting max_fn_card): earlier tickets + seats of this order
        $max = (int)Config::get('max_fn_card', 2);
        $reservationModel = new Reservation();
        foreach ($fnCardUses as $number => $count) {
            $existing = $reservationModel->getFnCardCount((string)$number, $eventId);
            if ($existing + $count > $max) {
                throw new \InvalidArgumentException(
                    'FN-Card ' . $number . ': höchstens ' . $max . ' Tickets pro Veranstaltung – bereits ' . $existing . ' gebucht, in dieser Bestellung ' . $count . '.'
                );
            }
        }
        return $seats;
    }

    /**
     * Price types offered for a seat. Without the "Preisarten" permission (external partners,
     * see App\Core\Permission) only the default price (first entry) is offered.
     */
    private static function choices(array $seat, int $eventId, string $eventDate): array
    {
        $choices = TicketPrice::choices($seat, $eventId, $eventDate, true);
        if (!self::priceTypesAllowed() && count($choices) > 1) {
            $first = array_key_first($choices);
            return [$first => $choices[$first]];
        }
        return $choices;
    }

    private static function priceTypesAllowed(): bool
    {
        // Only external partners are restricted; admin, presales (and CLI/tests) keep all types
        return !\App\Core\Auth::isPartner() || \App\Core\Permission::can(\App\Core\Permission::PRICE_TYPES);
    }

    /**
     * Seats arrive as arrays or - from the seats[] hidden inputs of step 2 - as JSON strings.
     * Returns a list of arrays whose indexes match price_type[index].
     */
    private static function normalize(array $seats): array
    {
        $out = [];
        foreach ($seats as $seat) {
            if (is_string($seat)) {
                $seat = json_decode($seat, true);
            }
            if (is_array($seat)) {
                $out[] = $seat;
            }
        }
        return $out;
    }

    /**
     * Choice key of the discount a seat already carries (e.g. back from a failed submit), or null.
     */
    private static function discountKey(array $seat): ?string
    {
        $percent = (float)($seat['discount_percent'] ?? 0);
        if ($percent <= 0) {
            return null;
        }
        foreach (TicketPrice::discounts() as $key => $discount) {
            if (abs($discount['percent'] - $percent) < 0.001 && $discount['label'] === ($seat['discount_label'] ?? $discount['label'])) {
                return $key;
            }
        }
        return null;
    }

    private static function money(float $amount): string
    {
        return number_format($amount, 2, ',', '.') . ' €';
    }
}
