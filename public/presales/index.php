<?php
require_once __DIR__ . '/../../app/bootstrap.php';

use App\Core\Auth;
use App\Core\Config;
use App\Core\Permission;
use App\Models\Reservation;
use App\Models\Voucher;

Auth::requireSalesStaff();

// External partner: own dashboard (today's sales, cash still to settle)
if (Auth::isPartner()) {
    $reservationModel = new Reservation();
    $userId = (int)Auth::id();
    $todayOrders = Reservation::groupSales($reservationModel->getSellerSales([
        'sold_by_user_id' => $userId,
        'from' => date('Y-m-d'),
        'to' => date('Y-m-d'),
    ]));
    $openOrders = Reservation::groupSales($reservationModel->getSellerSales([
        'sold_by_user_id' => $userId,
        'status_ids' => [10],
        'settled' => 'open',
    ]));
    $money = fn(float $v) => number_format($v, 2, ',', '.') . ' €';
    $todayTickets = array_sum(array_map(fn($o) => count($o['ids']), $todayOrders));
    $todayTotal = array_sum(array_column($todayOrders, 'total'));
    $openTotal = array_sum(array_column($openOrders, 'total'));

    $pageTitle = 'Externer Partner';
    $showSidebar = true;
    $showHeader = true;
    $content = '
<h2 class="mb-4">Willkommen, ' . htmlspecialchars(Auth::username() ?? '') . '</h2>
<div class="row g-3 mb-4">
    <div class="col-md-4">
        <div class="card h-100"><div class="card-body">
            <div class="text-muted small">Heute verkauft</div>
            <div class="fs-3 fw-bold">' . $todayTickets . ' Karte(n)</div>
            <div class="small text-muted">' . $money($todayTotal) . '</div>
        </div></div>
    </div>
    <div class="col-md-4">
        <div class="card border-warning h-100"><div class="card-body">
            <div class="text-muted small">Noch abzurechnen (Bargeld)</div>
            <div class="fs-3 fw-bold">' . $money($openTotal) . '</div>
            <div class="small text-muted">wird mit dem Veranstalter abgerechnet</div>
        </div></div>
    </div>
</div>
<div class="d-flex flex-wrap gap-2">
    <a href="' . url('presales/sell.php') . '" class="btn btn-primary btn-lg"><i class="bi bi-cart-plus"></i> Karten verkaufen</a>'
    . (Permission::can(Permission::OWN_ORDERS) ? '
    <a href="' . url('presales/my_sales.php') . '" class="btn btn-outline-secondary btn-lg"><i class="bi bi-receipt"></i> Meine Verkäufe</a>' : '') . '
</div>';
    require __DIR__ . '/../../app/views/layouts/main.php';
    exit;
}

$vouchersActive = Config::get('voucher_active', false) && Config::get('presales_allow_vouchers', true);

$stuckVouchers = [];
if ($vouchersActive) {
    $voucherModel = new Voucher();
    $stuckVouchers = $voucherModel->getStuckInCart(15);
}

$pageTitle = 'Vorverkauf Dashboard';
$showSidebar = true;
$showHeader = true;

$stuckWarning = '';
if (!empty($stuckVouchers)) {
    $cnt = count($stuckVouchers);
    $stuckWarning = '
<div class="alert alert-warning d-flex align-items-center gap-3" role="alert">
    <i class="bi bi-exclamation-triangle-fill fs-4"></i>
    <div class="flex-grow-1">
        <strong>' . $cnt . ' Gutschein' . ($cnt !== 1 ? 'e' : '') . ' im Warenkorb nicht abgeschlossen</strong>
        – Diese Gutscheine wurden vor mehr als 15 Minuten in den Warenkorb gelegt, aber nicht bezahlt.
        <a href="' . url('presales/vouchers.php?filter=stuck') . '" class="alert-link ms-2">Jetzt anzeigen &rarr;</a>
    </div>
</div>';
}

$content = $stuckWarning . '
<div class="card">
    <div class="card-body">
        <h5 class="card-title">Willkommen im Vorverkauf-Bereich</h5>
        <p class="card-text">Hier können Sie Reservierungen verwalten und Tickets verkaufen.</p>
    </div>
</div>
';

require __DIR__ . '/../../app/views/layouts/main.php';

