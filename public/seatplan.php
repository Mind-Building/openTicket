<?php
require_once __DIR__ . '/../app/bootstrap.php';

use App\Core\Session;
use App\Core\Config;
use App\Models\Event;
use App\Models\SeatPlan;
use App\Models\SeatCategory;
use App\Models\Reservation;

Session::start();

// Prevent caching - seat availability changes in real time
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

$eventId = (int)($_GET['event_id'] ?? 0);
$eventDate = $_GET['date'] ?? '';

$eventModel = new Event();
$event = $eventModel->getById($eventId);

(new Reservation())->cleanupExpiredCartItems();

if (!$event || (!$event['active'] && !isAdminEventPreview($event)) || !$event['use_seat_plan'] || empty($eventDate)) {
    header('Location: ' . url('event.php?id=' . $eventId));
    exit;
}

// Check if event date is still bookable
$maxReservationTime = $event['max_reservation_time'] ?? '12:00:00';
$bookableCheck = isEventDateBookable($eventDate, $maxReservationTime);
if (!$bookableCheck['bookable']) {
    $_SESSION['error_message'] = $bookableCheck['reason'];
    header('Location: ' . url('event.php?id=' . $eventId));
    exit;
}

// Get show start time from event_date
$showTime = $event['show_start_time'] ?? '20:30:00';
$eventDateData = $eventModel->getDateByEventAndDate($eventId, $eventDate);
if ($eventDateData && !empty($eventDateData['show_start_time'])) {
    $showTime = $eventDateData['show_start_time'];
}

$seatPlanModel = new SeatPlan();
$categoryModel = new SeatCategory();
$reservationModel = new Reservation();

$categories = $categoryModel->getByEvent($eventId);
$seatPlan = $seatPlanModel->getSeatPlanStructure($eventId);
$reservedSeats = $reservationModel->getReservedSeats($eventId, $eventDate);
$sessionId = session_id();
$cartItems = $reservationModel->getBySession($sessionId);
$cartSeatIds = array_column($cartItems, 'seat_identifier');

