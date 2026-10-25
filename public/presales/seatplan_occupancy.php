<?php
require_once __DIR__ . '/../../app/bootstrap.php';

use App\Core\Auth;
use App\Core\Config;
use App\Models\Event;
use App\Models\SeatPlan;
use App\Models\SeatCategory;
use App\Models\Reservation;

Auth::requirePresales();

$eventModel       = new Event();
$seatPlanModel    = new SeatPlan();
$categoryModel    = new SeatCategory();
$reservationModel = new Reservation();

$eventId   = (int)($_GET['event_id'] ?? 0);
$eventDate = $_GET['date'] ?? '';

$events     = $eventModel->getAll(false); // include inactive for presales
$event      = $eventId ? $eventModel->getById($eventId) : null;
$eventDates = $eventId ? $eventModel->getDates($eventId) : [];

$seatPlan      = [];
$categories    = [];
$reservedSeats = [];
if ($event && $eventDate) {
    $seatPlan      = $seatPlanModel->getSeatPlanStructure($eventId);
    $categories    = $categoryModel->getByEvent($eventId);
    $reservedSeats = $reservationModel->getReservedSeatsWithDetails($eventId, $eventDate);
}

$pageTitle   = 'Sitzplan-Belegung';
$showSidebar = true;
$showHeader  = true;

// Status helpers: use central reservationStatusLabel() so all views show the same label.
function getSeatStatusLabelP(int $statusId): string {
    return reservationStatusLabel($statusId);
}

function getSeatStatusColorP(int $statusId): string {
    return '#dc3545'; // Alle belegten Plätze rot, egal welcher Status
}

