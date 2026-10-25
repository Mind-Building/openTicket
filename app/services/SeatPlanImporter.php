<?php
/**
 * Sitzplan-Import
 *
 * Ordnet Tabellenzeilen (Excel/CSV) den Sitzen einer Veranstaltung zu und
 * schreibt die Alternativbelegung (Reihe/Platz in der Ausweichspielstätte)
 * sowie optional die Kategorie.
 *
 * Preise werden bewusst nicht importiert – sie stammen aus der Kategorie.
 */

namespace App\Services;

use App\Models\SeatCategory;
use App\Models\SeatPlan;

class SeatPlanImporter
{
    private SeatPlan $seatPlanModel;
    private SeatCategory $categoryModel;

    public function __construct(?SeatPlan $seatPlanModel = null, ?SeatCategory $categoryModel = null)
    {
        $this->seatPlanModel = $seatPlanModel ?? new SeatPlan();
        $this->categoryModel = $categoryModel ?? new SeatCategory();
    }

    /**
     * Schlägt anhand der Kopfzeile eine Spaltenzuordnung vor.
     * Die erste passende Spalte ist das Original, die zweite die Alternative.
     */
    public static function guessMapping(array $headers): array
    {
        $rowCols = [];
        $seatCols = [];
        $tableCols = [];
        $categoryCols = [];

        foreach ($headers as $index => $header) {
            $normalized = mb_strtolower(trim((string)$header));
            if ($normalized === '') {
                continue;
            }
            if (preg_match('/reihe|row/u', $normalized)) {
                $rowCols[] = $index;
            } elseif (preg_match('/tisch|table/u', $normalized)) {
                $tableCols[] = $index;
            } elseif (preg_match('/platz|sitz|seat/u', $normalized)) {
                $seatCols[] = $index;
            } elseif (preg_match('/kat|categ/u', $normalized)) {
                $categoryCols[] = $index;
            }
        }

        return [
            'row'      => $rowCols[0] ?? null,
            'seat'     => $seatCols[0] ?? null,
            'alt_row'  => $rowCols[1] ?? null,
            'alt_seat' => $seatCols[1] ?? null,
            'table'    => $tableCols[0] ?? null,
            'category' => $categoryCols[0] ?? null,
        ];
    }

    /**
     * Vereinheitlicht eine Kategorie-Angabe für den Abgleich:
     * "Kat. I." -> "i", "Kategorie A" -> "a"
     */
    public static function normalizeCategoryToken(?string $value): string
    {
        $value = mb_strtolower(trim((string)$value));
        // Längere Varianten zuerst, damit "kategorie" nicht als "kat" + "egorie" zerfällt
        $value = preg_replace('/^(kategorie|category|kat\.?|cat\.?)\s*/u', '', $value);

        return trim($value, " \t.,:-");
    }

    /**
     * Liest eine Platz-/Tischnummer ("13", "Platz 13") als positive Ganzzahl.
     */
    public static function parseNumber(?string $value): ?int
    {
        $clean = SeatPlan::normalizeAltValue($value);
        if ($clean === null || !is_numeric($clean)) {
            return null;
        }
        $number = (int)$clean;

        return $number > 0 ? $number : null;
    }

