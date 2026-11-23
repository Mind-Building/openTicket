<?php
/**
 * Abrechnung Partner – cash sales of external partners (status 10) per partner and period.
 * Shows the cash still with each partner (not settled), lets the admin mark sales as
 * settled (reservations.partner_settled_at) and exports the list as CSV.
 */
require_once __DIR__ . '/../../app/bootstrap.php';

use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Permission;
use App\Models\Reservation;
use App\Models\SeatPlan;
use App\Models\User;
use App\Services\LoggerService;

Auth::requireAdmin();

$reservationModel = new Reservation();
$userModel = new User();
$seatPlanModel = new SeatPlan();

if (!Permission::partnerRoleAvailable()) {
    $pageTitle = 'Abrechnung Partner';
    $showSidebar = true;
    $showHeader = true;
    $content = '<h2>Abrechnung Partner</h2><div class="alert alert-warning">Für die Rolle „Externe Partner" fehlt noch das Datenbank-Update (<a href="' . url('admin/database_update.php') . '">Datenbank-Update</a>).</div>';
    require __DIR__ . '/../../app/views/layouts/main.php';
    exit;
}

$validDate = fn($d) => (bool)preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$d);
$partnerId = (int)($_GET['partner_id'] ?? 0);
$from = $validDate($_GET['from'] ?? '') ? $_GET['from'] : '';
$to = $validDate($_GET['to'] ?? '') ? $_GET['to'] : '';
$settled = in_array($_GET['settled'] ?? 'open', ['open', 'settled', 'all'], true) ? ($_GET['settled'] ?? 'open') : 'open';

$message = '';
$messageType = '';

// ── Mark as settled ───────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Csrf::validatePost()) {
        http_response_code(403);
        die('Ungültige Anfrage: Sicherheits-Token fehlt oder ist abgelaufen. Bitte die Seite neu laden und erneut versuchen.');
    }
    $ids = array_values(array_filter(array_map('intval', explode(',', implode(',', (array)($_POST['order_ids'] ?? []))))));
    if (empty($ids)) {
        $message = 'Bitte mindestens einen Verkauf auswählen.';
        $messageType = 'warning';
    } else {
        // Amount for the log, computed before marking
        $rows = array_filter(
            $reservationModel->getSellerSales(['partners_only' => true, 'status_ids' => [10], 'settled' => 'open']),
            fn($r) => in_array((int)$r['id'], $ids, true)
        );
        $amount = 0.0;
        $perPartner = [];
        foreach ($rows as $r) {
            $price = \App\Helpers\TicketPrice::forReservation($r)['price'];
            $amount += $price;
            $perPartner[$r['seller_name']] = ($perPartner[$r['seller_name']] ?? 0) + $price;
        }
        $marked = $reservationModel->markPartnerSettled(array_map(fn($r) => (int)$r['id'], $rows));
        LoggerService::logAdmin('partner_settlement', [
            'admin_user_id'   => Auth::id(),
            'reservation_ids' => array_values(array_map(fn($r) => (int)$r['id'], $rows)),
            'count'           => $marked,
            'amount'          => round($amount, 2),
            'per_partner'     => array_map(fn($v) => round($v, 2), $perPartner),
        ]);
        $message = $marked . ' Karte(n) über ' . number_format($amount, 2, ',', '.') . ' € als abgerechnet markiert.';
        $messageType = 'success';
    }
}

// ── Load data ─────────────────────────────────────────────────────────────
$filters = ['partners_only' => true, 'status_ids' => [10]];
if ($partnerId > 0) {
    $filters['sold_by_user_id'] = $partnerId;
}
if ($from !== '') {
    $filters['from'] = $from;
}
if ($to !== '') {
    $filters['to'] = $to;
}
if ($settled !== 'all') {
    $filters['settled'] = $settled;
}
$orders = Reservation::groupSales($reservationModel->getSellerSales($filters));

// Open cash per partner (independent of the filters)
$openByPartner = [];
foreach (Reservation::groupSales($reservationModel->getSellerSales(['partners_only' => true, 'status_ids' => [10], 'settled' => 'open'])) as $o) {
    $openByPartner[$o['seller_id']] ??= ['name' => $o['seller_name'], 'total' => 0.0, 'tickets' => 0];
    $openByPartner[$o['seller_id']]['total'] += $o['total'];
    $openByPartner[$o['seller_id']]['tickets'] += count($o['ids']);
}

