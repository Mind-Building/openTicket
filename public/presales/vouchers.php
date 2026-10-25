<?php
require_once __DIR__ . '/../../app/bootstrap.php';

use App\Core\Auth;
use App\Core\Config;
use App\Models\Voucher;

Auth::requirePresales();

$voucherModel = new Voucher();

$searchQuery = $_GET['search'] ?? '';
$filterStuck = isset($_GET['filter']) && $_GET['filter'] === 'stuck';

// Handle release of stuck cart vouchers
$releaseMessage = '';
// CSRF-Schutz für alle POST-Aktionen dieser Seite
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !\App\Core\Csrf::validatePost()) {
    http_response_code(403);
    die('Ungültige Anfrage: Sicherheits-Token fehlt oder ist abgelaufen. Bitte die Seite neu laden und erneut versuchen.');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'release_stuck') {
    $released = $voucherModel->releaseStuckCartVouchers(15);
    $releaseMessage = '<div class="alert alert-success alert-dismissible fade show py-2" role="alert">'
        . $released . ' Gutschein' . ($released !== 1 ? 'e' : '') . ' aus dem Warenkorb freigegeben.'
        . '<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>';
    $filterStuck = false;
}

$stuckVouchers = $voucherModel->getStuckInCart(15);

if ($filterStuck) {
    $vouchers = $stuckVouchers;
} elseif ($searchQuery) {
    $vouchers = $voucherModel->search($searchQuery);
} else {
    $vouchers = $voucherModel->getAll(200, 0);
}
$totalCount = $voucherModel->count();

$vouchersActive = Config::get('voucher_active', false) && Config::get('presales_allow_vouchers', true);

$pageTitle = 'Gutscheine';
$showSidebar = true;
$showHeader = true;

$resendApiUrl = json_encode(url('api/voucher_resend_email.php'));

$stuckCount = count($stuckVouchers);
$stuckBanner = '';
if ($stuckCount > 0) {
    $stuckBanner = '
    <div class="alert alert-warning d-flex align-items-center justify-content-between gap-3 mb-3" role="alert">
        <div><i class="bi bi-exclamation-triangle-fill me-2"></i>
            <strong>' . $stuckCount . ' Gutschein' . ($stuckCount !== 1 ? 'e' : '') . ' im Warenkorb nicht abgeschlossen</strong>
            (älter als 15 Minuten).
            ' . (!$filterStuck ? '<a href="' . url('presales/vouchers.php?filter=stuck') . '" class="alert-link">Nur diese anzeigen</a>' : '<a href="' . url('presales/vouchers.php') . '" class="alert-link">Alle anzeigen</a>') . '
        </div>
        <form method="POST" action="" class="mb-0" onsubmit="return confirm(\'Alle ' . $stuckCount . ' festhängenden Gutscheine aus dem Warenkorb freigeben?\')">
            ' . \App\Core\Csrf::field() . '
            <input type="hidden" name="action" value="release_stuck">
            <button type="submit" class="btn btn-sm btn-warning">
                <i class="bi bi-arrow-counterclockwise"></i> Alle freigeben
            </button>
        </form>
    </div>';
}

$content = $releaseMessage . '
<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <h5 class="card-title mb-0"><i class="bi bi-gift"></i> Gutscheinkiste' . ($filterStuck ? ' – Im Warenkorb festhängend' : '') . '</h5>
        <div class="d-flex gap-2">
            ' . ($vouchersActive ? '<a href="' . url('presales/vouchers_create.php') . '" class="btn btn-sm btn-primary">
                <i class="bi bi-plus-circle"></i> Neuer Gutschein
            </a>' : '') . '
            <form method="GET" action="" class="d-flex">
                <input type="text" class="form-control form-control-sm" name="search" placeholder="Suchen..."
                       value="' . htmlspecialchars($searchQuery) . '">
                <button type="submit" class="btn btn-sm btn-outline-secondary ms-2">
                    <i class="bi bi-search"></i>
                </button>
                ' . ($searchQuery || $filterStuck ? '<a href="' . url('presales/vouchers.php') . '" class="btn btn-sm btn-outline-secondary ms-1">
                    <i class="bi bi-x-circle"></i>
                </a>' : '') . '
            </form>
        </div>
    </div>
    <div class="card-body">
        ' . $stuckBanner . '
        <div id="resend-feedback" class="mb-2"></div>
        <div class="mb-3">
            <small class="text-muted">Gesamt: ' . $totalCount . ' Gutscheine' . ($searchQuery ? ' (Suche: ' . count($vouchers) . ')' : ($filterStuck ? ' (Warenkorb festhängend: ' . $stuckCount . ')' : '')) . '</small>
        </div>

        <div class="table-responsive">
            <table class="table table-striped table-hover">
                <thead>
                    <tr>
                        <th>Code</th>
                        <th>Veranstaltung</th>
                        <th>Kategorie</th>
                        <th>Anzahl</th>
                        <th>Kunde</th>
                        <th>Status</th>
                        <th>Mail</th>
                        <th>Erstellt</th>
                        <th>Aktionen</th>
                    </tr>
                </thead>
                <tbody>
