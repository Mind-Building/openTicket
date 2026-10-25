<?php
require_once __DIR__ . '/../app/bootstrap.php';

use App\Core\Session;
use App\Core\Config;
use App\Models\Event;
use App\Models\SeatCategory;
use App\Models\Reservation;

Session::start();

$eventId = (int)($_GET['id'] ?? 0);
$eventModel = new Event();
$event = $eventModel->getById($eventId);

// Vorüber gegangene Veranstaltungen (alle Termine in der Vergangenheit) sind gesperrt
// Inactive events only in the admin preview (logged-in admin testing the customer flow)
if (!$event || (!$event['active'] && !isAdminEventPreview($event)) || !empty($event['is_finished'])) {
    header('Location: ' . url(''));
    exit;
}

$allDates = $eventModel->getDates($eventId);
$maxReservationTime = $event['max_reservation_time'] ?? '12:00:00';

// Show all dates – past / no-longer-bookable ones are rendered greyed out so the
// customer still sees that an event takes place on that day (with an explanation
// shown on click). Bookability is re-checked per date in the render loop below.
$dates = $allDates;

$selectedDate = $_GET['date'] ?? null;

// Get time for selected date or default from event
$showTime = $event['show_start_time'] ?? '20:30:00';
if ($selectedDate) {
    $eventDate = $eventModel->getDateByEventAndDate($eventId, $selectedDate);
    if ($eventDate && !empty($eventDate['show_start_time'])) {
        $showTime = $eventDate['show_start_time'];
    }
}

// Redirect to seatplan immediately (before any HTML output) when date + seatplan are set
if ($selectedDate && !empty($event['use_seat_plan'])) {
    header('Location: ' . url('seatplan.php?event_id=' . $eventId . '&date=' . $selectedDate));
    exit;
}

