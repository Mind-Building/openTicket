<?php
require_once __DIR__ . '/../../app/bootstrap.php';

use App\Core\Auth;
use App\Core\Config;
use App\Models\Voucher;

Auth::requirePresales();

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

