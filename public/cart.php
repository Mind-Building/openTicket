<?php
require_once __DIR__ . '/../app/bootstrap.php';

use App\Core\Session;
use App\Core\Config;
use App\Models\Reservation;
use App\Models\Voucher;
use App\Models\Event;
use App\Models\SeatCategory;
use App\Models\SeatPlan;

// Prevent caching
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

Session::start();

$sessionId = session_id();
$reservationModel = new Reservation();
$voucherModel = new Voucher();
$eventModel = new Event();
$categoryModel = new SeatCategory();
$seatPlanModel = new SeatPlan();

$cartItems = $reservationModel->getBySession($sessionId);
// Get vouchers to BUY (purchase_intent = 'buy') - these are items to purchase
$cartVouchersToBuy = $voucherModel->getBySession($sessionId, 'buy');
// Get vouchers to REDEEM (purchase_intent = 'redeem') - these are discounts
$cartVouchersToRedeem = $voucherModel->getBySession($sessionId, 'redeem');

// Use session-based reservation timer (synced with seatplan)
$reservationExpiresAt = Session::getReservationTimeout();
// If there are cart items but no timer set, initialize it
if ((!empty($cartItems) || !empty($cartVouchersToBuy)) && $reservationExpiresAt === 0) {
    $reservationExpiresAt = Session::resetReservationTimeout();
}
$expiresAt = $reservationExpiresAt;

// Group by event and date
$groupedItems = [];
$totalPrice = 0;

foreach ($cartItems as $item) {
    $key = $item['event_id'] . '_' . $item['event_date'];
    if (!isset($groupedItems[$key])) {
        $event = $eventModel->getById($item['event_id']);
        $groupedItems[$key] = [
            'event' => $event,
            'date' => $item['event_date'],
            'items' => []
        ];
    }
    
    $category = null;
    if ($item['category_id']) {
        $category = $categoryModel->getById($item['category_id']);
    }
    
    // Same rule as the PayPal charge (App\Helpers\TicketPrice)
    $priceInfo = \App\Helpers\TicketPrice::forReservation($item);
    $price = $priceInfo['price'];

    // Price types the customer can already pick here (otherwise at checkout)
    $choices = \App\Helpers\TicketPrice::choices($item, (int)$item['event_id'], (string)$item['event_date']);
    $selectedType = !empty($item['is_fn_card']) ? \App\Helpers\TicketPrice::TYPE_FN_CARD
        : (!empty($item['is_reduced']) ? \App\Helpers\TicketPrice::TYPE_REDUCED : $priceInfo['type']);
    if (!isset($choices[$selectedType])) {
        $selectedType = array_key_first($choices);
    }

    $groupedItems[$key]['items'][] = [
        'reservation' => $item,
        'category' => $category,
        'price' => $price,
        'price_type' => \App\Helpers\TicketPrice::typeLabel($priceInfo['type']),
        'choices' => $choices,
        'selected_type' => $selectedType,
    ];
    
    $totalPrice += $price;
}

// Add vouchers to BUY to total price (they are items to purchase)
foreach ($cartVouchersToBuy as $voucher) {
    $price = (float)($voucher['category_price'] ?? 0);
    $quantity = (int)($voucher['quantity'] ?? 1);
    $totalPrice += ($price * $quantity);
}

// Calculate voucher discount from REDEEMED vouchers (subtract from total)
$voucherDiscount = 0;
foreach ($cartVouchersToRedeem as $voucher) {
    $price = (float)($voucher['category_price'] ?? 0);
    $quantity = (int)($voucher['quantity'] ?? 1);
    $voucherDiscount += ($price * $quantity);
}
// Don't modify $totalPrice here - we'll calculate the final price in the display
// This allows us to show both the original total and the discount separately

$voucherActive = (bool)Config::get('voucher_active', false);