$seatLabels = function (array $order) use ($seatPlanModel): string {
    return implode(', ', array_map(
        fn($r) => $seatPlanModel->getSeatDisplayName((int)$r['event_id'], (string)$r['seat_identifier']),
        $order['rows']
    ));
};

// ── CSV export ────────────────────────────────────────────────────────────
if (($_GET['export'] ?? '') === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="abrechnung_partner_' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM for Excel
    fputcsv($out, ['Partner', 'Verkauft am', 'Veranstaltung', 'Datum', 'Plätze', 'Anzahl', 'Kunde', 'Betrag', 'Abgerechnet am'], ';');
    foreach ($orders as $o) {
        fputcsv($out, [
            $o['seller_name'],
            date('d.m.Y H:i', strtotime($o['order_date'])),
            $o['event_name'],
            date('d.m.Y', strtotime($o['event_date'])),
            $seatLabels($o),
            count($o['ids']),
            $o['customer'],
            number_format($o['total'], 2, ',', ''),
            $o['all_settled'] ? date('d.m.Y H:i', strtotime($o['settled_at'])) : '',
        ], ';');
    }
    fclose($out);
    exit;
}

$money = fn(float $v) => number_format($v, 2, ',', '.') . ' €';
$partners = $userModel->getPartners();

$pageTitle = 'Abrechnung Partner';
$showSidebar = true;
$showHeader = true;

$content = '
<div class="d-flex justify-content-between align-items-center mb-4">
    <h2><i class="bi bi-cash-coin"></i> Abrechnung Partner</h2>
    <a href="' . url('admin/reports.php') . '" class="btn btn-outline-secondary"><i class="bi bi-arrow-left"></i> Auswertungen</a>
</div>';

if ($message) {
    $content .= '<div class="alert alert-' . $messageType . ' alert-dismissible fade show">' . htmlspecialchars($message) . '<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>';
}
if (!Permission::partnerRoleActive()) {
    $content .= '<div class="alert alert-info small"><i class="bi bi-info-circle"></i> Die Rolle „Externe Partner" ist in den Einstellungen derzeit nicht aktiviert.</div>';
}

// Open cash cards
$content .= '<div class="row g-3 mb-4">';
if (empty($openByPartner)) {
    $content .= '<div class="col-12"><div class="alert alert-light border mb-0">Kein offenes Bargeld – alle Partner-Barverkäufe sind abgerechnet.</div></div>';
}
foreach ($openByPartner as $pid => $p) {
    $content .= '
    <div class="col-md-4">
        <a class="text-decoration-none text-body" href="' . url('admin/reports_partners.php?partner_id=' . (int)$pid . '&settled=open') . '">
            <div class="card border-warning h-100"><div class="card-body">
                <div class="text-muted small">Offen bei ' . htmlspecialchars($p['name']) . '</div>
                <div class="fs-4 fw-bold">' . $money($p['total']) . '</div>
                <div class="small text-muted">' . (int)$p['tickets'] . ' Karte(n)</div>
            </div></div>
        </a>
    </div>';
}
$content .= '</div>';

// Filter
$partnerOptions = '<option value="0">Alle Partner</option>';
foreach ($partners as $p) {
    $partnerOptions .= '<option value="' . (int)$p['id'] . '"' . ($partnerId === (int)$p['id'] ? ' selected' : '') . '>'
        . htmlspecialchars($p['username']) . ($p['active'] ? '' : ' (inaktiv)') . '</option>';
}
$csvUrl = url('admin/reports_partners.php') . '?' . http_build_query(['partner_id' => $partnerId, 'from' => $from, 'to' => $to, 'settled' => $settled, 'export' => 'csv']);
$content .= '
<div class="card mb-4"><div class="card-body">
    <form method="GET" class="row g-3 align-items-end">
        <div class="col-md-3">
            <label class="form-label" for="partner_id">Partner</label>
            <select class="form-select" id="partner_id" name="partner_id">' . $partnerOptions . '</select>
        </div>
        <div class="col-md-2">
            <label class="form-label" for="from">Verkauft von</label>
            <input type="date" class="form-control" id="from" name="from" value="' . htmlspecialchars($from) . '">
        </div>
        <div class="col-md-2">
            <label class="form-label" for="to">bis</label>
            <input type="date" class="form-control" id="to" name="to" value="' . htmlspecialchars($to) . '">
        </div>
        <div class="col-md-2">
            <label class="form-label" for="settled">Abrechnung</label>
            <select class="form-select" id="settled" name="settled">
                <option value="open"' . ($settled === 'open' ? ' selected' : '') . '>Offen</option>
                <option value="settled"' . ($settled === 'settled' ? ' selected' : '') . '>Abgerechnet</option>
                <option value="all"' . ($settled === 'all' ? ' selected' : '') . '>Alle</option>
            </select>
        </div>
        <div class="col-md-3 d-flex gap-2">
            <button type="submit" class="btn btn-primary flex-grow-1"><i class="bi bi-funnel"></i> Anzeigen</button>
            <a href="' . htmlspecialchars($csvUrl) . '" class="btn btn-outline-secondary" title="CSV-Export"><i class="bi bi-filetype-csv"></i></a>
        </div>
    </form>