$pageTitle = htmlspecialchars($event['name']);
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $pageTitle ?> - Ticket System</title>
    <link href="<?= url('libs/css/bootstrap.min.css') ?>" rel="stylesheet">
    <link href="<?= url('libs/css/bootstrap-icons.css') ?>" rel="stylesheet">
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
                    <i class="bi bi-cart"></i> Warenkorb
                    <span class="badge bg-light text-dark" id="cart-count">0</span>
                </a>
            </div>
        </div>
    </nav>
    <?php $companyLogo = Config::get('company_logo', ''); if ($companyLogo): ?>
    <div style="position: fixed; top: 70px; right: 50px; z-index: 1000; pointer-events: none;">
        <img src="<?= url($companyLogo) ?>" alt="Logo" style="height: 100px; max-width: 250px; object-fit: contain;">
    </div>
    <?php endif; ?>

    <div class="container mt-5 mb-5">
        <?= adminEventPreviewBanner($event) ?>
        <div class="row mb-4">
            <div class="col-12">
                <a href="<?= url('') ?>" class="btn btn-outline-secondary mb-3">
                    <i class="bi bi-arrow-left"></i> Zurück
                </a>
                <h1><?= $pageTitle ?></h1>
                <p class="text-muted">
                    <i class="bi bi-clock"></i> Vorstellungsbeginn: <?= date('H:i', strtotime($showTime)) ?> Uhr
                </p>
            </div>
        </div>

        <?php if (empty($dates)): ?>
            <div class="alert alert-warning">
                <i class="bi bi-exclamation-triangle"></i> Für diese Veranstaltung sind noch keine Termine verfügbar.
            </div>
        <?php else: ?>
            <div class="row">
                <div class="col-md-4">
                    <div class="card">
                        <div class="card-header">
                            <h5 class="mb-0">Termin wählen</h5>
                        </div>
                        <div class="card-body">
                            <div id="dateInfoBox" class="alert alert-warning d-none" role="alert"></div>
                            <div class="list-group">
                                <?php foreach ($dates as $date): ?>
                                    <?php
                                    // Re-check bookability for display: bookable dates link through,
                                    // past / deadline-passed dates are shown greyed out with an info text.
                                    $bookableCheck = isEventDateBookable($date['date'], $maxReservationTime);
                                    $isBookable    = $bookableCheck['bookable'];
                                    $isPast        = strtotime($date['date']) < strtotime(date('Y-m-d'));
                                    $href = $event['use_seat_plan']
                                        ? url('seatplan.php?event_id=' . $eventId . '&date=' . $date['date'])
                                        : url('event.php?id=' . $eventId . '&date=' . $date['date']);
                                    $dateLabel = date('d.m.Y', strtotime($date['date']));
                                    $infoText  = $isPast
                                        ? 'Diese Veranstaltung ist bereits vorüber.'
                                        : 'Der Bestellvorgang kann für diesen Tag nicht mehr durchgeführt werden, weil die Bestelluhrzeit überschritten wurde.';
                                    ?>
                                    <?php if ($isBookable): ?>
                                        <a href="<?= $href ?>"
                                           class="list-group-item list-group-item-action <?= $selectedDate === $date['date'] ? 'active' : '' ?>">
                                            <i class="bi bi-calendar"></i> <?= $dateLabel ?>
                                        </a>
                                    <?php else: ?>
                                        <a href="#"
                                           class="list-group-item list-group-item-action text-muted date-unavailable"
                                           style="opacity: 0.55; cursor: not-allowed;"
                                           data-info="<?= htmlspecialchars($infoText) ?>"
                                           title="<?= htmlspecialchars($bookableCheck['reason']) ?>">
                                            <i class="bi bi-calendar-x"></i> <?= $dateLabel ?>
                                            <span class="badge bg-secondary ms-2"><?= $isPast ? 'Vorüber' : 'Bestellzeit abgelaufen' ?></span>
                                        </a>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="col-md-8">
                    <?php if ($event['sell_vouchers']): ?>
                        <div class="card mb-3">
                            <div class="card-header bg-info text-white">
                                <h5 class="mb-0"><i class="bi bi-gift"></i> Gutscheine</h5>
                            </div>
                            <div class="card-body">
                                <p class="text-muted">Sie können auch Gutscheine für diese Veranstaltung erwerben.</p>
                                <a href="<?= url('vouchers.php?event_id=' . $eventId) ?>" class="btn btn-info">
                                    <i class="bi bi-gift"></i> Gutscheine kaufen
                                </a>
                            </div>
                        </div>
                    <?php endif; ?>
                    
                    <?php if ($selectedDate): ?>
                        <?php if (!$event['use_seat_plan']): ?>
                            <?php
                            // Veranstaltung ohne Sitzplan - Anzahl-Auswahl
                            $categoryModel = new SeatCategory();
                            $reservationModel = new Reservation();
                            $category = null;
                            if (!empty($event['seat_category_id'])) {
                                $category = $categoryModel->getById($event['seat_category_id']);
                            }
                            $maxSeats = $event['max_seats'] ?? null;
                            
                            // Calculate available tickets
                            $availableTickets = $maxSeats;
                            if ($maxSeats !== null && $selectedDate) {
                                // Get all reservations for this event/date
                                $existingReservations = $reservationModel->getAll([
                                    'event_id' => $eventId,
                                    'event_date' => $selectedDate
                                ]);
                                
                                // Count reserved tickets (only count reservations that are:
                                // - Paid/reserved/box-office-printed (status_id >= 2)
                                // - In cart and less than 15 minutes old (status_id = 1)
                                $reservedCount = 0;
                                foreach ($existingReservations as $reservation) {
                                    // Count tickets that are definitely reserved
                                    if ($reservation['status_id'] >= 2) {
                                        $reservedCount++;
                                    } elseif ($reservation['status_id'] == 1) {
                                        // Check if reservation is less than 15 minutes old
                                        $createdAt = strtotime($reservation['created_at']);
                                        $now = time();
                                        if (($now - $createdAt) < 900) { // 15 minutes = 900 seconds
                                            $reservedCount++;
                                        }
                                    }
                                }
                                
                                $availableTickets = max(0, $maxSeats - $reservedCount);
                            }
                            ?>
                            <div class="card">
                                <div class="card-header">
                                    <h5 class="mb-0">Tickets buchen</h5>
                                </div>
                                <div class="card-body">
                                    <?php if ($maxSeats === null): ?>
                                        <div class="alert alert-warning">
                                            <i class="bi bi-exclamation-triangle"></i> Für diese Veranstaltung ist noch keine maximale Sitzanzahl konfiguriert. Bitte kontaktieren Sie uns für weitere Informationen.
                                        </div>
                                    <?php elseif ($availableTickets <= 0): ?>
                                        <div class="alert alert-warning mb-0">
                                            <i class="bi bi-x-circle"></i> Diese Veranstaltung ist leider ausverkauft.
                                        </div>
                                    <?php else: ?>
                                        <?php if ($category): ?>
                                            <div class="alert alert-info mb-3">
                                                <strong><?= htmlspecialchars($category['name']) ?></strong> - 
                                                <?= number_format($category['price'], 2, ',', '.') ?> € pro Ticket
                                                <?php if ($category['reduced_price']): ?>
                                                    <br><small>Ermäßigt: <?= number_format($category['reduced_price'], 2, ',', '.') ?> €</small>
                                                <?php endif; ?>
                                            </div>
                                        <?php endif; ?>
                                        
                                        <form id="ticket-quantity-form" method="POST" action="<?= url('api/cart_add_no_seatplan.php') ?>">
                                            <input type="hidden" name="event_id" value="<?= $eventId ?>">
                                            <input type="hidden" name="event_date" value="<?= htmlspecialchars($selectedDate) ?>">
                                            
                                            <div class="mb-3">
                                                <label for="quantity" class="form-label">Anzahl der Tickets</label>
                                                <input type="number" class="form-control" id="quantity" name="quantity" 
                                                       min="1" max="<?= $availableTickets ?>" 
                                                       value="1" required>
                                                <small class="form-text text-muted">
                                                    <?php if ($availableTickets < $maxSeats): ?>
                                                        <span class="text-warning">Verfügbar: <?= $availableTickets ?> von <?= $maxSeats ?> Tickets</span>
                                                    <?php else: ?>
                                                        Verfügbar: <?= $availableTickets ?> Tickets
                                                    <?php endif; ?>
                                                </small>
                                            </div>
                                            
                                            <?php if (Config::get('reduced_prices_active', false) && ($event['allow_reduced_prices'] ?? false) && $category && !empty($category['reduced_price']) && $category['reduced_price'] > 0): ?>
                                            <div class="mb-3">
                                                <div class="form-check">
                                                    <input class="form-check-input" type="checkbox" id="is_reduced" name="is_reduced" value="1">
                                                    <label class="form-check-label" for="is_reduced">
                                                        Ermäßigt
                                                    </label>
                                                </div>
                                            </div>
                                            <?php endif; ?>
                                            
                                            <div class="d-grid">
                                                <button type="submit" class="btn btn-primary">
                                                    <i class="bi bi-cart-plus"></i> In den Warenkorb
                                                </button>
                                            </div>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endif; ?>
                    <?php else: ?>
                        <div class="alert alert-info">
                            <i class="bi bi-info-circle"></i> Bitte wählen Sie zuerst einen Termin aus.
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <script src="<?= url('libs/js/bootstrap.bundle.min.js') ?>"></script>
    <script>
        // Show info text when a greyed-out (non-bookable) date is clicked
        document.querySelectorAll('.date-unavailable').forEach(function (el) {
            el.addEventListener('click', function (e) {
                e.preventDefault();
                const box = document.getElementById('dateInfoBox');
                if (box) {
                    box.textContent = el.getAttribute('data-info');
                    box.classList.remove('d-none');
                }
            });
        });

        // Update cart count
        const cartCountUrl = '<?= url('api/cart_count.php') ?>';
        fetch(cartCountUrl)
            .then(response => {
                // Check content type
                const contentType = response.headers.get('content-type');
                if (!contentType || !contentType.includes('application/json')) {
                    throw new Error('Expected JSON but got: ' + contentType);
                }
                if (!response.ok) {
                    throw new Error('Network response was not ok: ' + response.status);
                }
                return response.text().then(text => {
                    try {
                        return JSON.parse(text);
                    } catch (e) {
                        console.error('Failed to parse JSON:', text);
                        throw e;
                    }
                });
            })
            .then(data => {
                if (data && typeof data.count !== 'undefined') {
                    document.getElementById('cart-count').textContent = data.count;
                }
            })
            .catch(error => {
                console.error('Error loading cart count from', cartCountUrl, ':', error);
                // Silently fail - cart count is not critical
            });
    </script>
</body>
</html>