$pageTitle = 'Warenkorb';
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
            </div>
        </div>
    </nav>
    <?php $companyLogo = Config::get('company_logo', ''); if ($companyLogo): ?>
    <div style="position: fixed; top: 70px; right: 50px; z-index: 1000; pointer-events: none;">
        <img src="<?= url($companyLogo) ?>" alt="Logo" style="height: 100px; max-width: 250px; object-fit: contain;">
    </div>
    <?php endif; ?>

    <div class="container mt-5 mb-5">
        <div id="feedback-container" style="position: fixed; top: 70px; right: 20px; z-index: 9999; max-width: 400px;"></div>
        <div class="row mb-4">
            <div class="col-12">
                <h1>Warenkorb</h1>
            </div>
        </div>

        <?php if (empty($cartItems) && empty($cartVouchersToBuy) && empty($cartVouchersToRedeem)): ?>
            <div class="alert alert-info">
                <i class="bi bi-info-circle"></i> Ihr Warenkorb ist leer.
                <a href="<?= url('') ?>" class="alert-link">Zur Veranstaltungsübersicht</a>
            </div>
        <?php else: ?>
            <!-- Gutscheincode einlösen -->
            <?php if ($voucherActive): ?>
            <div class="card mb-4">
                <div class="card-header bg-success text-white">
                    <h5 class="mb-0"><i class="bi bi-ticket-perforated"></i> Gutscheincode einlösen</h5>
                </div>
                <div class="card-body">
                    <form id="voucher-redeem-form" class="row g-2">
                        <div class="col-md-8">
                            <input type="text"
                                   class="form-control"
                                   id="voucher-code-input"
                                   placeholder="Gutscheincode eingeben (z.B. ABC123DEF456)"
                                   required
                                   style="text-transform: uppercase;">
                        </div>
                        <div class="col-md-4">
                            <button type="submit" class="btn btn-success w-100">
                                <i class="bi bi-check-circle"></i> Einlösen
                            </button>
                        </div>
                    </form>
                    <div id="voucher-redeem-message" class="mt-2"></div>
                </div>
            </div>
            <?php endif; ?>

            <div class="row">
                <div class="col-md-8">
                    <?php foreach ($groupedItems as $group): ?>
                        <div class="card mb-4">
                            <div class="card-header">
                                <h5 class="mb-0">
                                    <?= htmlspecialchars($group['event']['name']) ?>
                                    <small class="text-muted">- <?= date('d.m.Y', strtotime($group['date'])) ?></small>
                                </h5>
                            </div>
                            <div class="card-body">
                                <table class="table">
                                    <thead>
                                        <tr>
                                            <th>Platz</th>
                                            <th>Kategorie</th>
                                            <th>Preis</th>
                                            <th>Aktionen</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($group['items'] as $item): ?>
                                            <tr>
                                                <td><?= htmlspecialchars($seatPlanModel->getSeatDisplayName($item['reservation']['event_id'], $item['reservation']['seat_identifier'])) ?></td>
                                                <td>
                                                    <?php if ($item['category']): ?>
                                                        <?= htmlspecialchars($item['category']['name']) ?>
                                                        <?php if ($item['price_type'] !== ''): ?>
                                                            <span class="badge bg-info"><?= htmlspecialchars($item['price_type']) ?><?= $item['price_type'] === 'FN-Card' && !empty($item['reservation']['fn_card_number']) ? ' ' . htmlspecialchars($item['reservation']['fn_card_number']) : '' ?></span>
                                                        <?php endif; ?>
                                                    <?php else: ?>
                                                        <span class="text-muted">-</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <div><?= number_format($item['price'], 2, ',', '.') ?> €</div>
                                                    <?php if (count($item['choices']) > 1): $resId = (int)$item['reservation']['id']; ?>
                                                        <select class="form-select form-select-sm mt-1" style="max-width: 230px;" aria-label="Preisart"
                                                                onchange="setCartPriceType(<?= $resId ?>, this.value)">
                                                            <?php foreach ($item['choices'] as $type => $choicePrice): ?>
                                                                <option value="<?= $type ?>" <?= $type === $item['selected_type'] ? 'selected' : '' ?>>
                                                                    <?= htmlspecialchars(\App\Helpers\TicketPrice::typeLabel($type) ?: 'Normal') ?> – <?= number_format($choicePrice, 2, ',', '.') ?> €
                                                                </option>
                                                            <?php endforeach; ?>
                                                        </select>
                                                        <?php if (isset($item['choices'][\App\Helpers\TicketPrice::TYPE_FN_CARD])): ?>
                                                            <div id="fnbox_<?= $resId ?>" class="mt-1" style="display: <?= $item['selected_type'] === \App\Helpers\TicketPrice::TYPE_FN_CARD ? 'block' : 'none' ?>;">
                                                                <div class="input-group input-group-sm" style="max-width: 260px;">
                                                                    <input type="text" class="form-control" id="fncart_<?= $resId ?>" placeholder="FN-Card-Nummer (8 Ziffern)"
                                                                           maxlength="8" inputmode="numeric" value="<?= htmlspecialchars((string)($item['reservation']['fn_card_number'] ?? '')) ?>">
                                                                    <button class="btn btn-outline-primary" type="button" onclick="checkCartFnCard(<?= $resId ?>)">Prüfen</button>
                                                                </div>
                                                                <div class="small mt-1" id="fnmsg_<?= $resId ?>"></div>
                                                            </div>
                                                        <?php endif; ?>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <button class="btn btn-sm btn-outline-danger" 
                                                            onclick="removeFromCart(<?= $item['reservation']['id'] ?>)">
                                                        <i class="bi bi-trash"></i>
                                                    </button>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    <?php endforeach; ?>
                    
                    <?php if (!empty($cartVouchersToBuy)): ?>
                        <div class="card mb-4 border-primary">
                            <div class="card-header bg-primary text-white">
                                <h5 class="mb-0"><i class="bi bi-gift"></i> Gutscheine (zu kaufen)</h5>
                            </div>
                            <div class="card-body">
                                <table class="table">
                                    <thead>
                                        <tr>
                                            <th>Gutscheincode</th>
                                            <th>Kategorie</th>
                                            <th>Preis</th>
                                            <th>Aktionen</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($cartVouchersToBuy as $voucher): ?>
                                            <tr>
                                                <td>
                                                    <?php if ((int)($voucher['status_id'] ?? 1) === 1): ?>
                                                        <span class="text-muted">Wird nach Bezahlung angezeigt</span>
                                                    <?php else: ?>
                                                        <strong><?= htmlspecialchars($voucher['voucher_code']) ?></strong>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <?= htmlspecialchars($voucher['category_name'] ?? 'N/A') ?>
                                                    <small class="text-muted d-block"><?= htmlspecialchars($voucher['event_name'] ?? '') ?></small>
                                                </td>
                                                <td>
                                                    <?php
                                                        $qty = (int)($voucher['quantity'] ?? 1);
                                                        $unitPrice = (float)($voucher['category_price'] ?? 0);
                                                    ?>
                                                    <strong><?= number_format($unitPrice * $qty, 2, ',', '.') ?> €</strong>
                                                    <?php if ($qty > 1): ?>
                                                        <small class="text-muted d-block"><?= $qty ?> × <?= number_format($unitPrice, 2, ',', '.') ?> €</small>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <button class="btn btn-sm btn-outline-danger"
                                                            onclick="removeVoucherFromCart(<?= $voucher['id'] ?>)">
                                                        <i class="bi bi-trash"></i>
                                                    </button>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    <?php endif; ?>
                    
                    <?php if (!empty($cartVouchersToRedeem)): ?>
                        <div class="card mb-4 border-success">
                            <div class="card-header bg-success text-white">
                                <h5 class="mb-0"><i class="bi bi-percent"></i> Eingelöste Gutscheine (Rabatt)</h5>
                            </div>
                            <div class="card-body">
                                <div class="alert alert-info mb-3">
                                    <i class="bi bi-info-circle"></i> Der Gutscheinwert wird vom Gesamtpreis abgezogen.
                                </div>
                                <table class="table">
                                    <thead>
                                        <tr>
                                            <th>Gutscheincode</th>
                                            <th>Kategorie</th>
                                            <th>Rabatt</th>
                                            <th>Aktionen</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($cartVouchersToRedeem as $voucher): ?>
                                            <tr>
                                                <td>
                                                    <?php if ((int)($voucher['status_id'] ?? 1) === 1): ?>
                                                        <span class="text-muted">Wird nach Bezahlung angezeigt</span>
                                                    <?php else: ?>
                                                        <strong><?= htmlspecialchars($voucher['voucher_code']) ?></strong>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <?= htmlspecialchars($voucher['category_name'] ?? 'N/A') ?>
                                                    <small class="text-muted d-block"><?= htmlspecialchars($voucher['event_name'] ?? '') ?></small>
                                                </td>
                                                <td class="text-success">
                                                    <?php
                                                        $qty = (int)($voucher['quantity'] ?? 1);
                                                        $unitPrice = (float)($voucher['category_price'] ?? 0);
                                                    ?>
                                                    <strong>-<?= number_format($unitPrice * $qty, 2, ',', '.') ?> €</strong>
                                                    <?php if ($qty > 1): ?>
                                                        <small class="text-muted d-block"><?= $qty ?> × <?= number_format($unitPrice, 2, ',', '.') ?> €</small>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <button class="btn btn-sm btn-outline-danger" 
                                                            onclick="removeVoucherFromCart(<?= $voucher['id'] ?>)">
                                                        <i class="bi bi-trash"></i>
                                                    </button>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="col-md-4">
                    <?php 
                    // Check if there are any cart items with status_id = 1 OR vouchers
                    $hasCartItems = false;
                    foreach ($cartItems as $item) {
                        if (isset($item['status_id']) && $item['status_id'] == 1) {
                            $hasCartItems = true;
                            break;
                        }
                    }
                    // Also show countdown if there are vouchers in cart
                    $hasCartItems = $hasCartItems || !empty($cartVouchersToBuy) || !empty($cartVouchersToRedeem);
                    
                    if ($hasCartItems): 
                    ?>
                    <div class="card mb-3 border-warning">
                        <div class="card-header bg-warning text-dark">
                            <h5 class="mb-0">
                                <i class="bi bi-clock"></i> Reservierungszeit
                            </h5>
                        </div>
                        <div class="card-body">
                            <div class="text-center">
                                <div id="countdown-timer" class="fs-2 fw-bold mb-2" data-expires="<?= $expiresAt ?>">
                                    <i class="bi bi-hourglass-split"></i> <span id="countdown-text">Berechne...</span>
                                </div>
                                <p class="text-muted small mb-0">
                                    <i class="bi bi-info-circle"></i> Ihre Plätze werden nach Ablauf der Zeit automatisch freigegeben.
                                </p>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>
                    
                    <?php 
                    // Show summary if there are items OR vouchers
                    if (!empty($cartItems) || !empty($cartVouchersToBuy) || !empty($cartVouchersToRedeem)): 
                    ?>
                    <div class="card">
                        <div class="card-header">
                            <h5 class="mb-0">Zusammenfassung</h5>
                        </div>
                        <div class="card-body">
                            <?php if (!empty($cartItems)): ?>
                            <div class="d-flex justify-content-between mb-3">
                                <span>Anzahl Tickets:</span>
                                <strong><?= count($cartItems) ?></strong>
                            </div>
                            <?php endif; ?>
                            <?php if (!empty($cartVouchersToBuy)): ?>
                            <div class="d-flex justify-content-between mb-3">
                                <span>Anzahl Gutscheine (zu kaufen):</span>
                                <strong><?= count($cartVouchersToBuy) ?></strong>
                            </div>
                            <?php endif; ?>
                            <?php if (!empty($cartVouchersToRedeem)): ?>
                            <div class="d-flex justify-content-between mb-3 text-success">
                                <span>Gutscheinrabatt:</span>
                                <strong>-<?= number_format($voucherDiscount, 2, ',', '.') ?> €</strong>
                            </div>
                            <?php endif; ?>
                            <?php if (!empty($cartItems) || !empty($cartVouchersToBuy)): ?>
                            <div class="d-flex justify-content-between mb-2">
                                <span>Zwischensumme:</span>
                                <strong><?= number_format($totalPrice, 2, ',', '.') ?> €</strong>
                            </div>
                            <?php endif; ?>
                            <hr>
                            <?php if ($voucherDiscount > $totalPrice && !empty($cartVouchersToRedeem)): ?>
                            <div class="alert alert-warning py-2 mb-2">
                                <i class="bi bi-exclamation-triangle"></i>
                                Der Gutscheinwert (<?= number_format($voucherDiscount, 2, ',', '.') ?> €) übersteigt den Kaufbetrag (<?= number_format($totalPrice, 2, ',', '.') ?> €).
                                Der überschüssige Betrag von <strong><?= number_format($voucherDiscount - $totalPrice, 2, ',', '.') ?> €</strong> verfällt.
                            </div>
                            <?php endif; ?>
                            <div class="d-flex justify-content-between">
                                <span><strong>Gesamtpreis:</strong></span>
                                <strong><?= number_format(max(0, $totalPrice - $voucherDiscount), 2, ',', '.') ?> €</strong>
                            </div>
                            <div class="d-grid gap-2 mt-3">
                                <a href="<?= url('checkout.php') ?>" class="btn btn-primary">
                                    <i class="bi bi-credit-card"></i> Zur Kasse
                                </a>
                            <a href="<?= url('') ?>" class="btn btn-outline-secondary w-100 mt-2">
                                <i class="bi bi-arrow-left"></i> Weiter einkaufen
                            </a>
                            <button onclick="clearCart()" class="btn btn-outline-danger w-100 mt-2">
                                <i class="bi bi-trash"></i> Warenkorb leeren
                            </button>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
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
        // Countdown timer for cart reservations
        <?php if (!empty($cartItems) || !empty($cartVouchersToBuy) || !empty($cartVouchersToRedeem)): ?>
        function updateCountdown() {
            const countdownText = document.getElementById('countdown-text');
            const countdownTimer = document.getElementById('countdown-timer');

            // Check if elements exist
            if (!countdownText || !countdownTimer) {
                return; // Elements don't exist, exit silently
            }

            // Read on every tick: the keep-alive heartbeat writes a refreshed
            // expiry back into the dataset, which a value captured once would miss.
            const expiresAt = parseInt(countdownTimer.dataset.expires);
            if (!expiresAt) {
                return;
            }

            const now = Math.floor(Date.now() / 1000);
            const remaining = expiresAt - now;
            
            if (remaining <= 0) {
                countdownText.textContent = 'Abgelaufen';
                countdownTimer.classList.add('text-danger');
                // Clear cart and redirect to timeout page
                fetch('<?= url('api/cart_clear_expired.php') ?>', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    }
                })
                .then(() => {
                    // Redirect to timeout page
                    window.location.href = '<?= url('cart_timeout.php') ?>';
                })
                .catch(error => {
                    console.error('Error clearing cart:', error);
                    window.location.href = '<?= url('cart_timeout.php') ?>';
                });
                return;
            }
            
            const minutes = Math.floor(remaining / 60);
            const seconds = remaining % 60;
            const minutesStr = String(minutes).padStart(2, '0');
            const secondsStr = String(seconds).padStart(2, '0');
            
            countdownText.textContent = `${minutesStr}:${secondsStr}`;
            
            // Change color based on remaining time
            countdownTimer.classList.remove('text-success', 'text-warning', 'text-danger');
            
            // Red if less than 3 minutes
            if (remaining < 180) {
                countdownTimer.classList.add('text-danger');
            // Yellow if less than 5 minutes
            } else if (remaining < 300) {
                countdownTimer.classList.add('text-warning');
            // Green otherwise
            } else {
                countdownTimer.classList.add('text-success');
            }
        }
        
        // Update countdown every second (only if elements exist)
        if (document.getElementById('countdown-timer')) {
            updateCountdown();
            setInterval(updateCountdown, 1000);
        }
        <?php endif; ?>
        
        // Price type per seat (normal / reduced / FN card) - stored right away, checkout takes it over
        function setCartPriceType(reservationId, type) {
            const fnBox = document.getElementById('fnbox_' + reservationId);
            if (type === 'fn_card') {
                // FN card is only stored after the card number check
                if (fnBox) {
                    fnBox.style.display = 'block';
                    document.getElementById('fncart_' + reservationId).focus();
                }
                return;
            }
            if (fnBox) fnBox.style.display = 'none';
            fetch('<?= url('api/cart_price_type.php') ?>', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ reservation_id: reservationId, type: type === 'reduced' ? 'reduced' : 'normal' })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    location.reload();
                } else {
                    showFeedback(data.error || 'Die Preisart konnte nicht gespeichert werden.', 'danger');
                }
            })
            .catch(() => showFeedback('Die Preisart konnte nicht gespeichert werden.', 'danger'));
        }

        function checkCartFnCard(reservationId) {
            const input = document.getElementById('fncart_' + reservationId);
            const msg = document.getElementById('fnmsg_' + reservationId);
            const number = input.value.trim();
            if (!/^\d{8}$/.test(number)) {
                msg.innerHTML = '<span class="text-danger">Die FN-Card-Nummer muss 8 Ziffern lang sein.</span>';
                return;
            }
            msg.textContent = 'Prüfe …';
            fetch('<?= url('api/fncard_validate.php') ?>?action=set_fncard&reservation_id=' + reservationId + '&fncard=' + encodeURIComponent(number))
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        location.reload();
                    } else {
                        msg.innerHTML = '<span class="text-danger"></span>';
                        msg.firstChild.textContent = data.error || data.message || 'FN-Card konnte nicht geprüft werden.';
                    }
                })
                .catch(() => { msg.innerHTML = '<span class="text-danger">FN-Card konnte nicht geprüft werden.</span>'; });
        }

        function removeFromCart(reservationId) {
            if (!confirm('Möchten Sie diesen Platz wirklich aus dem Warenkorb entfernen?')) {
                return;
            }
            
            fetch('<?= url('api/cart_remove.php') ?>', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({
                    reservation_id: reservationId
                })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showFeedback('Platz wurde aus dem Warenkorb entfernt.', 'success');
                    setTimeout(() => {
                        window.location.reload();
                    }, 1000);
                } else {
                    showFeedback('Fehler: ' + (data.error || 'Unbekannter Fehler'), 'error');
                }
            })
            .catch(error => {
                showFeedback('Fehler beim Entfernen: ' + error.message, 'error');
            });
        }
        
        function removeVoucherFromCart(voucherId) {
            if (!confirm('Möchten Sie diesen Gutschein wirklich aus dem Warenkorb entfernen?')) {
                return;
            }
            
            fetch('<?= url('api/voucher_remove.php') ?>', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({
                    voucher_id: voucherId
                })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showFeedback('Gutschein wurde aus dem Warenkorb entfernt.', 'success');
                    setTimeout(() => {
                        window.location.reload();
                    }, 1000);
                } else {
                    showFeedback('Fehler: ' + (data.error || 'Unbekannter Fehler'), 'error');
                }
            })
            .catch(error => {
                showFeedback('Fehler beim Entfernen: ' + error.message, 'error');
            });
        }
        
        // Handle voucher code redemption
        const voucherRedeemForm = document.getElementById('voucher-redeem-form');
        if (voucherRedeemForm) voucherRedeemForm.addEventListener('submit', function(e) {
            e.preventDefault();
            
            const codeInput = document.getElementById('voucher-code-input');
            const messageDiv = document.getElementById('voucher-redeem-message');
            const submitButton = this.querySelector('button[type="submit"]');
            const originalText = submitButton.innerHTML;
            
            const voucherCode = codeInput.value.trim().toUpperCase();
            
            if (!voucherCode) {
                messageDiv.innerHTML = '<div class="alert alert-warning mb-0"><i class="bi bi-exclamation-triangle"></i> Bitte geben Sie einen Gutscheincode ein.</div>';
                return;
            }
            
            submitButton.disabled = true;
            submitButton.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Wird eingelöst...';
            messageDiv.innerHTML = '';
            
            fetch('<?= url('api/voucher_redeem.php') ?>', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({
                    voucher_code: voucherCode
                })
            })
            .then(response => {
                // Check if response is ok, if not try to parse error message
                if (!response.ok) {
                    return response.json().then(data => {
                        throw new Error(data.error || 'Fehler beim Einlösen des Gutscheins');
                    }).catch(() => {
                        throw new Error('Fehler beim Einlösen des Gutscheins (Status: ' + response.status + ')');
                    });
                }
                return response.json();
            })
            .then(data => {
                if (data.success) {
                    messageDiv.innerHTML = '<div class="alert alert-success mb-0"><i class="bi bi-check-circle"></i> ' + data.message + '</div>';
                    codeInput.value = '';
                    // Reload page after short delay to show updated cart
                    setTimeout(() => {
                        window.location.reload();
                    }, 1500);
                } else {
                    messageDiv.innerHTML = '<div class="alert alert-danger mb-0"><i class="bi bi-x-circle"></i> ' + (data.error || 'Unbekannter Fehler') + '</div>';
                }
            })
            .catch(error => {
                messageDiv.innerHTML = '<div class="alert alert-danger mb-0"><i class="bi bi-x-circle"></i> ' + error.message + '</div>';
            })
            .finally(() => {
                submitButton.disabled = false;
                submitButton.innerHTML = originalText;
            });
        });
        
        // Auto-uppercase input
        const voucherCodeInput = document.getElementById('voucher-code-input');
        if (voucherCodeInput) voucherCodeInput.addEventListener('input', function(e) {
            this.value = this.value.toUpperCase();
        });
        function clearCart() {
            if (!confirm('Möchten Sie den Warenkorb wirklich leeren?')) {
                return;
            }
            
            fetch('<?= url('api/cart_clear.php') ?>', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                }
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showFeedback('Warenkorb erfolgreich geleert.', 'success');
                    setTimeout(() => {
                        window.location.reload();
                    }, 1000);
                } else {
                    showFeedback('Fehler: ' + (data.error || 'Unbekannter Fehler'), 'danger');
                }
            })
            .catch(error => {
                showFeedback('Fehler beim Leeren des Warenkorbs: ' + error.message, 'danger');
            });
        }
    </script>
    <script src="<?= url('libs/js/cart-heartbeat.js') ?>" data-endpoint="<?= url('api/cart_update_timestamp.php') ?>"></script>
</body>
</html>

