<?php
require_once __DIR__ . '/../../app/bootstrap.php';

use App\Core\Auth;
use App\Models\Voucher;
use App\Models\Reservation;

Auth::requireAdmin();

$voucherId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($voucherId <= 0) {
    header('Location: ' . url('admin/vouchers.php'));
    exit;
}

$voucherModel = new Voucher();
$reservationModel = new Reservation();

$voucher = $voucherModel->getById($voucherId);

if (!$voucher) {
    header('Location: ' . url('admin/vouchers.php'));
    exit;
}

// Get reservations that used this voucher
$reservations = $reservationModel->getByVoucherId($voucherId);

$pageTitle = 'Gutschein-Details';
$showSidebar = true;
$showHeader = true;

$statusNames = [
    0 => 'Nicht eingelöst',
    1 => 'Im Warenkorb',
    2 => 'Bezahlt',
    3 => 'Eingelöst',
];

$statusLabel = match((int)$voucher['status_id']) {
    0 => '<span class="badge bg-secondary">Nicht eingelöst</span>',
    1 => '<span class="badge bg-warning">Im Warenkorb</span>',
    2 => '<span class="badge bg-success">Bezahlt</span>',
    3 => '<span class="badge bg-info">Eingelöst</span>',
    default => '<span class="badge bg-secondary">Unbekannt</span>'
};

$customerName = $voucher['customer_first_name'] && $voucher['customer_last_name'] 
    ? $voucher['customer_first_name'] . ' ' . $voucher['customer_last_name'] 
    : '-';

$content = '
<div class="d-flex justify-content-between align-items-center mb-4">
    <h2>Gutschein-Details</h2>
    <a href="' . url('admin/vouchers.php') . '" class="btn btn-outline-secondary">
        <i class="bi bi-arrow-left"></i> Zurück zur Übersicht
    </a>
</div>

<div class="row">
    <div class="col-md-6">
        <div class="card mb-4">
            <div class="card-header">
                <h5 class="mb-0">Gutschein-Informationen</h5>
            </div>
            <div class="card-body">
                <table class="table table-borderless">
                    <tr>
                        <th width="40%">Gutscheincode:</th>
                        <td><code class="fs-5">' . htmlspecialchars($voucher['voucher_code']) . '</code></td>
                    </tr>
                    <tr>
                        <th>ID:</th>
                        <td>' . htmlspecialchars($voucher['id']) . '</td>
                    </tr>
                    <tr>
                        <th>Status:</th>
                        <td>' . $statusLabel . '</td>
                    </tr>
                    <tr>
                        <th>Veranstaltung:</th>
                        <td>' . htmlspecialchars($voucher['event_name'] ?? '-') . '</td>
                    </tr>
                    <tr>
                        <th>Kategorie:</th>
                        <td>' . htmlspecialchars($voucher['category_name'] ?? '-') . '</td>
                    </tr>
                    <tr>
                        <th>Wert:</th>
                        <td><strong>' . number_format((float)($voucher['category_price'] ?? 0), 2, ',', '.') . ' €</strong></td>
                    </tr>
                    <tr>
                        <th>Anzahl:</th>
                        <td>' . htmlspecialchars($voucher['quantity']) . '</td>
                    </tr>
                    <tr>
                        <th>Kunde:</th>
                        <td>' . htmlspecialchars($customerName) . '</td>
                    </tr>
                    ' . ($voucher['customer_email'] ? '<tr>
                        <th>E-Mail:</th>
                        <td>' . htmlspecialchars($voucher['customer_email']) . '</td>
                    </tr>' : '') . '
                    <tr>
                        <th>Bestelldatum:</th>
                        <td>' . date('d.m.Y H:i', strtotime($voucher['order_date'])) . '</td>
                    </tr>
                    <tr>
                        <th>Erstellt am:</th>
                        <td>' . date('d.m.Y H:i', strtotime($voucher['created_at'])) . '</td>
                    </tr>
                </table>
                
                <div class="mt-3">
                    <a href="' . url('admin/vouchers_edit.php?id=' . $voucher['id']) . '" class="btn btn-primary">
                        <i class="bi bi-pencil"></i> Bearbeiten
                    </a>
                    <a href="' . url('admin/vouchers.php?delete=' . $voucher['id']) . '" class="btn btn-outline-danger" onclick="return confirm(\'Möchten Sie diesen Gutschein wirklich löschen?\')">
                        <i class="bi bi-trash"></i> Löschen
                    </a>
                </div>
            </div>
        </div>
    </div>
    
    <div class="col-md-6">
        <div class="card mb-4">
            <div class="card-header">
                <h5 class="mb-0">Verwendete Reservierungen</h5>
            </div>
            <div class="card-body">
';

if (empty($reservations)) {
    $content .= '
                <div class="alert alert-info mb-0">
                    <i class="bi bi-info-circle"></i> Dieser Gutschein wurde noch nicht für eine Reservierung verwendet.
                </div>';
} else {
    $content .= '
                <p class="text-muted mb-3">Dieser Gutschein wurde für <strong>' . count($reservations) . '</strong> Reservierung(en) verwendet:</p>
                <div class="table-responsive">
                    <table class="table table-sm table-hover">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Veranstaltung</th>
                                <th>Datum</th>
                                <th>Platz</th>
                                <th>Kunde</th>
                                <th>Status</th>
                                <th>Aktionen</th>
                            </tr>
                        </thead>
                        <tbody>';
    
    foreach ($reservations as $reservation) {
        $reservationStatusBadge = reservationStatusBadge((int)$reservation['status_id']);
        
        $reservationCustomerName = $reservation['first_name'] && $reservation['last_name'] 
            ? htmlspecialchars($reservation['first_name'] . ' ' . $reservation['last_name'])
            : '<span class="text-muted">-</span>';
        
        $content .= '
                            <tr>
                                <td>' . $reservation['id'] . '</td>
                                <td>' . htmlspecialchars($reservation['event_name'] ?? '-') . '</td>
                                <td>' . date('d.m.Y', strtotime($reservation['event_date'])) . '</td>
                                <td><code>' . htmlspecialchars($reservation['seat_identifier']) . '</code></td>
                                <td>' . $reservationCustomerName . '</td>
                                <td>' . $reservationStatusBadge . '</td>
                                <td>
                                    <a href="' . url('admin/reservations.php') . '?id=' . $reservation['id'] . '" class="btn btn-sm btn-outline-primary" title="Details">
                                        <i class="bi bi-eye"></i>
                                    </a>
                                </td>
                            </tr>';
    }
    
    $content .= '
                        </tbody>
                    </table>
                </div>';
}

$content .= '
            </div>
        </div>
    </div>
</div>
';

require __DIR__ . '/../../app/views/layouts/main.php';