</div></div>';

// Orders
$rowsHtml = '';
$sum = 0.0;
$hasOpen = false;
foreach ($orders as $o) {
    $sum += $o['total'];
    $open = !$o['any_settled'];
    $hasOpen = $hasOpen || $open;
    $rowsHtml .= '
        <tr>
            <td>' . ($open ? '<input type="checkbox" class="form-check-input settle-check" name="order_ids[]" value="' . htmlspecialchars(implode(',', $o['ids'])) . '" data-amount="' . $o['total'] . '" onchange="updateSettleSum()">' : '') . '</td>
            <td>' . htmlspecialchars($o['seller_name']) . '</td>
            <td>' . date('d.m.Y H:i', strtotime($o['order_date'])) . '</td>
            <td>' . htmlspecialchars($o['event_name']) . '<div class="small text-muted">' . date('d.m.Y', strtotime($o['event_date'])) . '</div></td>
            <td class="small">' . htmlspecialchars($seatLabels($o)) . '</td>
            <td>' . ($o['customer'] !== '' ? htmlspecialchars($o['customer']) : '<span class="text-muted">Barverkauf</span>') . '</td>
            <td class="text-end">' . $money($o['total']) . '</td>
            <td>' . ($o['all_settled']
                ? '<span class="badge bg-success">abgerechnet ' . date('d.m.Y', strtotime($o['settled_at'])) . '</span>'
                : ($o['any_settled'] ? '<span class="badge bg-secondary">teilweise</span>' : '<span class="badge bg-warning text-dark">offen</span>')) . '</td>
        </tr>';
}
if ($rowsHtml === '') {
    $rowsHtml = '<tr><td colspan="8" class="text-center text-muted">Keine Barverkäufe für diese Auswahl.</td></tr>';
}

$content .= '
<form method="POST" onsubmit="return confirmSettle()">
    ' . Csrf::field() . '
    <div class="card"><div class="card-body">
        <div class="table-responsive">
            <table class="table table-hover align-middle">
                <thead><tr>
                    <th>' . ($hasOpen ? '<input type="checkbox" class="form-check-input" title="Alle offenen auswählen" onchange="document.querySelectorAll(\'.settle-check\').forEach(c => c.checked = this.checked); updateSettleSum()">' : '') . '</th>
                    <th>Partner</th><th>Verkauft am</th><th>Veranstaltung</th><th>Plätze</th><th>Kunde</th><th class="text-end">Betrag</th><th>Abrechnung</th>
                </tr></thead>
                <tbody>' . $rowsHtml . '</tbody>
                <tfoot><tr><th colspan="6" class="text-end">Summe</th><th class="text-end">' . $money($sum) . '</th><th></th></tr></tfoot>
            </table>
        </div>' . ($hasOpen ? '
        <div class="d-flex align-items-center gap-3 mt-2">
            <button type="submit" class="btn btn-success"><i class="bi bi-check2-all"></i> Ausgewählte als abgerechnet markieren</button>
            <span class="text-muted">Ausgewählt: <strong id="settle-sum">0,00 €</strong></span>
        </div>' : '') . '
    </div></div>
</form>
<script>
function updateSettleSum() {
    var sum = 0;
    document.querySelectorAll(".settle-check:checked").forEach(function (c) { sum += parseFloat(c.dataset.amount); });
    var el = document.getElementById("settle-sum");
    if (el) el.textContent = sum.toFixed(2).replace(".", ",") + " €";
}
function confirmSettle() {
    var n = document.querySelectorAll(".settle-check:checked").length;
    if (n === 0) { alert("Bitte mindestens einen Verkauf auswählen."); return false; }
    return confirm("Den Erhalt des Bargelds für " + n + " Verkauf/Verkäufe (" + document.getElementById("settle-sum").textContent + ") bestätigen?");
}
</script>';

require __DIR__ . '/../../app/views/layouts/main.php';
