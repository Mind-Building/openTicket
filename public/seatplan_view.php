<?php
/**
 * Customer-facing seatplan view – no admin login required.
 * Access is secured via a HMAC token bound to the exact set of reservation IDs.
 */
require_once __DIR__ . '/../app/bootstrap.php';

use App\Core\Session;
use App\Models\Event;
use App\Models\SeatPlan;
use App\Models\SeatCategory;
use App\Models\Reservation;

Session::start();

$eventId    = (int)($_GET['event_id'] ?? 0);
$eventDate  = $_GET['date'] ?? '';
$idsParam   = $_GET['reservation_ids'] ?? '';
$token      = $_GET['token'] ?? '';

// ── Validate required parameters ──────────────────────────────────────────────
if (!$eventId || !$eventDate || !$idsParam || !$token) {
    http_response_code(400);
    die('<p style="font-family:sans-serif;color:red;padding:2rem;">Ungültige oder fehlende Parameter.</p>');
}

$reservationIds = array_filter(array_map('intval', explode(',', $idsParam)));
if (empty($reservationIds)) {
    http_response_code(400);
    die('<p style="font-family:sans-serif;color:red;padding:2rem;">Keine gültigen Reservierungs-IDs.</p>');
}

// ── Verify HMAC token ──────────────────────────────────────────────────────────
$expectedToken = generateSeatplanViewToken(array_values($reservationIds));
if (!hash_equals($expectedToken, $token)) {
    http_response_code(403);
    die('<p style="font-family:sans-serif;color:red;padding:2rem;">Ungültiger oder abgelaufener Link.</p>');
}

// ── Load models ────────────────────────────────────────────────────────────────
$eventModel       = new Event();
$seatPlanModel    = new SeatPlan();
$categoryModel    = new SeatCategory();
$reservationModel = new Reservation();

$event = $eventModel->getById($eventId);
if (!$event) {
    http_response_code(404);
    die('<p style="font-family:sans-serif;color:red;padding:2rem;">Veranstaltung nicht gefunden.</p>');
}

$seatPlan      = $seatPlanModel->getSeatPlanStructure($eventId);
$categories    = $categoryModel->getByEvent($eventId);
$reservedSeats = $reservationModel->getReservedSeats($eventId, $eventDate);

// ── Collect seat identifiers belonging to THIS reservation ─────────────────────
$highlightIds = [];
foreach ($reservationIds as $id) {
    $res = $reservationModel->getById($id);
    if ($res && !empty($res['seat_identifier'])) {
        $highlightIds[] = $res['seat_identifier'];
    }
}

