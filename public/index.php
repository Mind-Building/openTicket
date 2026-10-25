<?php
require_once __DIR__ . '/../app/bootstrap.php';

use App\Core\Config;
use App\Core\Session;
use App\Models\Event;

Session::start();

$pageTitle = Config::get('company_name', 'Ticket System');
$eventModel = new Event();
// Nur aktive Veranstaltungen, die noch nicht vorüber sind
$events = $eventModel->getAll(true, true);
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pageTitle) ?></title>
    <link href="<?= url('libs/css/bootstrap.min.css') ?>" rel="stylesheet">
    <link href="<?= url('libs/css/bootstrap-icons.css') ?>" rel="stylesheet">
    <style>
        .event-card {
            transition: transform 0.2s;
            cursor: pointer;
        }
        .event-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 4px 8px rgba(0,0,0,0.1);
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
                <i class="bi bi-ticket-perforated"></i> <?= htmlspecialchars($pageTitle) ?>
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
        <div class="row mb-4">
            <div class="col-12 text-center">
                <h1>Veranstaltungen</h1>
                <?php if (!empty($events)): ?>
                <p class="lead text-muted">Wählen Sie eine Veranstaltung aus, um Tickets zu buchen</p>
                <?php endif; ?>
            </div>
        </div>

        <?php if (empty($events)): ?>
            <div class="row">
                <div class="col-md-8 mx-auto">
                    <div class="alert alert-info text-center">
                        <i class="bi bi-info-circle"></i> Derzeit sind keine aktiven Veranstaltungen verfügbar.
                    </div>
                </div>
            </div>
        <?php else: ?>
            <div class="row g-4">
                <?php foreach ($events as $event): ?>
                    <div class="col-md-6 col-lg-4">
                        <div class="card event-card h-100" onclick="window.location.href='<?= url('event.php?id=' . $event['id']) ?>'">
                            <div class="card-body">
                                <h5 class="card-title">
                                    <i class="bi bi-calendar-event"></i> <?= htmlspecialchars($event['name']) ?>
                                </h5>
                                <?php if ($event['sell_tickets']): ?>
                                    <span class="badge bg-success mb-2">Tickets verfügbar</span>
                                <?php endif; ?>
                                <?php if ($event['sell_vouchers']): ?>
                                    <span class="badge bg-info mb-2">Gutscheine verfügbar</span>
                                <?php endif; ?>
                                <p class="card-text text-muted small mt-2">
                                    <i class="bi bi-clock"></i> Vorstellungsbeginn: <?= date('H:i', strtotime($event['show_start_time'])) ?> Uhr
                                </p>
                            </div>
                            <div class="card-footer bg-transparent d-flex flex-column gap-2">
                                <?php if ($event['sell_tickets']): ?>
                                <a href="<?= url('event.php?id=' . $event['id']) ?>" class="btn btn-primary w-100">
                                    <i class="bi bi-ticket-perforated"></i> Tickets buchen
                                </a>
                                <?php endif; ?>
                                <?php if ($event['sell_vouchers']): ?>
                                <a href="<?= url('vouchers.php?event_id=' . $event['id']) ?>" class="btn btn-outline-info w-100">
                                    <i class="bi bi-gift"></i> Gutscheine kaufen
                                </a>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <script src="<?= url('libs/js/bootstrap.bundle.min.js') ?>"></script>
    <script>
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