$pageTitle = htmlspecialchars($event['name']) . ' - Sitzplan';
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $pageTitle ?></title>
    <link href="<?= url('libs/css/bootstrap.min.css') ?>" rel="stylesheet">
    <link href="<?= url('libs/css/bootstrap-icons.css') ?>" rel="stylesheet">
    <link href="<?= assetUrl('libs/css/seatplan-fit.css') ?>" rel="stylesheet">
    <style>
        .seatplan-container {
            background: #f8f9fa;
            padding: 1rem;
            border-radius: 0.5rem;
            margin: 1rem 0;
            width: 100%;
            max-width: 100%;
            overflow-x: visible;
        }
        .seatplan-row {
            display: flex;
            align-items: center;
            margin-bottom: 0.5rem;
            gap: 0.25rem;
        }
        .row-label {
            min-width: 60px;
            font-weight: bold;
            text-align: right;
            font-size: 0.85rem;
        }
        .seatplan-elements {
            display: flex;
            flex-wrap: nowrap;
            gap: 0.25rem;
            flex: 1;
            overflow-x: visible;
            min-width: 0;
        }
        .seat-element, .table-element, .distance-element, .stage-element {
            padding: 0.25rem 0.4rem;
            border-radius: 0.2rem;
            cursor: pointer;
            transition: all 0.2s;
            user-select: none;
            border: 4px solid transparent;
            font-size: 0.75rem;
            min-width: 28px;
            text-align: center;
            white-space: nowrap;
        }
        .seat-element {
            background: #28a745;
            color: white;
            border-color: #0d6efd;
        }
        .seat-element:hover {
            transform: scale(1.05);
            box-shadow: 0 2px 4px rgba(0,0,0,0.2);
        }
        .seat-element.reserved {
            background: #dc3545;
            color: white;
            cursor: not-allowed;
        }
        .seat-element.in-cart {
            background: #ffc107;
            color: #212529;
        }
        .seat-element.selected {
            background: #ffc107;
            color: #212529;
        }
        .table-element {
            background: #6c757d !important;
            color: white !important;
            border: 1px solid #5a6268 !important;
        }
        .distance-element {
            background: #6c757d !important;
            color: white !important;
            border: 1px solid #6c757d !important;
            cursor: not-allowed !important;
            pointer-events: auto;
            width: 28px;
            min-width: 28px;
            flex-shrink: 0;
        }
        .distance-element:hover {
            transform: none !important;
            box-shadow: none !important;
        }
        .stage-element {
            background: #212529;
            color: white;
            width: 100%;
            text-align: center;
            padding: 1rem;
            font-weight: bold;
            cursor: default;
        }
        .legend {
            display: flex;
            gap: 1rem;
            flex-wrap: wrap;
            margin-top: 1rem;
        }
        .legend-item {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
    </style>
</head>
<body>
    <?php
    use App\Core\Auth;
    $isLoggedIn = Auth::check();
    $userRole = Auth::role();
    ?>
    <nav class="navbar navbar-expand-lg navbar-dark bg-primary">
        <div class="container">
            <a class="navbar-brand" href="<?= url('') ?>">
                <i class="bi bi-ticket-perforated"></i> Ticket System
            </a>
            <div class="ms-auto d-flex gap-2">
                <?php if ($isLoggedIn && $userRole === 'presales'): ?>
                    <a href="<?= url('presales/index.php') ?>" class="btn btn-outline-light btn-sm">
                        <i class="bi bi-house-door"></i> Vorverkauf
                    </a>
                <?php elseif ($isLoggedIn && $userRole === 'admin'): ?>
                    <a href="<?= url('admin/index.php') ?>" class="btn btn-outline-light btn-sm">
                        <i class="bi bi-house-door"></i> Admin
                    </a>
                <?php endif; ?>
                <a href="<?= url('cart.php') ?>" class="btn btn-outline-light btn-sm">
                    <i class="bi bi-cart"></i> Warenkorb (<span id="cart-count"><?= count($cartItems) ?></span>)
                </a>
            </div>
        </div>
    </nav>
    <?php $companyLogo = Config::get('company_logo', ''); if ($companyLogo): ?>
    <div style="position: fixed; top: 70px; right: 50px; z-index: 1000; pointer-events: none;">
        <img src="<?= url($companyLogo) ?>" alt="Logo" style="height: 100px; max-width: 250px; object-fit: contain;">
    </div>
    <?php endif; ?>

    <div class="container-fluid mt-5 mb-5">
        <?= adminEventPreviewBanner($event) ?>
        <div id="feedback-container" style="position: fixed; top: 70px; right: 20px; z-index: 9999; max-width: 400px;"></div>
        <div class="row mb-4">
            <div class="col-12">
                <a href="<?= url('event.php?id=' . $eventId . '&date=' . $eventDate) ?>" class="btn btn-outline-secondary mb-3">
                    <i class="bi bi-arrow-left"></i> Zurück
                </a>
                <h1><?= htmlspecialchars($event['name']) ?></h1>
                <p class="text-muted">
                    <i class="bi bi-calendar"></i> <?= date('d.m.Y', strtotime($eventDate)) ?> 
                    <i class="bi bi-clock ms-3"></i> <?= date('H:i', strtotime($showTime)) ?> Uhr
                </p>
            </div>
        </div>

        <!-- Sitzplan und Ausgewählte Plätze nebeneinander -->
        <div class="row">
            <!-- Sitzplan (75%) -->
            <div class="col-md-9">
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0">Sitzplan</h5>
                    </div>
                    <div class="card-body">
                        <div class="seatplan-container seatplan-fit" id="seatplan-container" style="--n: <?= \App\Helpers\SeatPlanLayout::maxSlots($seatPlan ?? []) ?>">
                            <?php if (empty($seatPlan)): ?>
                                <p class="text-muted text-center">Für diese Veranstaltung ist noch kein Sitzplan vorhanden.</p>
                            <?php else: ?>
                                <?php foreach ($seatPlan as $row): ?>
                                    <?php if ($row['type'] === 'row' && isset($row['no'])): ?>
                                        <?php if ($row['no'] === 0): ?>
                                            <div class="seatplan-row">
                                                <div class="stage-element">
                                                    <i class="bi bi-music-note-beamed"></i> BÜHNE
                                                </div>
                                            </div>
                                        <?php else: ?>
                                            <div class="seatplan-row">
                                                <div class="row-label">Reihe <?= $row['no'] ?></div>
                                                <div class="seatplan-elements">
                                                    <?php foreach ($row['content'] as $element): ?>
                                                        <?php
                                                        $seatId = '';
                                                        $isReserved = false;
                                                        $isInCart = false;
                                                        
                                                        if ($element['type'] === 'seat') {
                                                            $seatId = 'seat_' . $row['no'] . '_' . $element['no'];
                                                            $isInCart = in_array($seatId, $cartSeatIds);
                                                            $isReserved = isset($reservedSeats[$seatId]) && !$isInCart;
                                                        } elseif ($element['type'] === 'wheelchair') {
                                                            $seatId = 'wheelchair_' . $row['no'] . '_' . $element['no'];
                                                            $isReserved = isset($reservedSeats[$seatId]);
                                                        } elseif ($element['type'] === 'table') {
                                                            $seatId = 'table_' . $element['no'];
                                                        }
                                                        
                                                        $category = null;
                                                        if (isset($element['category']) && $element['category']) {
                                                            foreach ($categories as $cat) {
                                                                if ($cat['id'] == $element['category']) {
                                                                    $category = $cat;
                                                                    break;
                                                                }
                                                            }
                                                        }
                                                        $bgColor = $category ? ($category['color'] ?: '#0d6efd') : '#0d6efd';
                                                        ?>
                                                        
                                                        <?php if ($element['type'] === 'seat'): ?>
                                                            <span class="seat-element <?= $isReserved ? 'reserved' : ($isInCart ? 'in-cart' : '') ?>"
                                                                  data-seat-id="<?= htmlspecialchars($seatId) ?>"
                                                                  data-row="<?= $row['no'] ?>"
                                                                  data-seat="<?= $element['no'] ?>"
                                                                  data-category="<?= $element['category'] ?? '' ?>"
                                                                  style="border-color: <?= $bgColor ?>; border-width: 2px; background-color: <?= $isReserved ? '#dc3545' : ($isInCart ? '#ffc107' : '#28a745') ?>; color: <?= $isInCart ? '#212529' : 'white' ?>;"
                                                                  onclick="<?= $isReserved ? '' : 'toggleSeat(this)' ?>"
                                                                  title="<?= $isReserved ? 'Reserviert' : ($isInCart ? 'In Ihrem Warenkorb' : 'Verfügbar') ?>">
                                                                <?= $element['no'] ?>
                                                            </span>
                                                        <?php elseif ($element['type'] === 'wheelchair'): ?>
                                                            <span class="seat-element wheelchair-seat <?= $isReserved ? 'reserved' : '' ?>"
                                                                  data-seat-id="<?= htmlspecialchars($seatId) ?>"
                                                                  data-row="<?= $row['no'] ?>"
                                                                  data-seat="<?= $element['no'] ?>"
                                                                  data-category="<?= $element['category'] ?? '' ?>"
                                                                  style="border-color: <?= $bgColor ?>; border-width: 2px; background-color: <?= $isReserved ? '#dc3545' : '#6c757d' ?>; color: white; cursor: not-allowed;"
                                                                  title="<?= $isReserved ? 'Rollstuhlplatz – Reserviert' : 'Rollstuhlplatz – Buchung nur über Vorverkauf' ?>">
                                                                <i class="bi bi-person-wheelchair"></i> <?= $element['no'] ?>
                                                            </span>
                                                        <?php elseif ($element['type'] === 'table'): ?>
                                                            <div class="table-with-seats" style="display: inline-flex; flex-direction: column; align-items: center; gap: 0.25rem;">
                                                                <?php if (!empty($element['seats']['front'])): ?>
                                                                    <div style="display: flex; gap: 0.25rem;">
                                                                        <?php foreach ($element['seats']['front'] as $seat): ?>
                                                                            <?php
                                                                            $tableSeatId = 'tableseat_' . $element['no'] . '_' . $seat['number'];
                                                                            $isInCart = in_array($tableSeatId, $cartSeatIds);
                                                                            $isReserved = isset($reservedSeats[$tableSeatId]) && !$isInCart;
                                                                            $seatCategory = null;
                                                                            if ($seat['category']) {
                                                                                foreach ($categories as $cat) {
                                                                                    if ($cat['id'] == $seat['category']) {
                                                                                        $seatCategory = $cat;
                                                                                        break;
                                                                                    }
                                                                                }
                                                                            }
                                                                            $seatBgColor = $seatCategory ? ($seatCategory['color'] ?: '#0d6efd') : '#0d6efd';
                                                                            ?>
                                                                            <span class="seat-element <?= $isReserved ? 'reserved' : ($isInCart ? 'in-cart' : '') ?>" 
                                                                                  data-seat-id="<?= htmlspecialchars($tableSeatId) ?>"
                                                                                  data-row="<?= $row['no'] ?>"
                                                                                  data-seat="<?= $seat['number'] ?>"
                                                                                  data-table="<?= $element['no'] ?>"
                                                                                  data-category="<?= $seat['category'] ?? '' ?>"
                                                                                  style="border-color: <?= $seatBgColor ?>; border-width: 2px; background-color: <?= $isReserved ? '#dc3545' : ($isInCart ? '#ffc107' : '#28a745') ?>; color: <?= $isInCart ? '#212529' : 'white' ?>;"
                                                                                  onclick="<?= $isReserved ? '' : 'toggleSeat(this)' ?>">
                                                                                <?= $seat['number'] ?>
                                                                            </span>
                                                                        <?php endforeach; ?>
                                                                    </div>
                                                                <?php endif; ?>
                                                                <div style="width: 50px; height: 20px; background-color: #8B4513; border-radius: 4px; margin: 2px 0;"></div>
                                                                <?php if (!empty($element['seats']['back'])): ?>
                                                                    <div style="display: flex; gap: 0.25rem;">
                                                                        <?php foreach ($element['seats']['back'] as $seat): ?>
                                                                            <?php
                                                                            $tableSeatId = 'tableseat_' . $element['no'] . '_' . $seat['number'];
                                                                            $isInCart = in_array($tableSeatId, $cartSeatIds);
                                                                            $isReserved = isset($reservedSeats[$tableSeatId]) && !$isInCart;
                                                                            $seatCategory = null;
                                                                            if ($seat['category']) {
                                                                                foreach ($categories as $cat) {
                                                                                    if ($cat['id'] == $seat['category']) {
                                                                                        $seatCategory = $cat;
                                                                                        break;
                                                                                    }
                                                                                }
                                                                            }
                                                                            $seatBgColor = $seatCategory ? ($seatCategory['color'] ?: '#0d6efd') : '#0d6efd';
                                                                            ?>
                                                                            <span class="seat-element <?= $isReserved ? 'reserved' : ($isInCart ? 'in-cart' : '') ?>" 
                                                                                  data-seat-id="<?= htmlspecialchars($tableSeatId) ?>"
                                                                                  data-row="<?= $row['no'] ?>"
                                                                                  data-seat="<?= $seat['number'] ?>"
                                                                                  data-table="<?= $element['no'] ?>"
                                                                                  data-category="<?= $seat['category'] ?? '' ?>"
                                                                                  style="border-color: <?= $seatBgColor ?>; border-width: 2px; background-color: <?= $isReserved ? '#dc3545' : ($isInCart ? '#ffc107' : '#28a745') ?>; color: <?= $isInCart ? '#212529' : 'white' ?>;"
                                                                                  onclick="<?= $isReserved ? '' : 'toggleSeat(this)' ?>">
                                                                                <?= $seat['number'] ?>
                                                                            </span>
                                                                        <?php endforeach; ?>
                                                                    </div>
                                                                <?php endif; ?>
                                                            </div>
                                                        <?php elseif ($element['type'] === 'distance' || $element['type'] === 'spacer'): ?>
                                                            <span class="distance-element"<?= seatplanStairsAttr($element) ?>>&nbsp;</span>
                                                        <?php elseif ($element['type'] === 'stage'): ?>
                                                            <div class="stage-element" style="background-color: #6c757d; color: white; padding: 5px 10px; border-radius: 4px; margin: 2px;">
                                                                <?= htmlspecialchars($element['label'] ?? 'Bühne') ?>
                                                            </div>
                                                        <?php endif; ?>
                                                    <?php endforeach; ?>
                                                </div>
                                            </div>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                        
                        <div class="legend mt-3">
                            <div class="legend-item">
                                <span class="seat-element" style="background-color: #28a745;"></span>
                                <span>Verfügbar</span>
                            </div>
                            <div class="legend-item">
                                <span class="seat-element" style="background-color: #ffc107; border: 2px solid #856404;"></span>
                                <span>Ausgewählt</span>
                            </div>
                            <div class="legend-item">
                                <span class="seat-element" style="background-color: #dc3545;"></span>
                                <span>Reserviert</span>
                            </div>
                            <?php
                            // Legend entries only for element types that occur in this plan
                            $planTypes = seatplanElementTypes($seatPlan);
                            ?>
                            <?php if (isset($planTypes['wheelchair'])): ?>
                            <div class="legend-item">
                                <span class="seat-element" style="background-color: #6c757d;"><i class="bi bi-person-wheelchair"></i></span>
                                <span>Rollstuhlplatz (nur Vorverkauf)</span>
                            </div>
                            <?php endif; ?>
                            <?php if (isset($planTypes['stairs'])): ?>
                            <div class="legend-item">
                                <span class="seat-element" style="background-color: #fff; border: 2px solid #000;"></span>
                                <span>Treppe</span>
                            </div>
                            <?php endif; ?>
                            <?php foreach ($categories as $cat): ?>
                                <?php if ($cat['color']): ?>
                                    <div class="legend-item">
                                        <span class="seat-element" style="background-color: <?= htmlspecialchars($cat['color']) ?>;"></span>
                                        <span><?= htmlspecialchars($cat['name']) ?> (<?= number_format($cat['price'], 2, ',', '.') ?> €)</span>
                                    </div>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Ausgewählte Plätze (25%) -->
            <div class="col-md-3">
                <div class="card">
                    <div class="card-header d-flex justify-content-between align-items-center py-2">
                        <h6 class="mb-0">Ausgewählt</h6>
                        <div id="session-countdown" class="badge bg-warning text-dark" style="font-size: 0.7rem; display: <?= !empty($cartItems) ? 'inline-block' : 'none' ?>;">
                            <i class="bi bi-clock"></i> <span id="countdown-text">--:--</span>
                        </div>
                    </div>
                    <div class="card-body p-2">
                        <div id="selected-seats" style="max-height: 300px; overflow-y: auto;">
                            <p class="text-muted small text-center">Keine Plätze ausgewählt</p>
                        </div>
                        <hr class="my-2">
                        <div class="d-flex justify-content-between align-items-center">
                            <div id="total-price" class="small"><strong>0,00 €</strong></div>
                            <button class="btn btn-primary btn-sm" id="add-to-cart-btn" onclick="addToCart()" disabled>Zum Warenkorb</button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="<?= url('libs/js/bootstrap.bundle.min.js') ?>"></script>
    <script src="<?= assetUrl('libs/js/seatplan-zoom.js') ?>"></script>
    <script>
        // Feedback-Funktion für UI-Meldungen
        function showFeedback(message, type = 'info') {
            const container = document.getElementById('feedback-container');
            if (!container) return;
            
            const alertClass = {
                'success': 'alert-success',
                'error': 'alert-danger',
                'warning': 'alert-warning',
                'info': 'alert-info'
            }[type] || 'alert-info';
            
            const alertId = 'alert-' + Date.now();
            const alertHtml = 
                "<div id=\"" + alertId + "\" class=\"alert " + alertClass + " alert-dismissible fade show\" role=\"alert\">" +
                message +
                "<button type=\"button\" class=\"btn-close\" data-bs-dismiss=\"alert\" aria-label=\"Close\"></button>" +
                "</div>";
            
            container.insertAdjacentHTML('beforeend', alertHtml);
            
            // Auto-remove after 5 seconds
            setTimeout(() => {
                const alertElement = document.getElementById(alertId);
                if (alertElement) {
                    const bsAlert = new bootstrap.Alert(alertElement);
                    bsAlert.close();
                }
            }, 5000);
        }
    </script>
    <script>
        const categories = <?= json_encode($categories) ?>;
        const eventId = <?= $eventId ?>;
        const eventDate = '<?= $eventDate ?>';
        const customerReserveApi = '<?= url('api/customer_reserve_seat.php') ?>';
        const customerFreeApi = '<?= url('api/customer_free_seat.php') ?>';
        const sessionResetApi = '<?= url('api/session_reset_timeout.php') ?>';
        const reservationTimeoutMinutes = <?= \App\Core\Session::getReservationTimeoutMinutes() ?>;

        // Helper function to get price for a seat
        function getSeatPrice(categoryId) {
            if (!categoryId) return 0;
            const category = categories.find(c => c.id == categoryId);
            return category ? parseFloat(category.price) : 0;
        }

        // Initialize selectedSeats with items already in cart from database
        let selectedSeats = <?= json_encode(array_map(function($item) use ($categories) {
            $categoryId = $item['category_id'] ?? null;
            $price = 0;
            if ($categoryId) {
                foreach ($categories as $cat) {
                    if ($cat['id'] == $categoryId) {
                        $price = (float)$cat['price'];
                        break;
                    }
                }
            }
            return [
                'id' => $item['seat_identifier'],
                'row' => strpos($item['seat_identifier'], 'seat_') === 0 ? (int)explode('_', $item['seat_identifier'])[1] : null,
                'seat' => strpos($item['seat_identifier'], 'seat_') === 0 ? (int)explode('_', $item['seat_identifier'])[2] : null,
                'table' => strpos($item['seat_identifier'], 'table_') === 0 ? (int)explode('_', $item['seat_identifier'])[1] : null,
                'category' => $categoryId,
                'price' => $price
            ];
        }, $cartItems)) ?>;

        // Reservation countdown timer (synced with cart)
        let reservationExpiresAt = <?= \App\Core\Session::getReservationTimeout() ?>;

        function showCountdown() {
            const el = document.getElementById('session-countdown');
            if (el) el.style.display = 'inline-block';
        }

        function hideCountdown() {
            const el = document.getElementById('session-countdown');
            if (el) el.style.display = 'none';
        }

        function updateReservationCountdown() {
            const countdownEl = document.getElementById('session-countdown');
            const countdownText = document.getElementById('countdown-text');
            if (!countdownEl || !countdownText) return;

            // Hide countdown if no seats selected and no timer set
            if (selectedSeats.length === 0 && reservationExpiresAt === 0) {
                hideCountdown();
                return;
            }

            // If timer is not set yet but seats exist, don't show countdown
            if (reservationExpiresAt === 0) {
                hideCountdown();
                return;
            }

            showCountdown();

            const now = Math.floor(Date.now() / 1000);
            const remaining = reservationExpiresAt - now;

            if (remaining <= 0) {
                countdownText.textContent = 'Abgelaufen';
                countdownEl.className = 'badge bg-danger';
                countdownEl.style.display = 'inline-block';
                // Redirect to timeout page
                window.location.href = '<?= url('cart_timeout.php') ?>';
                return;
            }

            const minutes = Math.floor(remaining / 60);
            const seconds = remaining % 60;
            countdownText.textContent = String(minutes).padStart(2, '0') + ':' + String(seconds).padStart(2, '0');

            // Color changes based on remaining time
            countdownEl.className = 'badge';
            countdownEl.style.display = 'inline-block';
            if (remaining < 60) {
                countdownEl.classList.add('bg-danger');
            } else if (remaining < 180) {
                countdownEl.classList.add('bg-warning', 'text-dark');
            } else {
                countdownEl.classList.add('bg-success');
            }
        }

        // Start countdown timer
        updateReservationCountdown();
        setInterval(updateReservationCountdown, 1000);

        function customerReserveSeat(seatId, categoryId) {
            fetch(customerReserveApi, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    event_id: eventId,
                    event_date: eventDate,
                    seat_identifier: seatId,
                    category_id: categoryId
                })
            })
            .then(response => response.json())
            .then(data => {
                if (data.reservation_expires_at) {
                    reservationExpiresAt = data.reservation_expires_at;
                    showCountdown();
                }
            })
            .catch(error => console.log('Reserve error:', error));
        }

        function customerFreeSeat(seatId) {
            fetch(customerFreeApi, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    event_id: eventId,
                    event_date: eventDate,
                    seat_identifier: seatId,
                    reason: "customer_deselected"
                })
            })
            .then(response => response.json())
            .then(data => {
                if (data.reservation_expires_at) {
                    reservationExpiresAt = data.reservation_expires_at;
                    if (reservationExpiresAt === 0) {
                        hideCountdown();
                    } else {
                        showCountdown();
                    }
                }
            })
            .catch(error => console.log('Free error:', error));
        }

        function toggleSeat(element) {
            const seatId = element.dataset.seatId;
            const index = selectedSeats.findIndex(s => s.id === seatId);
            
            if (index > -1) {
                selectedSeats.splice(index, 1);
                element.classList.remove('selected');
                const categoryId = element.dataset.category ? parseInt(element.dataset.category) : null;
                const category = categoryId ? categories.find(c => c.id == categoryId) : null;
                const categoryColor = category ? (category.color || '#0d6efd') : '#0d6efd';
                element.style.borderColor = categoryColor;
                element.style.backgroundColor = '#28a745';
                element.style.color = 'white';
                
                customerFreeSeat(seatId);
            } else {
                const categoryId = element.dataset.category ? parseInt(element.dataset.category) : null;
                const category = categoryId ? categories.find(c => c.id == categoryId) : null;
                selectedSeats.push({
                    id: seatId,
                    row: element.dataset.row,
                    seat: element.dataset.seat,
                    table: element.dataset.table || null,
                    category: categoryId,
                    categoryName: category ? category.name : null,
                    price: category ? parseFloat(category.price) : 0
                });
                element.classList.add('selected');
                element.style.backgroundColor = '#ffc107';
                element.style.color = '#212529';
                
                customerReserveSeat(seatId, categoryId);
            }
            
            updateSelection();
        }

        function updateSelection() {
            const container = document.getElementById('selected-seats');
            const totalPriceEl = document.getElementById('total-price');
            const addBtn = document.getElementById('add-to-cart-btn');
            
            if (selectedSeats.length === 0) {
                container.innerHTML = '<p class="text-muted small">Keine Plätze ausgewählt</p>';
                totalPriceEl.innerHTML = '<strong>Gesamt: 0,00 €</strong>';
                addBtn.disabled = true;
            } else {
                let html = '';
                let total = 0;
                
                selectedSeats.forEach(seat => {
                    total += seat.price;
                    let seatName = '';
                    if (seat.table) {
                        seatName = "Tisch " + seat.table + ", Platz " + seat.seat;
                    } else {
                        seatName = "Reihe " + seat.row + ", Platz " + seat.seat;
                    }
                    html += "<div class=\"d-flex justify-content-between mb-2\">" +
                        "<span>" + seatName + "</span>" +
                        "<span>" + seat.price.toFixed(2) + " €</span>" +
                        "</div>";
                });
                
                container.innerHTML = html;
                totalPriceEl.innerHTML = "<strong>Gesamt: " + total.toFixed(2) + " €</strong>";
                addBtn.disabled = false;
            }
        }

        function addToCart() {
            if (selectedSeats.length === 0) return;

            fetch('<?= url('api/cart_add.php') ?>', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({
                    event_id: eventId,
                    event_date: eventDate,
                    seats: selectedSeats
                })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showFeedback(data.message || 'Plätze wurden zum Warenkorb hinzugefügt!', 'success');
                    setTimeout(() => {
                        window.location.href = '<?= url('cart.php') ?>';
                    }, 1000);
                } else {
                    showFeedback('Fehler: ' + (data.error || 'Unbekannter Fehler'), 'error');
                }
            })
            .catch(error => {
                showFeedback('Fehler beim Hinzufügen zum Warenkorb: ' + error.message, 'error');
            });
        }
        
        // Initialize selected seats display on page load
        document.addEventListener('DOMContentLoaded', function() {
            updateSelection();
        });
    </script>
    <script src="<?= url('libs/js/cart-heartbeat.js') ?>" data-endpoint="<?= url('api/cart_update_timestamp.php') ?>"></script>
</body>
</html>