';

if (empty($vouchers)) {
    $content .= '<tr><td colspan="9" class="text-center text-muted">Keine Gutscheine gefunden.</td></tr>';
} else {
    foreach ($vouchers as $voucher) {
        $customerName = ($voucher['customer_first_name'] && $voucher['customer_last_name'])
            ? htmlspecialchars($voucher['customer_first_name'] . ' ' . $voucher['customer_last_name'])
            : '-';
        $statusLabel = match((int)$voucher['status_id']) {
            0 => '<span class="badge bg-secondary">Vorverkauf, nicht eingelöst</span>',
            1 => '<span class="badge bg-warning text-dark">Im Warenkorb</span>',
            2 => '<span class="badge bg-success">Bezahlt, nicht eingelöst</span>',
            3 => (int)($voucher['transformed_status_id'] ?? 2) === 0
                ? '<span class="badge bg-info">Vorverkauf, eingelöst</span>'
                : '<span class="badge bg-info">Bezahlt, eingelöst</span>',
            default => '<span class="badge bg-secondary">Unbekannt</span>',
        };
        $mailLabel = $voucher['mail_sent']
            ? '<span class="badge bg-success" title="' . ($voucher['mail_sent_date'] ? 'am ' . date('d.m.Y', strtotime($voucher['mail_sent_date'])) : '') . '"><i class="bi bi-check"></i></span>'
            : '<span class="badge bg-secondary">-</span>';
        $createdAt = date('d.m.Y', strtotime($voucher['created_at']));
        $hasCustomerEmail = !empty($voucher['customer_id']);

        $content .= '<tr>
            <td><code>' . htmlspecialchars($voucher['voucher_code']) . '</code></td>
            <td>' . htmlspecialchars($voucher['event_name'] ?? '-') . '</td>
            <td>' . htmlspecialchars($voucher['category_name'] ?? '-') . '</td>
            <td>' . htmlspecialchars($voucher['quantity']) . '</td>
            <td>' . $customerName . '</td>
            <td>' . $statusLabel . '</td>
            <td>' . $mailLabel . '</td>
            <td>' . $createdAt . '</td>
            <td>
                ' . ($hasCustomerEmail ? '<button class="btn btn-sm btn-outline-secondary" onclick="resendEmail(' . $voucher['id'] . ', this)" title="E-Mail erneut senden">
                    <i class="bi bi-envelope-plus"></i>
                </button>' : '') . '
            </td>
        </tr>';
    }
}

$content .= '
                </tbody>
            </table>
        </div>
    </div>
</div>
';

$additionalJs = '
<script>
const resendVoucherEmailUrl = ' . $resendApiUrl . ';

function resendEmail(voucherId, btn) {
    if (!confirm("E-Mail für diesen Gutschein erneut senden?")) return;
    btn.disabled = true;
    btn.innerHTML = \'<span class="spinner-border spinner-border-sm"></span>\';

    fetch(resendVoucherEmailUrl, {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ voucher_id: voucherId })
    })
    .then(r => r.json())
    .then(data => {
        const fb = document.getElementById("resend-feedback");
        if (data.success) {
            fb.innerHTML = \'<div class="alert alert-success alert-dismissible fade show py-2">\' + data.message + \'<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>\';
            btn.innerHTML = \'<i class="bi bi-check-circle text-success"></i>\';
        } else {
            fb.innerHTML = \'<div class="alert alert-danger alert-dismissible fade show py-2">\' + (data.error || "Fehler") + \'<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>\';
            btn.disabled = false;
            btn.innerHTML = \'<i class="bi bi-envelope-plus"></i>\';
        }
    })
    .catch(() => {
        btn.disabled = false;
        btn.innerHTML = \'<i class="bi bi-envelope-plus"></i>\';
    });
}
</script>
';

require __DIR__ . '/../../app/views/layouts/main.php';