$pageTitle = htmlspecialchars($event['name']) . ' – Ihr Sitzplan';
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $pageTitle ?></title>
    <link href="<?= url('libs/css/bootstrap.min.css') ?>" rel="stylesheet">
    <link href="<?= url('libs/css/bootstrap-icons.css') ?>" rel="stylesheet">
    <style>
        .seatplan-container { background: #f8f9fa; padding: 1rem; border-radius: .5rem; overflow-x: auto; }
        .seatplan-row { display: flex; align-items: center; margin-bottom: .5rem; gap: .25rem; }
        .row-label { min-width: 60px; font-weight: bold; text-align: right; font-size: .85rem; }
        .seatplan-elements { display: flex; flex-wrap: nowrap; gap: .25rem; flex: 1; }
        .seat-element {
            padding: .25rem .4rem; border-radius: .2rem; font-size: .75rem;
            min-width: 28px; text-align: center; white-space: nowrap;
            border: 3px solid transparent; cursor: default; user-select: none;
        }
        .seat-free     { background: #28a745; color: white; }
        .seat-reserved { background: #dc3545; color: white; }
        .seat-highlight {
            background: #ff8c00; color: white;
            border-color: #000 !important;
            box-shadow: 0 0 6px 2px rgba(255,140,0,.7);
        }
        .distance-element {
            padding: .25rem .4rem; font-size: .75rem;
            min-width: 28px; text-align: center; background: transparent; border: none;
        }
        .stage-element {
            background: #212529; color: white; width: 100%;
            text-align: center; padding: 1rem; font-weight: bold; border-radius: .2rem;
        }
        .legend { display: flex; gap: 1rem; flex-wrap: wrap; margin-top: 1rem; }
        .legend-item { display: flex; align-items: center; gap: .5rem; font-size: .85rem; }
        .legend-box { width: 22px; height: 22px; border-radius: .2rem; border: 2px solid rgba(0,0,0,.2); }
    /* Seats and distances share one fixed slot width so seat numbers line up across rows */
.seatplan-row .seat-element, .seatplan-row .distance-element {
    display: inline-block; box-sizing: border-box;
    width: 2.5rem; min-width: 2.5rem; flex: 0 0 2.5rem;
    padding-left: 0; padding-right: 0;
}
</style>
</head>
<body>
<nav class="navbar navbar-dark bg-primary">
    <div class="container">
        <span class="navbar-brand"><i class="bi bi-grid"></i> Sitzplan – Ihre Plätze</span>
    </div>
</nav>

<div class="container-fluid mt-4 mb-5">
    <h4><?= htmlspecialchars($event['name']) ?> &mdash; <?= date('d.m.Y', strtotime($eventDate)) ?></h4>

    <?php if (!empty($highlightIds)): ?>
        <div class="alert alert-warning py-2">
            <i class="bi bi-geo-alt-fill"></i>
            <strong>Ihre reservierten Plätze:</strong>
            <?= implode(', ', array_map(function($sid) use ($seatPlanModel, $eventId) {
                $name = $seatPlanModel->getSeatDisplayName($eventId, $sid);
                return htmlspecialchars($name ?: $sid);
            }, $highlightIds)) ?>
        </div>
    <?php endif; ?>

    <div class="card">
        <div class="card-body p-2">
            <div class="seatplan-container">
                <?php if (empty($seatPlan)): ?>
                    <p class="text-muted text-center">Kein Sitzplan für diese Veranstaltung.</p>
                <?php else: ?>
                    <?php foreach ($seatPlan as $row): ?>
                        <?php if ($row['type'] !== 'row' || !isset($row['no'])) continue; ?>
                        <?php if ($row['no'] === 0): ?>
                            <div class="seatplan-row">
                                <div class="stage-element"><i class="bi bi-music-note-beamed"></i> BÜHNE</div>
                            </div>
                        <?php else: ?>
                            <div class="seatplan-row">
                                <div class="row-label">Reihe <?= $row['no'] ?></div>
                                <div class="seatplan-elements">
                                    <?php foreach ($row['content'] as $element): ?>
                                        <?php
                                        if ($element['type'] === 'seat') {
                                            $seatId = 'seat_' . $row['no'] . '_' . $element['no'];
                                            $isHighlight = in_array($seatId, $highlightIds);
                                            $isReserved  = isset($reservedSeats[$seatId]);
                                            $category = null;
                                            foreach ($categories as $cat) {
                                                if ($cat['id'] == ($element['category'] ?? 0)) { $category = $cat; break; }
                                            }
                                            $borderColor = $category ? ($category['color'] ?: '#0d6efd') : '#0d6efd';
                                            $cssClass = $isHighlight ? 'seat-highlight' : ($isReserved ? 'seat-reserved' : 'seat-free');
                                            echo '<span class="seat-element ' . $cssClass . '" '
                                                . 'style="border-color:' . ($isHighlight ? '#000' : $borderColor) . ';" '
                                                . 'title="' . ($isHighlight ? 'Ihr Platz' : ($isReserved ? 'Belegt' : 'Frei')) . '">'
                                                . $element['no'] . '</span>';
                                        } elseif ($element['type'] === 'table') {
                                            echo '<div style="display:inline-flex;flex-direction:column;align-items:center;gap:.25rem;">';
                                            foreach (['front', 'back'] as $side) {
                                                if (empty($element['seats'][$side])) continue;
                                                echo '<div style="display:flex;gap:.25rem;">';
                                                foreach ($element['seats'][$side] as $seat) {
                                                    $tableSeatId = 'tableseat_' . $element['no'] . '_' . $seat['number'];
                                                    $isHighlight = in_array($tableSeatId, $highlightIds);
                                                    $isReserved  = isset($reservedSeats[$tableSeatId]);
                                                    $seatCat = null;
                                                    foreach ($categories as $cat) {
                                                        if ($cat['id'] == ($seat['category'] ?? 0)) { $seatCat = $cat; break; }
                                                    }
                                                    $bc = $seatCat ? ($seatCat['color'] ?: '#0d6efd') : '#0d6efd';
                                                    $cssClass = $isHighlight ? 'seat-highlight' : ($isReserved ? 'seat-reserved' : 'seat-free');
                                                    echo '<span class="seat-element ' . $cssClass . '" '
                                                        . 'style="border-color:' . ($isHighlight ? '#000' : $bc) . ';" '
                                                        . 'title="' . ($isHighlight ? 'Ihr Platz' : ($isReserved ? 'Belegt' : 'Frei')) . '">'
                                                        . $seat['number'] . '</span>';
                                                }
                                                echo '</div>';
                                                if ($side === 'front') {
                                                    echo '<div style="width:50px;height:14px;background:#8B4513;border-radius:3px;"></div>';
                                                }
                                            }
                                            echo '</div>';
                                        } elseif ($element['type'] === 'distance' || $element['type'] === 'spacer') {
                                            echo '<span class="distance-element"' . seatplanStairsAttr($element) . '></span>';
                                        } elseif ($element['type'] === 'stage') {
                                            echo '<div class="stage-element" style="background:#6c757d;padding:5px 10px;border-radius:4px;">'
                                                . htmlspecialchars($element['label'] ?? 'Bühne') . '</div>';
                                        }
                                        ?>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endif; ?>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <div class="legend mt-3">
                <div class="legend-item">
                    <div class="legend-box" style="background:#ff8c00;border-color:#000;box-shadow:0 0 4px rgba(255,140,0,.7);"></div>
                    <span>Ihr Platz</span>
                </div>
                <div class="legend-item">
                    <div class="legend-box" style="background:#dc3545;"></div>
                    <span>Anderweitig belegt</span>
                </div>
                <div class="legend-item">
                    <div class="legend-box" style="background:#28a745;"></div>
                    <span>Frei</span>
                </div>
                <?php foreach ($categories as $cat): ?>
                    <?php if ($cat['color']): ?>
                        <div class="legend-item">
                            <div class="legend-box" style="background:<?= htmlspecialchars($cat['color']) ?>;"></div>
                            <span><?= htmlspecialchars($cat['name']) ?></span>
                        </div>
                    <?php endif; ?>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</div>
<script src="<?= url('libs/js/bootstrap.bundle.min.js') ?>"></script>
</body>
</html>
