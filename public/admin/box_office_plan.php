<?php
/**
 * Seat plan PDF (free vs. occupied) for admin.
 * Used by the box office (freie Plätze) and the seat plan occupancy print
 * (?mode=occupancy). Rendering lives in App\Services\SeatPlanPdfGenerator so
 * every printout looks identical.
 *
 * GET: event_id, date (Y-m-d), bw=1 (optional), mode=occupancy (optional)
 */

require_once __DIR__ . '/../../app/bootstrap.php';

use App\Core\Auth;
use App\Services\SeatPlanPdfGenerator;

Auth::requireAdmin();

$eventId   = isset($_GET['event_id']) ? (int)$_GET['event_id'] : 0;
$eventDate = isset($_GET['date']) ? (string)$_GET['date'] : '';
$bw        = isset($_GET['bw']) && $_GET['bw'] === '1';
$occupancy = ($_GET['mode'] ?? '') === 'occupancy';

try {
    (new SeatPlanPdfGenerator())->output(
        $eventId,
        $eventDate,
        $bw,
        $occupancy ? 'Sitzplan-Belegung' : 'Sitzplan - Freie Plätze',
        $occupancy ? 'sitzplan_belegung' : 'sitzplan_freie_plaetze'
    );
} catch (\Throwable $e) {
    error_log('box_office_plan.php: ' . $e->getMessage());
    die('Fehler beim Generieren der PDF-Datei: ' . htmlspecialchars($e->getMessage()));
}