ob_start();
?>
<link rel="stylesheet" href="<?= assetUrl('libs/css/seatplan-fit.css') ?>">
<script src="<?= assetUrl('libs/js/seatplan-zoom.js') ?>" defer></script>
<style>
.seatplan-container { background:#f8f9fa; padding:1rem; border-radius:.5rem; overflow-x:auto; }
.seatplan-row { display:flex; align-items:center; margin-bottom:.4rem; gap:.2rem; }
.row-label { min-width:60px; font-weight:bold; text-align:right; font-size:.82rem; color:#555; flex-shrink:0; }
.seatplan-elements { display:flex; flex-wrap:nowrap; gap:.2rem; flex:1; }
.seat-el {
    padding:.25rem .35rem; border-radius:.2rem; font-size:.73rem;
    min-width:28px; text-align:center; white-space:nowrap;
    border:4px solid transparent; cursor:default; user-select:none;
    position:relative;
}
.seat-free    { background:#28a745; color:#fff; }
.seat-occupied{ color:#fff; }
.stage-el { background:#212529; color:#fff; width:100%; text-align:center; padding:.8rem; font-weight:bold; border-radius:.2rem; font-size:.85rem; }
.distance-el { padding:.25rem .35rem; min-width:28px; }
.legend { display:flex; gap:1rem; flex-wrap:wrap; margin-top:1rem; align-items:center; }
.legend-item { display:flex; align-items:center; gap:.4rem; font-size:.82rem; }
.legend-box { width:22px; height:22px; border-radius:.2rem; border:1px solid rgba(0,0,0,.15); flex-shrink:0; }

.seat-el[data-tip]:hover::after {
    content: attr(data-tip);
    position: absolute;
    z-index: 9999;
    bottom: calc(100% + 6px);
    left: 50%;
    transform: translateX(-50%);
    background: rgba(0,0,0,.88);
    color: #fff;
    padding: .35rem .6rem;
    border-radius: .3rem;
    font-size: .75rem;
    white-space: pre;
    pointer-events: none;
    min-width: 160px;
    max-width: 260px;
    line-height: 1.5;
    text-align: left;
    box-shadow: 0 2px 8px rgba(0,0,0,.35);
}
.seat-el[data-tip]:hover::before {
    content: '';
    position: absolute;
    z-index: 9999;
    bottom: calc(100% + 2px);
    left: 50%;
    transform: translateX(-50%);
    border: 4px solid transparent;
    border-top-color: rgba(0,0,0,.88);
}
/* Tooltip unterhalb für erste Reihe */
.seat-el.tooltip-below[data-tip]:hover::after {
    bottom: auto;
    top: calc(100% + 6px);
}
.seat-el.tooltip-below[data-tip]:hover::before {
    bottom: auto;
    top: calc(100% + 2px);
    border-top-color: transparent;
    border-bottom-color: rgba(0,0,0,.88);
}
</style>

<div class="card mb-3">
    <div class="card-body py-2">
        <form method="GET" action="" class="row g-2 align-items-end">
            <div class="col-md-4">
                <label class="form-label mb-1 small fw-bold">Veranstaltung</label>
                <select class="form-select form-select-sm" name="event_id" onchange="this.form.submit()">
                    <option value="">Bitte wählen...</option>
                    <?php foreach ($events as $ev): ?>
                        <option value="<?= $ev['id'] ?>" <?= $ev['id'] == $eventId ? 'selected' : '' ?>>
                            <?= htmlspecialchars($ev['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label mb-1 small fw-bold">Datum</label>
                <select class="form-select form-select-sm" name="date" <?= empty($eventDates) ? 'disabled' : '' ?>>
                    <option value="">Bitte wählen...</option>
                    <?php foreach ($eventDates as $ed): ?>
                        <option value="<?= $ed['date'] ?>" <?= $ed['date'] === $eventDate ? 'selected' : '' ?>>
                            <?= date('d.m.Y', strtotime($ed['date'])) ?>
                            <?php if (!empty($ed['time'])): ?>(<?= substr($ed['time'], 0, 5) ?> Uhr)<?php endif; ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <button type="submit" class="btn btn-sm btn-primary w-100">
                    <i class="bi bi-search"></i> Anzeigen
                </button>
            </div>
            <?php if ($event && $eventDate): ?>
            <div class="col-md-3 text-end">
                <small class="text-muted">
                    <?= count($reservedSeats) ?> Platz/Plätze belegt
                    &bull; <?= date('d.m.Y', strtotime($eventDate)) ?>
                </small>
            </div>
            <?php endif; ?>
        </form>
    </div>
</div>

<?php if ($event && $eventDate): ?>

<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
        <h5 class="mb-0 card-title">
            <i class="bi bi-grid-3x3-gap-fill"></i>
            <?= htmlspecialchars($event['name']) ?> &mdash; <?= date('d.m.Y', strtotime($eventDate)) ?>
        </h5>
        <div class="d-flex flex-wrap gap-2">
            <a href="<?= url('presales/box_office_plan.php?event_id=' . $eventId . '&date=' . urlencode($eventDate) . '&mode=occupancy') ?>" target="_blank" class="btn btn-sm btn-outline-primary">
                <i class="bi bi-file-earmark-pdf"></i> Als PDF drucken (Farbe)
            </a>
            <a href="<?= url('presales/box_office_plan.php?event_id=' . $eventId . '&date=' . urlencode($eventDate) . '&mode=occupancy&bw=1') ?>" target="_blank" class="btn btn-sm btn-outline-secondary">
                <i class="bi bi-file-earmark-pdf"></i> Als PDF drucken (S/W)
            </a>
        </div>
    </div>
    <div class="card-body p-2">
        <div class="seatplan-container seatplan-fit" style="--n: <?= \App\Helpers\SeatPlanLayout::maxSlots($seatPlan ?? []) ?>">
        <?php if (empty($seatPlan)): ?>
            <p class="text-muted text-center">Kein Sitzplan für diese Veranstaltung.</p>
        <?php else: ?>
            <?php $firstSeatRow = true; ?>
            <?php foreach ($seatPlan as $row): ?>
                <?php if ($row['type'] !== 'row' || !isset($row['no'])) continue; ?>

                <?php if ($row['no'] === 0): ?>
                    <div class="seatplan-row">
                        <div class="stage-el"><i class="bi bi-music-note-beamed"></i> BÜHNE</div>
                    </div>
                <?php else: ?>
                    <div class="seatplan-row">
                        <div class="row-label">Reihe <?= $row['no'] ?></div>
                        <div class="seatplan-elements">
                        <?php foreach ($row['content'] as $element):
                            if ($element['type'] === 'seat') {
                                $seatId   = 'seat_' . $row['no'] . '_' . $element['no'];
                                $res      = $reservedSeats[$seatId] ?? null;
                                $category = null;
                                foreach ($categories as $cat) {
                                    if ($cat['id'] == ($element['category'] ?? 0)) { $category = $cat; break; }
                                }
                                $borderColor = $category ? ($category['color'] ?: '#0d6efd') : '#0d6efd';
                                $tipClass    = $firstSeatRow ? ' tooltip-below' : '';

                                if ($res) {
                                    $bgColor   = getSeatStatusColorP((int)$res['status_id']);
                                    $cssClass  = 'seat-el seat-occupied' . $tipClass;
                                    $style     = 'background:' . $bgColor . ';border-color:' . $borderColor . ';';
                                    $custName  = trim(($res['first_name'] ?? '') . ' ' . ($res['last_name'] ?? ''));
                                    $isComp    = !empty($res['is_companion']);
                                    $priceVal  = $isComp ? 0.0 : (float)($res['category_price'] ?? 0);
                                    $tooltip   = 'Platz: ' . $element['no'] . " (Reihe {$row['no']})\n"
                                               . 'Status: ' . getSeatStatusLabelP((int)$res['status_id']) . "\n"
                                               . 'Kunde: ' . ($custName ?: 'Kein Kunde') . "\n"
                                               . 'Kategorie: ' . ($res['category_name'] ?? '-') . "\n"
                                               . 'Preis: ' . number_format($priceVal, 2, ',', '.') . ' €'
                                               . ($isComp ? ' (Begleitperson kostenfrei)' : '');
                                } else {
                                    $cssClass = 'seat-el seat-free' . $tipClass;
                                    $style    = 'border-color:' . $borderColor . ';';
                                    $tooltip  = 'Frei – Reihe ' . $row['no'] . ', Platz ' . $element['no'];
                                    if ($category) {
                                        $tooltip .= "\nKategorie: " . $category['name'];
                                    }
                                }
                                echo '<span class="' . $cssClass . '" style="' . $style . '" data-tip="' . htmlspecialchars($tooltip) . '">'
                                   . $element['no'] . '</span>';

                            } elseif ($element['type'] === 'wheelchair') {
                                $seatId   = 'wheelchair_' . $row['no'] . '_' . $element['no'];
                                $res      = $reservedSeats[$seatId] ?? null;
                                $category = null;
                                foreach ($categories as $cat) {
                                    if ($cat['id'] == ($element['category'] ?? 0)) { $category = $cat; break; }
                                }
                                $borderColor = $category ? ($category['color'] ?: '#0d6efd') : '#0d6efd';
                                $tipClass    = $firstSeatRow ? ' tooltip-below' : '';

                                if ($res) {
                                    $bgColor   = getSeatStatusColorP((int)$res['status_id']);
                                    $cssClass  = 'seat-el seat-occupied' . $tipClass;
                                    $style     = 'background:' . $bgColor . ';border-color:' . $borderColor . ';';
                                    $custName  = trim(($res['first_name'] ?? '') . ' ' . ($res['last_name'] ?? ''));
                                    $isComp    = !empty($res['is_companion']);
                                    $priceVal  = $isComp ? 0.0 : (float)($res['category_price'] ?? 0);
                                    $tooltip   = 'Rollstuhlplatz: ' . $element['no'] . " (Reihe {$row['no']})\n"
                                               . 'Status: ' . getSeatStatusLabelP((int)$res['status_id']) . "\n"
                                               . 'Kunde: ' . ($custName ?: 'Kein Kunde') . "\n"
                                               . 'Kategorie: ' . ($res['category_name'] ?? '-') . "\n"
                                               . 'Preis: ' . number_format($priceVal, 2, ',', '.') . ' €'
                                               . ($isComp ? ' (Begleitperson kostenfrei)' : '');
                                } else {
                                    $cssClass = 'seat-el seat-free' . $tipClass;
                                    $style    = 'border-color:' . $borderColor . ';';
                                    $tooltip  = 'Frei – Rollstuhlplatz, Reihe ' . $row['no'] . ', Platz ' . $element['no'];
                                    if ($category) {
                                        $tooltip .= "\nKategorie: " . $category['name'];
                                    }
                                }
                                echo '<span class="' . $cssClass . '" style="' . $style . '" data-tip="' . htmlspecialchars($tooltip) . '">'
                                   . '<i class="bi bi-person-wheelchair"></i> ' . $element['no'] . '</span>';

                            } elseif ($element['type'] === 'table') {
                                echo '<div style="display:inline-flex;flex-direction:column;align-items:center;gap:.2rem;">';
                                foreach (['front', 'back'] as $side) {
                                    if (empty($element['seats'][$side])) continue;
                                    echo '<div style="display:flex;gap:.2rem;">';
                                    foreach ($element['seats'][$side] as $seat) {
                                        $tseatId  = 'tableseat_' . $element['no'] . '_' . $seat['number'];
                                        $res      = $reservedSeats[$tseatId] ?? null;
                                        $seatCat  = null;
                                        foreach ($categories as $cat) {
                                            if ($cat['id'] == ($seat['category'] ?? 0)) { $seatCat = $cat; break; }
                                        }
                                        $bc = $seatCat ? ($seatCat['color'] ?: '#0d6efd') : '#0d6efd';
                                        if ($res) {
                                            $bgColor  = getSeatStatusColorP((int)$res['status_id']);
                                            $cssClass = 'seat-el seat-occupied';
                                            $style    = 'background:' . $bgColor . ';border-color:' . $bc . ';';
                                            $custName = trim(($res['first_name'] ?? '') . ' ' . ($res['last_name'] ?? ''));
                                            $tooltip  = 'Tisch ' . $element['no'] . ", Platz {$seat['number']}\n"
                                                      . 'Status: ' . getSeatStatusLabelP((int)$res['status_id']) . "\n"
                                                      . 'Kunde: ' . ($custName ?: 'Kein Kunde') . "\n"
                                                      . 'Kategorie: ' . ($res['category_name'] ?? '-') . "\n"
                                                      . 'Preis: ' . number_format((float)($res['category_price'] ?? 0), 2, ',', '.') . ' €';
                                        } else {
                                            $cssClass = 'seat-el seat-free';
                                            $style    = 'border-color:' . $bc . ';';
                                            $tooltip  = 'Frei – Tisch ' . $element['no'] . ', Platz ' . $seat['number'];
                                        }
                                        echo '<span class="' . $cssClass . '" style="' . $style . '" data-tip="' . htmlspecialchars($tooltip) . '">'
                                           . $seat['number'] . '</span>';
                                    }
                                    echo '</div>';
                                    if ($side === 'front') {
                                        echo '<div style="width:46px;height:12px;background:#8B4513;border-radius:3px;"></div>';
                                    }
                                }
                                echo '</div>';

                            } elseif ($element['type'] === 'distance' || $element['type'] === 'spacer') {
                                echo '<span class="distance-el"' . seatplanStairsAttr($element) . '></span>';
                            } elseif ($element['type'] === 'stage') {
                                echo '<div class="stage-el" style="background:#6c757d;padding:4px 8px;">'
                                   . htmlspecialchars($element['label'] ?? 'Bühne') . '</div>';
                            }
                        endforeach; ?>
                        </div>
                    </div>
                <?php $firstSeatRow = false; endif; ?>
            <?php endforeach; ?>
        <?php endif; ?>
        </div>

        <div class="legend mt-3">
            <div class="legend-item">
                <div class="legend-box" style="background:#28a745;"></div><span>Frei</span>
            </div>
            <div class="legend-item">
                <div class="legend-box" style="background:#dc3545;"></div><span>Belegt</span>
            </div>
            <?php if (isset(seatplanElementTypes($seatPlan ?? [])['stairs'])): ?>
            <div class="legend-item">
                <div class="legend-box" style="background:#fff;border:2px solid #000;"></div><span>Treppe</span>
            </div>
            <?php endif; ?>
            <?php foreach ($categories as $cat): ?>
                <?php if ($cat['color']): ?>
                    <div class="legend-item">
                        <div class="legend-box" style="background:transparent;border:4px solid <?= htmlspecialchars($cat['color']) ?>;"></div>
                        <span><?= htmlspecialchars($cat['name']) ?></span>
                    </div>
                <?php endif; ?>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<?php elseif ($eventId && empty($eventDate)): ?>
    <div class="alert alert-info">Bitte ein Datum auswählen.</div>
<?php else: ?>
    <div class="alert alert-secondary">Bitte eine Veranstaltung und ein Datum wählen.</div>
<?php endif; ?>

<?php
$content = ob_get_clean();
require __DIR__ . '/../../app/views/layouts/main.php';
