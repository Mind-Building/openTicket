<?php
require_once __DIR__ . '/../../app/bootstrap.php';

use App\Core\Auth;
use App\Models\Voucher;
use App\Models\Reservation;

Auth::requireAdmin();

$voucherModel = new Voucher();
$reservationModel = new Reservation();
$message = '';
$messageType = '';
$resendApiUrl = json_encode(url('api/voucher_resend_email.php'));

// Handle search
$searchQuery = $_GET['search'] ?? '';
$vouchers = $searchQuery ? $voucherModel->search($searchQuery) : $voucherModel->getAll(100, 0);
$totalCount = $voucherModel->count();

// Handle delete
if (isset($_GET['delete']) && is_numeric($_GET['delete'])) {
    try {
        $deleteId = (int)$_GET['delete'];
        $deleteVoucher = $voucherModel->getById($deleteId);
        $voucherModel->delete($deleteId);
        \App\Services\LoggerService::logAdmin('voucher_deleted', [
            'voucher_id'   => $deleteId,
            'voucher_code' => $deleteVoucher['voucher_code'] ?? null,
            'event_name'   => $deleteVoucher['event_name'] ?? null,
            'admin_id'     => \App\Core\Auth::id(),
            'admin_user'   => \App\Core\Auth::username(),
        ]);
        $message = 'Gutschein erfolgreich gelöscht.';
        $messageType = 'success';
        // Reload vouchers
        $vouchers = $searchQuery ? $voucherModel->search($searchQuery) : $voucherModel->getAll(100, 0);
    } catch (\Exception $e) {
        $message = 'Fehler beim Löschen: ' . $e->getMessage();
        $messageType = 'danger';
    }
}

// Get status names
$statusNames = [
    0 => 'Vorverkauf, nicht eingelöst',
    1 => 'Im Warenkorb',
    2 => 'Bezahlt, nicht eingelöst',
    3 => 'Eingelöst',
];

$pageTitle = 'Gutscheine';
$showSidebar = true;
$showHeader = true;

$content = '
<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <h5 class="card-title mb-0"><i class="bi bi-gift"></i> Gutscheinverwaltung</h5>
        <div class="d-flex gap-2">
            <a href="' . url('admin/vouchers_create.php') . '" class="btn btn-sm btn-primary">
                <i class="bi bi-plus-circle"></i> Neuer Gutschein
            </a>
            <form method="GET" action="" class="d-flex">
                <input type="text" class="form-control form-control-sm" name="search" placeholder="Suchen..." 
                       value="' . htmlspecialchars($searchQuery) . '">
                <button type="submit" class="btn btn-sm btn-outline-secondary ms-2">
                    <i class="bi bi-search"></i>
                </button>
                ' . ($searchQuery ? '<a href="' . url('admin/vouchers.php') . '" class="btn btn-sm btn-outline-secondary ms-1">
                    <i class="bi bi-x-circle"></i>
                </a>' : '') . '
            </form>
        </div>
    </div>
    <div class="card-body">
        ' . ($message ? '<div class="alert alert-' . $messageType . ' alert-dismissible fade show" role="alert">' . htmlspecialchars($message) . '<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>' : '') . '
        
        <div id="resend-feedback" class="mb-2"></div>
        <div class="mb-3">
            <small class="text-muted">Gesamt: ' . $totalCount . ' Gutscheine' . ($searchQuery ? ' (Gefiltert: ' . count($vouchers) . ')' : '') . '</small>
        </div>
        
        <div class="table-responsive">
            <table class="table table-striped table-hover">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Gutscheincode</th>
                        <th>Veranstaltung</th>
                        <th>Kategorie</th>
                        <th>Anzahl</th>
                        <th>Kunde</th>
                        <th>Status</th>
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
        $customerName = $voucher['customer_first_name'] && $voucher['customer_last_name'] 
            ? $voucher['customer_first_name'] . ' ' . $voucher['customer_last_name'] 
            : '-';
        $statusLabel = match((int)$voucher['status_id']) {
            0 => '<span class="badge bg-secondary">Vorverkauf, nicht eingelöst</span>',
            1 => '<span class="badge bg-warning">Im Warenkorb</span>',
            2 => '<span class="badge bg-success">Bezahlt, nicht eingelöst</span>',
            3 => (int)($voucher['transformed_status_id'] ?? 2) === 0
                ? '<span class="badge bg-info">Vorverkauf, eingelöst</span>'
                : '<span class="badge bg-info">Bezahlt, eingelöst</span>',
            default => '<span class="badge bg-secondary">Unbekannt</span>'
        };
        $createdAt = date('d.m.Y H:i', strtotime($voucher['created_at']));
        
        $content .= '<tr>
            <td>' . htmlspecialchars($voucher['id']) . '</td>
            <td><code>' . htmlspecialchars($voucher['voucher_code']) . '</code></td>
            <td>' . htmlspecialchars($voucher['event_name'] ?? '-') . '</td>
            <td>' . htmlspecialchars($voucher['category_name'] ?? '-') . '</td>
            <td>' . htmlspecialchars($voucher['quantity']) . '</td>
            <td>' . htmlspecialchars($customerName) . '</td>
            <td>' . $statusLabel . '</td>
            <td>' . $createdAt . '</td>
            <td>
                ' . (!empty($voucher['customer_id']) ? '<button class="btn btn-sm btn-outline-secondary" onclick="resendEmail(' . $voucher['id'] . ', this)" title="E-Mail erneut senden">
                    <i class="bi bi-envelope-plus"></i>
                </button>' : '') . '
                <a href="' . url('admin/vouchers_detail.php?id=' . $voucher['id']) . '" class="btn btn-sm btn-outline-info" title="Details">
                    <i class="bi bi-eye"></i>
                </a>
                <a href="' . url('admin/vouchers_edit.php?id=' . $voucher['id']) . '" class="btn btn-sm btn-outline-primary" title="Bearbeiten">
                    <i class="bi bi-pencil"></i>
                </a>
                <a href="' . url('admin/vouchers.php?delete=' . $voucher['id']) . '" class="btn btn-sm btn-outline-danger" title="Löschen" onclick="return confirm(\'Möchten Sie diesen Gutschein wirklich löschen?\')">
                    <i class="bi bi-trash"></i>
                </a>
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
