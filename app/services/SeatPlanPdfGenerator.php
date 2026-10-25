<?php
/**
 * Seat Plan PDF Generator
 *
 * Renders a one-page A4-landscape seat plan showing free vs. occupied seats.
 * Shared by the box office (freie Plätze) and the seat plan occupancy views
 * so every printout looks identical.
 *
 * The whole plan is auto-scaled so it always fits on a single A4 page.
 * Row 0 is rendered as the stage ("BÜHNE"). Wheelchair seats are included.
 * Supports colour and black-white output (bw = toner-saving for box office).
 */

namespace App\Services;

use App\Models\Event;
use App\Models\Reservation;
use App\Models\SeatPlan;
use App\Models\SeatCategory;
use setasign\Fpdi\Tcpdf\Fpdi;

class SeatPlanPdfGenerator
{
    /**
     * Build the seat plan PDF and stream it inline to the browser, then exit.
     *
     * @throws \RuntimeException on invalid input or missing seat plan/event.
     */
    public function output(
        int $eventId,
        string $eventDate,
        bool $bw = false,
        string $title = 'Sitzplan - Freie Plätze',
        string $filenamePrefix = 'sitzplan_freie_plaetze'
    ): void {
        if ($eventId <= 0 || $eventDate === '') {
            throw new \RuntimeException('Ungültige Parameter');
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $eventDate)) {
            throw new \RuntimeException('Ungültiges Datumsformat');
        }

        $eventModel       = new Event();
        $reservationModel = new Reservation();
        $seatPlanModel    = new SeatPlan();
        $categoryModel    = new SeatCategory();

        $event = $eventModel->getById($eventId);
        if (!$event) {
            throw new \RuntimeException('Veranstaltung nicht gefunden');
        }

        $seatPlanStructure = $seatPlanModel->getSeatPlanStructure($eventId);
        if (empty($seatPlanStructure)) {
            throw new \RuntimeException('Kein Sitzplan für diese Veranstaltung vorhanden');
        }

        // Occupied = every reservation with a completed/pending status (>= 2),
        // plus cart items (status 1) younger than 15 minutes.
        $reservedSeats = [];
        foreach ($reservationModel->getAll(['event_id' => $eventId, 'event_date' => $eventDate]) as $r) {
            $statusId = (int)$r['status_id'];
            if ($statusId >= 2) {
                $reservedSeats[$r['seat_identifier']] = true;
            } elseif ($statusId === 1 && strtotime($r['created_at']) > (time() - 15 * 60)) {
                $reservedSeats[$r['seat_identifier']] = true;
            }
        }
        $isSeatFree = fn($seatId) => !isset($reservedSeats[$seatId]);