    /**
     * Führt den Import aus.
     *
     * @param array $rows    Alle Zeilen der Datei (inkl. Kopfzeile)
     * @param array $mapping ['row'=>?int, 'seat'=>?int, 'alt_row'=>?int, 'alt_seat'=>?int,
     *                        'table'=>?int, 'category'=>?int] – 0-basierte Spaltenindizes
     * @param array $options ['header_row'=>int, 'create_missing'=>bool, 'clear_existing'=>bool,
     *                        'new_seat_category'=>?int]
     *
     * @throws \InvalidArgumentException bei unvollständiger Zuordnung
     */
    public function import(int $eventId, array $rows, array $mapping, array $options = []): array
    {
        $colRow      = $mapping['row'] ?? null;
        $colSeat     = $mapping['seat'] ?? null;
        $colAltRow   = $mapping['alt_row'] ?? null;
        $colAltSeat  = $mapping['alt_seat'] ?? null;
        $colTable    = $mapping['table'] ?? null;
        $colCategory = $mapping['category'] ?? null;

        if ($colSeat === null) {
            throw new \InvalidArgumentException('Bitte die Spalte mit der originalen Platznummer zuordnen.');
        }
        if ($colRow === null && $colTable === null) {
            throw new \InvalidArgumentException('Bitte die Spalte mit der originalen Reihe oder mit der Tischnummer zuordnen.');
        }
        if ($colAltRow === null && $colAltSeat === null && $colCategory === null) {
            throw new \InvalidArgumentException('Bitte mindestens eine Spalte der Alternativbelegung zuordnen.');
        }

        $headerRow = max(0, (int)($options['header_row'] ?? 1));
        $createMissing = !empty($options['create_missing']);
        $clearExisting = !empty($options['clear_existing']);
        $newSeatCategory = $options['new_seat_category'] ?? null;

        $dataRows = array_slice($rows, $headerRow);
        $planRows = $this->seatPlanModel->getRows($eventId);

        // Kategorien für den Abgleich per Zeichen (Buchstabe) oder Name
        $categoryLookup = [];
        foreach ($this->categoryModel->getByEvent($eventId) as $category) {
            $categoryLookup[self::normalizeCategoryToken($category['sign'])] = (int)$category['id'];
            $categoryLookup[self::normalizeCategoryToken($category['name'])] = (int)$category['id'];
        }
        unset($categoryLookup['']);

        $entries = [];
        $conflicts = [];
        $invalidLines = [];
        $unknownCategories = [];

        foreach ($dataRows as $offset => $dataRow) {
            $lineNumber = $headerRow + $offset + 1;

            $seatToken  = $colSeat !== null ? (string)($dataRow[$colSeat] ?? '') : '';
            $rowToken   = $colRow !== null ? (string)($dataRow[$colRow] ?? '') : '';
            $tableToken = $colTable !== null ? (string)($dataRow[$colTable] ?? '') : '';
            $altRow     = $colAltRow !== null ? SeatPlan::normalizeAltValue((string)($dataRow[$colAltRow] ?? '')) : null;
            $altSeat    = $colAltSeat !== null ? SeatPlan::normalizeAltValue((string)($dataRow[$colAltSeat] ?? '')) : null;

            // Komplett leere Zeilen still überspringen
            if (trim($seatToken) === '' && trim($rowToken) === '' && trim($tableToken) === '') {
                continue;
            }

            $seatNumber = self::parseNumber($seatToken);
            if ($seatNumber === null) {
                $invalidLines[] = ['line' => $lineNumber, 'reason' => 'Platznummer nicht lesbar: "' . $seatToken . '"'];
                continue;
            }

            // Kategorie (optional): Abgleich über das Zeichen der angelegten Kategorie
            $categoryId = null;
            if ($colCategory !== null) {
                $categoryToken = self::normalizeCategoryToken((string)($dataRow[$colCategory] ?? ''));
                if ($categoryToken !== '') {
                    if (isset($categoryLookup[$categoryToken])) {
                        $categoryId = $categoryLookup[$categoryToken];
                    } else {
                        $unknownCategories[$categoryToken] = ($unknownCategories[$categoryToken] ?? 0) + 1;
                    }
                }
            }

            if ($colTable !== null && trim($tableToken) !== '') {
                $tableNumber = self::parseNumber($tableToken);
                if ($tableNumber === null) {
                    $invalidLines[] = ['line' => $lineNumber, 'reason' => 'Tischnummer nicht lesbar: "' . $tableToken . '"'];
                    continue;
                }
                $key = 'T:' . $tableNumber . '|' . $seatNumber;
                $label = 'Tisch ' . $tableNumber . ', Platz ' . $seatNumber;
                $rowNumber = null;
            } else {
                $rowNumber = $this->resolveRowNumber($rowToken, $planRows);
                if ($rowNumber === null) {
                    $invalidLines[] = ['line' => $lineNumber, 'reason' => 'Reihe nicht lesbar/nicht gefunden: "' . $rowToken . '"'];
                    continue;
                }
                $key = $rowNumber . '|' . $seatNumber;
                $label = 'Reihe ' . $rowNumber . ', Platz ' . $seatNumber;
            }

            if (isset($entries[$key])) {
                // Doppelte Zeile (z. B. je Vorstellung) – nur abweichende Werte melden
                if ($entries[$key]['alt_row'] !== $altRow || $entries[$key]['alt_seat'] !== $altSeat) {
                    $conflicts[] = [
                        'line'  => $lineNumber,
                        'label' => $label,
                        'kept'  => SeatPlan::formatAlternativeSeat($entries[$key]['alt_row'], $entries[$key]['alt_seat']),
                        'other' => SeatPlan::formatAlternativeSeat($altRow, $altSeat),
                    ];
                }
                continue;
            }

            $entries[$key] = [
                'row'      => $rowNumber,
                'seat'     => $seatNumber,
                'alt_row'  => $altRow,
                'alt_seat' => $altSeat,
                'category' => $categoryId,
                'label'    => $label,
                'is_table' => str_starts_with($key, 'T:'),
            ];
        }

        $cleared = $clearExisting ? $this->seatPlanModel->clearAlternativeSeats($eventId) : 0;
        $seatIndex = $this->seatPlanModel->getSeatIndex($eventId);

        $updated = 0;
        $unchanged = 0;
        $created = 0;
        $categoriesSet = 0;
        $notFound = [];

        foreach ($entries as $key => $entry) {
            if (isset($seatIndex[$key])) {
                $element = $seatIndex[$key];
                $currentRow  = SeatPlan::normalizeAltValue($element['alt_row'] ?? null);
                $currentSeat = SeatPlan::normalizeAltValue($element['alt_seat'] ?? null);
                $changed = false;

                if ($currentRow !== $entry['alt_row'] || $currentSeat !== $entry['alt_seat']) {
                    $this->seatPlanModel->updateAlternativeSeat((int)$element['id'], $entry['alt_row'], $entry['alt_seat']);
                    $changed = true;
                }

                if ($entry['category'] !== null && (int)($element['category_id'] ?? 0) !== $entry['category']) {
                    $this->seatPlanModel->updateElementCategory((int)$element['id'], $entry['category']);
                    $categoriesSet++;
                    $changed = true;
                }

                $changed ? $updated++ : $unchanged++;
                continue;
            }

            // Tischplätze werden nicht automatisch angelegt (Bereich/Anordnung unbekannt)
            if (!$createMissing || $entry['is_table']) {
                $notFound[] = $entry['label'];
                continue;
            }

            $planRows = $this->seatPlanModel->getRows($eventId);
            $targetRow = null;
            foreach ($planRows as $planRow) {
                if ((int)$planRow['row_number'] === (int)$entry['row']) {
                    $targetRow = $planRow;
                    break;
                }
            }
            $rowId = $targetRow
                ? (int)$targetRow['id']
                : $this->seatPlanModel->saveRow($eventId, (int)$entry['row'], null, count($planRows));

            // Sorted in by seat number - not appended in the order of the spreadsheet lines
            $position = $this->seatPlanModel->makeRoomForSeat($rowId, (int)$entry['seat']);

            $this->seatPlanModel->addElement($rowId, [
                'element_type'   => 'seat',
                'element_number' => (int)$entry['seat'],
                'position'       => $position,
                'category_id'    => $entry['category'] ?? $newSeatCategory,
                'alt_row'        => $entry['alt_row'],
                'alt_seat'       => $entry['alt_seat'],
            ]);
            $created++;
        }

        return [
            'total_entries'      => count($entries),
            'updated'            => $updated,
            'unchanged'          => $unchanged,
            'created'            => $created,
            'categories_set'     => $categoriesSet,
            'cleared'            => $cleared,
            'not_found'          => $notFound,
            'invalid'            => $invalidLines,
            'conflicts'          => $conflicts,
            'unknown_categories' => $unknownCategories,
        ];
    }

    /**
     * Löst eine Reihenangabe ("3", "Reihe 3", "Balkon") in eine Reihennummer auf.
     */
    private function resolveRowNumber(?string $value, array $planRows): ?int
    {
        $clean = SeatPlan::normalizeAltValue($value);
        if ($clean === null) {
            return null;
        }
        if (is_numeric($clean)) {
            return (int)$clean;
        }
        foreach ($planRows as $planRow) {
            if (mb_strtolower(trim((string)($planRow['row_name'] ?? ''))) === mb_strtolower($clean)) {
                return (int)$planRow['row_number'];
            }
        }

        return null;
    }
}
