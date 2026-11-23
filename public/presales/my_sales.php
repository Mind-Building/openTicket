<?php
/**
 * "Meine Verkäufe" – own sales of an external partner (role partner).
 * Lists the partner's orders with total, cash still to hand over (not settled yet),
 * ticket reprint and – with the "Eigene Verkäufe stornieren" permission – cancellation
 * of unsettled sales. Presales staff use reservations.php instead.
 */
require_once __DIR__ . '/../../app/bootstrap.php';

use App\Core\Auth;
use App\Core\Permission;
use App\Models\Reservation;
use App\Models\SeatPlan;

Auth::requireSalesStaff();
if (!Auth::isPartner()) {
    // Presales office: full reservation list
    header('Location: ' . url('presales/reservations.php'));
    exit;
}
Permission::require(Permission::OWN_ORDERS);

$reservationModel = new Reservation();
$seatPlanModel = new SeatPlan();
$canCancel = Permission::can(Permission::CANCEL_OWN);

$from = $_GET['from'] ?? date('Y-m-d', strtotime('-30 days'));
$to = $_GET['to'] ?? date('Y-m-d');
$validDate = fn($d) => (bool)preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$d);
if (!$validDate($from)) {
    $from = date('Y-m-d', strtotime('-30 days'));
}
if (!$validDate($to)) {
    $to = date('Y-m-d');
}

$userId = (int)Auth::id();
$orders = Reservation::groupSales($reservationModel->getSellerSales([
    'sold_by_user_id' => $userId,
    'from' => $from,
    'to' => $to,
]));

// Cash still with the partner: all unsettled cash sales (independent of the date filter)
$openOrders = Reservation::groupSales($reservationModel->getSellerSales([
    'sold_by_user_id' => $userId,
    'status_ids' => [10],
    'settled' => 'open',
]));
$openCash = array_sum(array_column($openOrders, 'total'));
$openTickets = array_sum(array_map(fn($o) => count($o['ids']), $openOrders));

$money = fn(float $v) => number_format($v, 2, ',', '.') . ' €';

$pageTitle = 'Meine Verkäufe';
$showSidebar = true;
$showHeader = true;

$content = '
<div class="d-flex justify-content-between align-items-center mb-4">
    <h2>Meine Verkäufe</h2>
    <a href="' . url('presales/sell.php') . '" class="btn btn-primary"><i class="bi bi-cart-plus"></i> Neuer Verkauf</a>
</div>

<div class="row g-3 mb-4">
    <div class="col-md-6">
        <div class="card border-warning h-100">
            <div class="card-body">
                <div class="text-muted small">Noch abzurechnen (Bargeld)</div>
                <div class="fs-3 fw-bold">' . $money($openCash) . '</div>
                <div class="small text-muted">' . $openTickets . ' Karte(n) aus Barverkauf, noch nicht mit dem Veranstalter abgerechnet</div>
            </div>
        </div>
    </div>
</div>

<div class="card mb-4">
    <div class="card-body">
        <form method="GET" class="row g-3 align-items-end">
            <div class="col-md-4">
                <label class="form-label" for="from">Verkauft von</label>
                <input type="date" class="form-control" id="from" name="from" value="' . htmlspecialchars($from) . '">
            </div>
            <div class="col-md-4">
                <label class="form-label" for="to">bis</label>
                <input type="date" class="form-control" id="to" name="to" value="' . htmlspecialchars($to) . '">
            </div>
            <div class="col-md-4">
                <button type="submit" class="btn btn-outline-primary w-100"><i class="bi bi-funnel"></i> Anzeigen</button>
            </div>
        </form>
    </div>
</div>

<div class="card">
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-hover align-middle">
                <thead>
                    <tr>
                        <th>Verkauft am</th>
                        <th>Veranstaltung</th>
                        <th>Plätze</th>
                        <th>Kunde</th>
                        <th>Status</th>
                        <th class="text-end">Betrag</th>
                        <th>Aktionen</th>
                    </tr>
                </thead>
                <tbody>';

if (empty($orders)) {
    $content .= '<tr><td colspan="7" class="text-center text-muted">Keine Verkäufe in diesem Zeitraum.</td></tr>';
}

$periodTotal = 0.0;
foreach ($orders as $order) {
    $periodTotal += $order['total'];
    $seats = array_map(
        fn($r) => $seatPlanModel->getSeatDisplayName((int)$r['event_id'], (string)$r['seat_identifier']),
        $order['rows']
    );
    $actions = '';
    $printable = in_array($order['status_id'], [2, 4, 5, 7, 8, 9, 10, 11], true);
    if ($printable) {
        $token = generateTicketDownloadToken($order['ids']);
        $ticketUrl = url('api/ticket_download.php?reservation_ids=' . implode(',', $order['ids']) . '&token=' . $token);
        $actions .= '<a href="' . htmlspecialchars($ticketUrl) . '" target="_blank" class="btn btn-sm btn-outline-primary me-1" title="Tickets drucken"><i class="bi bi-printer"></i></a>';
    }
    if ($canCancel && !$order['any_settled'] && in_array($order['status_id'], [3, 10], true)) {
        $actions .= '<button type="button" class="btn btn-sm btn-outline-danger" title="Stornieren" onclick="cancelOrder(' . htmlspecialchars(json_encode($order['ids'])) . ')"><i class="bi bi-x-circle"></i></button>';
    }
    $settledNote = $order['status_id'] === 10
        ? ($order['all_settled']
            ? '<div class="small text-success"><i class="bi bi-check2"></i> abgerechnet ' . date('d.m.Y', strtotime($order['settled_at'])) . '</div>'
            : '<div class="small text-warning"><i class="bi bi-hourglass-split"></i> noch nicht abgerechnet</div>')
        : '';

    $content .= '
                    <tr>
                        <td>' . date('d.m.Y H:i', strtotime($order['order_date'])) . '</td>
                        <td>' . htmlspecialchars($order['event_name']) . '<div class="small text-muted">' . date('d.m.Y', strtotime($order['event_date'])) . '</div></td>
                        <td class="small">' . htmlspecialchars(implode(', ', $seats)) . '</td>
                        <td>' . ($order['customer'] !== '' ? htmlspecialchars($order['customer']) : '<span class="text-muted">Barverkauf</span>') . '</td>
                        <td>' . reservationStatusBadge($order['status_id']) . $settledNote . '</td>
                        <td class="text-end">' . $money($order['total']) . '</td>
                        <td class="text-nowrap">' . $actions . '</td>
                    </tr>';
}

$content .= '
                </tbody>
                <tfoot>
                    <tr><th colspan="5" class="text-end">Summe im Zeitraum</th><th class="text-end">' . $money($periodTotal) . '</th><th></th></tr>
                </tfoot>
            </table>
        </div>
    </div>
</div>';

if ($canCancel) {
    $deleteUrl = json_encode(url('api/reservation_delete.php'));
    $content .= '
<script>
function cancelOrder(ids) {
    if (!confirm("Diesen Verkauf wirklich stornieren? Die Plätze werden wieder freigegeben.")) return;
    fetch(' . $deleteUrl . ', {
        method: "POST",
        headers: {"Content-Type": "application/json"},
        body: JSON.stringify({reservation_ids: ids})
    })
    .then(function (r) { return r.json(); })
    .then(function (data) {
        if (data.success) { location.reload(); }
        else { alert(data.error || data.message || "Stornieren fehlgeschlagen"); }
    })
    .catch(function () { alert("Stornieren fehlgeschlagen"); });
}
</script>';
}

require __DIR__ . '/../../app/views/layouts/main.php';
