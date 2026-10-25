<?php
require_once __DIR__ . '/../app/bootstrap.php';

use App\Core\Session;
use App\Models\Event;
use App\Models\SeatCategory;

Session::start();

$eventId = (int)($_GET['event_id'] ?? 0);
$eventModel = new Event();
$event = $eventModel->getById($eventId);

// Vorüber gegangene Veranstaltungen sind auch für den Gutscheinkauf gesperrt
if (!$event || !$event['active'] || !$event['sell_vouchers'] || !empty($event['is_finished'])) {
    header('Location: ' . url(''));
    exit;
}

$categoryModel = new SeatCategory();
$categories = $categoryModel->getByEvent($eventId);

$pageTitle = 'Gutscheine kaufen - ' . htmlspecialchars($event['name']);
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $pageTitle ?></title>
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

    <div class="container mt-5 mb-5">
        <div id="feedback-container" style="position: fixed; top: 70px; right: 20px; z-index: 9999; max-width: 400px;"></div>
        <div class="row mb-4">
            <div class="col-12">
                <a href="<?= url('event.php?id=' . $eventId) ?>" class="btn btn-outline-secondary mb-3">
                    <i class="bi bi-arrow-left"></i> Zurück
                </a>
                <h1><i class="bi bi-gift"></i> Gutscheine kaufen</h1>
                <p class="text-muted"><?= htmlspecialchars($event['name']) ?></p>
            </div>
        </div>

        <?php if (empty($categories)): ?>
            <div class="alert alert-warning">
                <i class="bi bi-exclamation-triangle"></i> Für diese Veranstaltung sind noch keine Kategorien verfügbar.
            </div>
        <?php else: ?>
            <div class="row g-4">
                <?php foreach ($categories as $category): ?>
                    <div class="col-md-6 col-lg-4">
                        <div class="card h-100">
                            <div class="card-header" style="background-color: <?= htmlspecialchars($category['color'] ?? '#0d6efd') ?>; color: white;">
                                <h5 class="mb-0">
                                    <?php if (!empty($category['sign'])): ?>
                                        <span class="badge bg-light text-dark me-2"><?= htmlspecialchars($category['sign']) ?></span>
                                    <?php endif; ?>
                                    <?= htmlspecialchars($category['name']) ?>
                                </h5>
                            </div>
                            <div class="card-body">
                                <p class="card-text">
                                    <?php if (!empty($category['description'])): ?>
                                        <?= htmlspecialchars($category['description']) ?>
                                    <?php else: ?>
                                        Gutschein für <?= htmlspecialchars($category['name']) ?>
                                    <?php endif; ?>
                                </p>
                                <?php if (!empty($category['price'])): ?>
                                    <p class="h4 text-primary mb-3">
                                        <?= number_format((float)$category['price'], 2, ',', '.') ?> €
                                    </p>
                                <?php endif; ?>
                            </div>
                            <div class="card-footer bg-transparent">
                                <form method="POST" action="<?= url('api/voucher_add.php') ?>" class="voucher-form">
                                    <input type="hidden" name="event_id" value="<?= $eventId ?>">
                                    <input type="hidden" name="category_id" value="<?= $category['id'] ?>">
                                    <div class="mb-2">
                                        <label for="quantity_<?= $category['id'] ?>" class="form-label">Anzahl:</label>
                                        <input type="number" class="form-control" id="quantity_<?= $category['id'] ?>"
                                               name="quantity" value="1" min="1" max="10" required
                                               onchange="toggleVoucherMode(<?= $category['id'] ?>)">
                                    </div>
                                    <div class="mb-2 voucher-mode-choice" id="voucher-mode-<?= $category['id'] ?>" style="display:none;">
                                        <label class="form-label small text-muted">Gutschein-Art:</label>
                                        <div class="form-check">
                                            <input class="form-check-input" type="radio" name="voucher_mode" id="mode_single_<?= $category['id'] ?>" value="single" checked>
                                            <label class="form-check-label small" for="mode_single_<?= $category['id'] ?>">
                                                <i class="bi bi-upc"></i> Ein Code für alle Tickets
                                            </label>
                                        </div>
                                        <div class="form-check">
                                            <input class="form-check-input" type="radio" name="voucher_mode" id="mode_separate_<?= $category['id'] ?>" value="separate">
                                            <label class="form-check-label small" for="mode_separate_<?= $category['id'] ?>">
                                                <i class="bi bi-upc-scan"></i> Jeweils ein eigener Code pro Ticket
                                            </label>
                                        </div>
                                    </div>
                                    <button type="submit" class="btn btn-primary w-100">
                                        <i class="bi bi-cart-plus"></i> In den Warenkorb
                                    </button>
                                </form>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <script src="<?= url('libs/js/bootstrap.bundle.min.js') ?>"></script>
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
            const alertHtml = `
                <div id="${alertId}" class="alert ${alertClass} alert-dismissible fade show" role="alert">
                    ${message}
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            `;
            
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
        function toggleVoucherMode(categoryId) {
            const qty = parseInt(document.getElementById('quantity_' + categoryId).value) || 1;
            const el = document.getElementById('voucher-mode-' + categoryId);
            if (el) el.style.display = qty > 1 ? '' : 'none';
        }

        // Handle form submission
        document.querySelectorAll('.voucher-form').forEach(form => {
            form.addEventListener('submit', function(e) {
                e.preventDefault();
                
                const formData = new FormData(this);
                const button = this.querySelector('button[type="submit"]');
                const originalText = button.innerHTML;
                button.disabled = true;
                button.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Wird hinzugefügt...';
                
                fetch(this.action, {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        // Update cart count
                        updateCartCount();
                        // Show success message with cart link
                        showFeedback('Gutschein wurde zum Warenkorb hinzugefügt! <a href="<?= url('cart.php') ?>" class="alert-link ms-2"><i class="bi bi-cart"></i> Zum Warenkorb</a>', 'success');
                    } else {
                        showFeedback('Fehler: ' + (data.error || 'Unbekannter Fehler'), 'error');
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    showFeedback('Fehler beim Hinzufügen zum Warenkorb.', 'error');
                })
                .finally(() => {
                    button.disabled = false;
                    button.innerHTML = originalText;
                });
            });
        });

        // Update cart count
        function updateCartCount() {
            const cartCountUrl = '<?= url('api/cart_count.php') ?>';
            fetch(cartCountUrl)
                .then(response => response.json())
                .then(data => {
                    if (data && typeof data.count !== 'undefined') {
                        document.getElementById('cart-count').textContent = data.count;
                    }
                })
                .catch(error => {
                    console.error('Error loading cart count:', error);
                });
        }

        // Initial cart count
        updateCartCount();
    </script>
</body>
</html>