        // Category colours (only used in colour mode)
        $categoryColors = [];
        foreach ($categoryModel->getByEvent($eventId) as $cat) {
            $categoryColors[$cat['id']] = $cat['color'] ?? '#6c757d';
        }
        $hexToRgb = function ($hex) {
            $hex = ltrim($hex, '#');
            if (strlen($hex) == 3) {
                $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
            }
            return [hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2))];
        };

        // ── Pass 1: normalise the plan + count seats ─────────────────────────
        // Widths are expressed as multiples of the (yet unknown) seat size so
        // the whole plan can be scaled to fit exactly one A4 page in pass 2.
        $SP = 0.18; $LBL = 2.6; $LBLGAP = 0.3; $TBL = 2.0; $ROWSP = 0.5;
        $DIST = 1 + $SP; // a distance takes exactly one seat slot, so seat numbers line up across rows

        $plan = [];
        $freeCount = 0; $occupiedCount = 0; $totalSeats = 0; $maxUnitsW = 0.0;

        foreach ($seatPlanStructure as $row) {
            if (($row['type'] ?? 'row') !== 'row' || !isset($row['no'])) {
                continue;
            }
            if ((int)$row['no'] === 0) {
                $plan[] = ['stage' => true];
                continue;
            }

            $rowCategoryId = $row['category'] ?? null;
            $label = $row['row_name'] ?: ('R' . $row['no']);
            $cells = [];
            $unitsW = $LBL + $LBLGAP;

            foreach ($row['content'] as $element) {
                $type = $element['type'];
                if ($type === 'seat' || $type === 'wheelchair') {
                    $prefix = $type === 'wheelchair' ? 'wheelchair_' : 'seat_';
                    $isFree = $isSeatFree($prefix . $row['no'] . '_' . $element['no']);
                    $totalSeats++;
                    $isFree ? $freeCount++ : $occupiedCount++;
                    $cells[] = ['kind' => 'seat', 'no' => $element['no'], 'free' => $isFree,
                                'cat' => $element['category'] ?? $rowCategoryId, 'wheelchair' => $type === 'wheelchair'];
                    $unitsW += 1 + $SP;
                } elseif ($type === 'table') {
                    $tableSeats = $element['seats'] ?? [];
                    $allTableSeats = [];
                    foreach (['left', 'right', 'back', 'front'] as $area) {
                        foreach ($tableSeats[$area] ?? [] as $seat) {
                            $allTableSeats[] = ['number' => $seat['number'], 'category' => $seat['category'] ?? null];
                        }
                    }
                    if (!empty($allTableSeats)) {
                        foreach ($allTableSeats as $seat) {
                            $isFree = $isSeatFree('tableseat_' . $element['no'] . '_' . $seat['number']);
                            $totalSeats++;
                            $isFree ? $freeCount++ : $occupiedCount++;
                            $cells[] = ['kind' => 'seat', 'no' => $seat['number'], 'free' => $isFree,
                                        'cat' => $seat['category'] ?? $rowCategoryId, 'wheelchair' => false];
                            $unitsW += 1 + $SP;
                        }
                    } else {
                        $cells[] = ['kind' => 'table', 'no' => $element['no']];
                        $unitsW += $TBL + $SP;
                    }
                } elseif ($type === 'distance' || $type === 'spacer') {
                    $cells[] = ['kind' => 'distance', 'stairs' => !empty($element['stairs'])];
                    $unitsW += $DIST;
                }
            }

            $plan[] = ['label' => $label, 'cells' => $cells];
            $maxUnitsW = max($maxUnitsW, $unitsW);
        }

        // ── Seat size that fits everything on one A4 landscape page ──────────
        $numRows = count($plan);
        $startX  = 5;
        $availW  = 287;      // 297mm - 2×5mm margins
        $bodyTop = 29;       // below title + legend block
        $bodyBottom = 191;   // above summary block
        $availH  = $bodyBottom - $bodyTop;

        $seatWFit = $maxUnitsW > 0 ? ($availW / $maxUnitsW) : 8.0;
        $seatHFit = $numRows > 0 ? ($availH / ($numRows * (1 + $ROWSP))) : 8.0;
        $S = min($seatWFit, $seatHFit, 8.0);
        $S = max($S, 1.0);

        $spacing   = $SP * $S;
        $labelW    = $LBL * $S;
        $labelGap  = $LBLGAP * $S;
        $rowStep   = (1 + $ROWSP) * $S;
        $planDrawW = min($maxUnitsW * $S, $availW);
        $seatFont  = max(2.2, min(6.0, $S * 0.85));
        $labelFont = max(3.0, min(7.0, $S * 1.0));

        $drawColor = $bw ? [0, 0, 0] : [100, 100, 100];
        $setFreeFill = function ($pdf, $catId) use ($bw, $categoryColors, $hexToRgb) {
            if ($bw) { $pdf->SetFillColor(255, 255, 255); return; }
            if ($catId && isset($categoryColors[$catId])) {
                $rgb = $hexToRgb($categoryColors[$catId]);
                $pdf->SetFillColor(min(255, $rgb[0] + 100), min(255, $rgb[1] + 100), min(255, $rgb[2] + 100));
            } else {
                $pdf->SetFillColor(200, 255, 200);
            }
        };
        $setOccFill = function ($pdf) use ($bw) {
            $bw ? $pdf->SetFillColor(150, 150, 150) : $pdf->SetFillColor(255, 200, 200);
        };

        // ── Pass 2: render ───────────────────────────────────────────────────
        while (ob_get_level()) {
            ob_end_clean();
        }

        $pdf = new Fpdi();
        $pdf->SetAutoPageBreak(false);
        $pdf->SetCreator('Ticket System');
        $pdf->SetAuthor('Ticket System');
        $pdf->SetTitle($title . ': ' . $event['name']);
        $pdf->SetMargins(5, 5, 5);
        $pdf->AddPage('L', 'A4');

        // Title
        $pdf->SetFont('Helvetica', 'B', 14);
        $pdf->Cell(0, 8, $title, 0, 1, 'C');
        $pdf->SetFont('Helvetica', '', 11);
        $pdf->Cell(0, 7, $event['name'] . ' - ' . date('d.m.Y', strtotime($eventDate)), 0, 1, 'C');
        $pdf->Ln(1);

        // Legend
        $pdf->SetFont('Helvetica', '', 8);
        $legendY = $pdf->GetY();
        $legendX = 5;
        $setFreeFill($pdf, null);
        $pdf->SetDrawColor($drawColor[0], $drawColor[1], $drawColor[2]);
        $pdf->SetLineWidth(0.1);
        $pdf->Rect($legendX, $legendY, 8, 6, 'DF');
        $pdf->SetXY($legendX + 10, $legendY);
        $pdf->Cell(25, 6, 'Frei', 0, 0, 'L');

        $legendX += 45;
        $setOccFill($pdf);
        $pdf->Rect($legendX, $legendY, 8, 6, 'DF');
        $pdf->SetXY($legendX + 10, $legendY);
        $pdf->Cell(25, 6, 'Belegt', 0, 0, 'L');

        $legendX += 45;
        $pdf->SetFillColor(30, 30, 30);
        $pdf->Rect($legendX, $legendY, 8, 6, 'F');
        $pdf->SetXY($legendX + 10, $legendY);
        $pdf->Cell(30, 6, 'Bühne', 0, 1, 'L');

        // Body
        $currentY = $bodyTop;
        foreach ($plan as $entry) {
            if (!empty($entry['stage'])) {
                $stageH = max($S, 5);
                $pdf->SetFillColor(30, 30, 30);
                $pdf->SetDrawColor(30, 30, 30);
                $pdf->Rect($startX, $currentY, $planDrawW, $stageH, 'F');
                $pdf->SetFont('Helvetica', 'B', max(5, min(9, $S)));
                $pdf->SetTextColor(255, 255, 255);
                $pdf->SetXY($startX, $currentY + ($stageH - $S) / 2);
                $pdf->Cell($planDrawW, $S, 'BÜHNE', 0, 0, 'C');
                $currentY += $stageH + $ROWSP * $S;
                continue;
            }

            $pdf->SetXY($startX, $currentY);
            $pdf->SetFont('Helvetica', 'B', $labelFont);
            $pdf->SetTextColor(0, 0, 0);
            $pdf->Cell($labelW, $S, $entry['label'], 0, 0, 'R');

            $rowX = $startX + $labelW + $labelGap;
            $pdf->SetLineWidth(0.1);

            foreach ($entry['cells'] as $cell) {
                if ($cell['kind'] === 'distance') {
                    if (!empty($cell['stairs'])) {
                        // Stairs: white with black border, same size as a seat
                        $pdf->SetFillColor(255, 255, 255);
                        $pdf->SetDrawColor(0, 0, 0);
                        $pdf->SetLineWidth(max(0.2, $S * 0.08));
                        $pdf->Rect($rowX, $currentY, $S, $S, 'DF');
                        $pdf->SetLineWidth(0.1);
                    }
                    $rowX += $DIST * $S;
                    continue;
                }
                if ($cell['kind'] === 'table') {
                    $tw = $TBL * $S;
                    $pdf->SetFillColor(220, 220, 220);
                    $pdf->SetDrawColor($drawColor[0], $drawColor[1], $drawColor[2]);
                    $pdf->Rect($rowX, $currentY, $tw, $S, 'DF');
                    $pdf->SetFont('Helvetica', '', $seatFont);
                    $pdf->SetTextColor(0, 0, 0);
                    $pdf->SetXY($rowX, $currentY);
                    $pdf->Cell($tw, $S, 'T' . $cell['no'], 0, 0, 'C');
                    $rowX += $tw + $spacing;
                    continue;
                }
                // seat / wheelchair
                if ($cell['free']) {
                    $setFreeFill($pdf, $cell['cat']);
                } else {
                    $setOccFill($pdf);
                }
                $pdf->SetDrawColor($drawColor[0], $drawColor[1], $drawColor[2]);
                $pdf->Rect($rowX, $currentY, $S, $S, 'DF');
                $pdf->SetFont('Helvetica', '', $seatFont);
                $pdf->SetTextColor(0, 0, 0);
                $pdf->SetXY($rowX, $currentY);
                $label = $cell['wheelchair'] ? ('#' . $cell['no']) : (string)$cell['no'];
                $pdf->Cell($S, $S, $label, 0, 0, 'C');
                $rowX += $S + $spacing;
            }

            $currentY += $rowStep;
        }

        // Summary
        $summaryY = $bodyBottom + 1;
        $pdf->SetTextColor(0, 0, 0);
        $pdf->SetXY(5, $summaryY);
        $pdf->SetFont('Helvetica', 'B', 9);
        $pdf->Cell(0, 6, 'Zusammenfassung:', 0, 1, 'L');
        $pdf->SetFont('Helvetica', '', 8);

        $summaryX = 5;
        $setFreeFill($pdf, null);
        $pdf->SetDrawColor($drawColor[0], $drawColor[1], $drawColor[2]);
        $pdf->Rect($summaryX, $pdf->GetY(), 8, 6, 'DF');
        $pdf->SetXY($summaryX + 10, $pdf->GetY());
        $pdf->Cell(40, 6, 'Frei: ' . $freeCount, 0, 0, 'L');

        $summaryX += 60;
        $setOccFill($pdf);
        $pdf->Rect($summaryX, $pdf->GetY(), 8, 6, 'DF');
        $pdf->SetXY($summaryX + 10, $pdf->GetY());
        $pdf->Cell(40, 6, 'Belegt: ' . $occupiedCount, 0, 0, 'L');

        $summaryX += 60;
        $pdf->SetXY($summaryX, $pdf->GetY());
        $pdf->Cell(0, 6, 'Gesamt: ' . $totalSeats, 0, 1, 'L');

        $suffix = $bw ? '_sw' : '';
        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="' . $filenamePrefix . '_' . $eventDate . $suffix . '.pdf"');
        $pdf->Output();
        exit;
    }
}
