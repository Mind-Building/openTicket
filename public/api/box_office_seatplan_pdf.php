<?php
/**
 * Box Office Seat Plan PDF (presales + admin).
 * Thin wrapper around App\Services\SeatPlanPdfGenerator so the output is
 * identical to admin/presales box_office_plan.php.
 *
 * GET params: event_id, event_date (Y-m-d), bw=1 (optional), mode=occupancy (optional)
 */

require_once __DIR__ . '/../../app/bootstrap.php';

use App\Core\Auth;
use App\Core\Session;
use App\Services\SeatPlanPdfGenerator;

Session::start();
Auth::requireAuth();

if (!Auth::hasRole('presales') && !Auth::isAdmin()) {
    http_response_code(403);
    die('Zugriff verweigert');
}

$eventId   = isset($_GET['event_id']) ? (int)$_GET['event_id'] : 0;
// Accept both `event_date` (legacy) and `date`
$eventDate = isset($_GET['event_date']) ? trim((string)$_GET['event_date'])
           : (isset($_GET['date']) ? trim((string)$_GET['date']) : '');
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
    error_log('box_office_seatplan_pdf.php: ' . $e->getMessage());
    die('Fehler beim Generieren der PDF-Datei: ' . htmlspecialchars($e->getMessage()));
}
