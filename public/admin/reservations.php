<?php
require_once __DIR__ . '/../../app/bootstrap.php';

use App\Core\Auth;
use App\Models\Reservation;
use App\Models\Event;
use App\Models\Customer;

Auth::requireAdmin();

$reservationModel = new Reservation();
$eventModel = new Event();
$customerModel = new Customer();

// Filters
$filters = [];
$eventId = (int)($_GET['event_id'] ?? 0);
$eventDate = $_GET['date'] ?? '';
$statusId = (isset($_GET['status_id']) && $_GET['status_id'] !== '') ? (int)$_GET['status_id'] : null;
$customerId = isset($_GET['customer_id']) ? (int)$_GET['customer_id'] : null;
$reservationId = isset($_GET['reservation_id']) ? (int)$_GET['reservation_id'] : null;

if ($eventId > 0) {
    $filters['event_id'] = $eventId;
}
if (!empty($eventDate)) {
    $filters['event_date'] = $eventDate;
}
if ($statusId !== null) {
    $filters['status_id'] = $statusId;
}
if ($customerId !== null && $customerId > 0) {
    $filters['customer_id'] = $customerId;
}
if ($reservationId !== null && $reservationId > 0) {
    $filters['reservation_id'] = $reservationId;
}

// Standard: nur Bestellungen zu aktiven Veranstaltungen. Ältere Bestellungen
// erreicht man über den Filter "Alle Veranstaltungen (inkl. beendete)".
// Bei einer gezielten Suche (Veranstaltung, Kunde, Reservierungs-Nr.) gilt die
// Einschränkung nicht – sonst bliebe das Ergebnis unerwartet leer.
$eventScope = ($_GET['event_scope'] ?? 'active') === 'all' ? 'all' : 'active';
$hasExplicitTarget = $eventId > 0
    || ($customerId !== null && $customerId > 0)
    || ($reservationId !== null && $reservationId > 0);
if ($eventScope === 'active' && !$hasExplicitTarget) {
    $filters['active_events_only'] = true;
}

$reservationModel->cleanupExpiredCartItems();
$reservations = $reservationModel->getGrouped($filters);
$events = $eventModel->getAll();

// Get customer info if filtering by customer
$customer = null;
if ($customerId !== null && $customerId > 0) {
    $customer = $customerModel->getById($customerId);
}

$pageTitle = 'Reservierungen';
$showSidebar = true;
$showHeader = true;

$content = '
<div class="d-flex justify-content-between align-items-center mb-4">
    <h2>Reservierungen' . ($customer ? ' - ' . htmlspecialchars(trim(($customer['first_name'] ?? '') . ' ' . ($customer['last_name'] ?? '')) ?: $customer['email']) : '') . '</h2>
    <div class="d-flex gap-2">
        ' . ($customer ? '<a href="' . url('admin/customers.php') . '" class="btn btn-outline-secondary">
            <i class="bi bi-arrow-left"></i> Zurück zu Kunden
        </a>' : '') . '
        <a href="' . url('admin/sell.php') . '" class="btn btn-primary">
            <i class="bi bi-plus-circle"></i> Neue Reservierung
        </a>
    </div>
</div>';

if ($customer) {
    $content .= '
<div class="alert alert-info mb-4">
    <strong>Kunde:</strong> ' . htmlspecialchars(trim(($customer['first_name'] ?? '') . ' ' . ($customer['last_name'] ?? '')) ?: $customer['email']) . ' 
    (' . htmlspecialchars($customer['email']) . ')
    <a href="' . url('admin/customers_edit.php?id=' . $customer['id']) . '" class="btn btn-sm btn-outline-primary ms-2">
        <i class="bi bi-pencil"></i> Kunde bearbeiten
    </a>
</div>';
}

$content .= '

<div class="card mb-4">
    <div class="card-body">
        <form method="GET" action="" class="row g-3">
            <div class="col-md-3">
                <label for="event_id" class="form-label">Veranstaltung</label>
                <select class="form-select" id="event_id" name="event_id">
                    <option value="">Alle Veranstaltungen</option>';
foreach ($events as $event) {
    $selected = $eventId === (int)$event['id'] ? 'selected' : '';
    $eventLabel = $event['name'] . ($event['active'] ? '' : ' (inaktiv)');
    $content .= '<option value="' . $event['id'] . '" ' . $selected . '>' . htmlspecialchars($eventLabel) . '</option>';
}
$content .= '
                </select>
            </div>
            <div class="col-md-2">
                <label for="event_scope" class="form-label">Zeitraum</label>
                <select class="form-select" id="event_scope" name="event_scope">
                    <option value="active"' . ($eventScope === 'active' ? ' selected' : '') . '>Nur aktive Veranstaltungen</option>
                    <option value="all"' . ($eventScope === 'all' ? ' selected' : '') . '>Alle (inkl. beendete)</option>
                </select>
            </div>
            <div class="col-md-2">
                <label for="date" class="form-label">Datum</label>
                <input type="date" class="form-control" id="date" name="date" value="' . htmlspecialchars($eventDate) . '">
            </div>
            <div class="col-md-3">
                <label for="status_id" class="form-label">Status</label>
                <select class="form-select" id="status_id" name="status_id">
                    <option value="">Alle Status</option>';
// All selectable reservation statuses (same set as reservationStatusOptionsJson,
// driven by reservationStatusLabel() so labels stay consistent everywhere).
foreach ([1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 20, 91] as $sid) {
    $selected = $statusId === $sid ? ' selected' : '';
    $content .= '
                    <option value="' . $sid . '"' . $selected . '>' . htmlspecialchars(reservationStatusLabel($sid)) . '</option>';
}
$content .= '
                </select>
            </div>
            <div class="col-md-2 d-flex align-items-end">
                <button type="submit" class="btn btn-primary w-100">
                    <i class="bi bi-funnel"></i> Filtern
                </button>
            </div>
            ' . (!empty($filters['active_events_only']) ? '
            <div class="col-12 mt-2">
                <div class="alert alert-light border py-2 px-3 mb-0 small">
                    <i class="bi bi-info-circle"></i>
                    Es werden nur Bestellungen zu <strong>aktiven Veranstaltungen</strong> angezeigt.
                    <a href="' . url('admin/reservations.php?event_scope=all') . '">Alle Bestellungen anzeigen (inkl. beendeter Veranstaltungen)</a>
                </div>
            </div>' : '') . '
            <div class="col-md-10 mt-2">
                <label for="customer_filter" class="form-label">Suche (Name oder E-Mail)</label>
                <input type="text" class="form-control" id="customer_filter"
                       placeholder="Vor- / Nachname oder E-Mail..." autocomplete="off"
                       oninput="filterTableByCustomer(this.value)">
            </div>
            <div class="col-md-2 mt-2 d-flex align-items-end">
                <button type="button" class="btn btn-outline-secondary w-100" onclick="clearCustomerFilter()">
                    <i class="bi bi-x-circle"></i> Zurücksetzen
                </button>
            </div>
        </form>
    </div>
</div>

<div class="card">
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-hover">
                <thead>
                    <tr>
                        <th>Veranstaltung</th>
                        <th>Datum</th>
                        <th>Karten</th>
                        <th>Kunde</th>
                        <th>Status</th>
                        <th>Bestelldatum</th>
                        <th>Aktionen</th>
                    </tr>
                </thead>
                <tbody>';

if (empty($reservations)) {
    $content .= '
                    <tr>
                        <td colspan="7" class="text-center text-muted">
                            <i class="bi bi-inbox"></i> Keine Reservierungen gefunden
                        </td>
                    </tr>';
} else {
    foreach ($reservations as $order) {
        $statusId    = (int)$order['status_id'];
        $ticketCount = (int)$order['ticket_count'];

        $statusBadge = reservationStatusBadge($statusId);

        // Barverkauf (no customer) shows a "Barverkauf" badge; only cart items
        // (status 1, customer not yet entered) stay as "-".
        if ($statusId === 1) {
            $customerDisplay = '<span class="text-muted">-</span>';
        } elseif (!$order['customer_id']) {
            $customerDisplay = '<span class="badge bg-secondary">Barverkauf</span>';
        } elseif ($order['first_name'] && $order['last_name']) {
            $customerDisplay = htmlspecialchars($order['first_name'] . ' ' . $order['last_name']);
        } else {
            $customerDisplay = '<span class="text-muted">-</span>';
        }

        $idsJson = htmlspecialchars(json_encode(array_map('intval', explode(',', $order['reservation_ids']))));

        $content .= '
                    <tr data-customer-name="' . htmlspecialchars(($order['first_name'] ?? '') . ' ' . ($order['last_name'] ?? '')) . '" data-customer-email="' . htmlspecialchars($order['email'] ?? '') . '">
                        <td>' . htmlspecialchars($order['event_name'] ?? '-') . '</td>
                        <td>' . date('d.m.Y', strtotime($order['event_date'])) . '</td>
                        <td><span class="badge bg-primary">' . $ticketCount . '</span></td>
                        <td>' . $customerDisplay . '</td>
                        <td>' . $statusBadge . '</td>
                        <td>' . date('d.m.Y H:i', strtotime($order['order_date'])) . '</td>
                        <td>
                            <button class="btn btn-sm btn-outline-primary" onclick="viewReservation(' . $idsJson . ')" title="Details anzeigen">
                                <i class="bi bi-eye"></i>
                            </button>
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

$content .= '
<!-- Order Details Modal -->
<div class="modal fade" id="reservationModal" tabindex="-1" aria-labelledby="reservationModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="reservationModalLabel"><i class="bi bi-ticket-perforated"></i> Bestelldetails</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body" id="reservationModalBody">
                <div class="text-center"><div class="spinner-border" role="status"></div></div>
            </div>
            <div class="modal-footer" id="reservationModalFooter">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Schließen</button>
            </div>
        </div>
    </div>
</div>

<!-- Seatplan Overlay Modal -->
<div class="modal fade" id="seatplanModal" tabindex="-1" aria-labelledby="seatplanModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="seatplanModalLabel"><i class="bi bi-grid"></i> Sitzplan</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-0">
                <iframe id="seatplanIframe" src="about:blank" style="width:100%;height:80vh;border:0;display:block;"></iframe>
            </div>
        </div>
    </div>
</div>
';

$orderDetailsUrl      = json_encode(url('api/order_details.php'));
$adminDeleteUrl       = json_encode(url('api/admin_reservation_delete.php'));
$resendEmailUrl       = json_encode(url('api/reservation_resend_email.php'));
$ticketDownloadUrl    = json_encode(url('api/ticket_download.php'));
$markPaidUrl          = json_encode(url('api/reservation_mark_paid.php'));
$changeStatusUrl      = json_encode(url('api/admin_change_status.php'));
$seatplanViewUrl      = json_encode(url('admin/seatplan_view.php'));
$customerEditUrl      = json_encode(url('admin/customers_edit.php'));
$seatplanDataUrl      = json_encode(url('api/seatplan_data.php'));
$rebookSeatsUrl       = json_encode(url('api/rebook_seats.php'));
$addSeatsUrl          = json_encode(url('api/reservation_add_seats.php'));
$sendToSupportUrl     = json_encode(url('api/order_send_to_support.php'));
$customerSearchUrl    = json_encode(url('api/customer_search.php'));
$linkCustomerUrl      = json_encode(url('api/admin_link_customer.php'));

$additionalJs = '
<script>
const ORDER_DETAILS_URL   = ' . $orderDetailsUrl . ';
const ADMIN_DELETE_URL    = ' . $adminDeleteUrl . ';
const RESEND_EMAIL_URL    = ' . $resendEmailUrl . ';
const TICKET_DOWNLOAD_URL = ' . $ticketDownloadUrl . ';
const MARK_PAID_URL       = ' . $markPaidUrl . ';
const CHANGE_STATUS_URL   = ' . $changeStatusUrl . ';
const SEATPLAN_VIEW_URL   = ' . $seatplanViewUrl . ';
const CUSTOMER_EDIT_URL   = ' . $customerEditUrl . ';
const SEATPLAN_DATA_URL   = ' . $seatplanDataUrl . ';
const REBOOK_SEATS_URL    = ' . $rebookSeatsUrl . ';
const ADD_SEATS_URL       = ' . $addSeatsUrl . ';
const SEND_TO_SUPPORT_URL = ' . $sendToSupportUrl . ';
const CUSTOMER_SEARCH_URL = ' . $customerSearchUrl . ';
const LINK_CUSTOMER_URL   = ' . $linkCustomerUrl . ';

const STATUS_OPTIONS = ' . reservationStatusOptionsJson() . ';

function buildStatusSelect(reservationId, currentStatusId) {
    let opts = \'\';
    for (const [sid, slabel] of STATUS_OPTIONS) {
        const sel = parseInt(currentStatusId) === sid ? \'selected\' : \'\';
        opts += `<option value="${sid}" ${sel}>${slabel}</option>`;
    }
    return `<div class="d-flex gap-1 align-items-center">
        <select id="status-sel-${reservationId}" class="form-select form-select-sm" style="min-width:160px">${opts}</select>
        <button onclick="changeStatus(${reservationId})" class="btn btn-sm btn-outline-primary" title="Übernehmen">
            <i class="bi bi-check-lg"></i>
        </button>
    </div>`;
}

function changeStatus(reservationId) {
    const select = document.getElementById(\'status-sel-\' + reservationId);
    if (!select) return;
    const newStatusId = parseInt(select.value);
    fetch(CHANGE_STATUS_URL, {
        method:  \'POST\',
        headers: { \'Content-Type\': \'application/json\' },
        body:    JSON.stringify({ reservation_id: reservationId, new_status_id: newStatusId })
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            showFeedback(\'Status erfolgreich geändert\', \'success\');
            const row = select.closest(\'tr\');
            if (row) {
                const badgeCell = row.querySelector(\'.status-badge-cell\');
                if (badgeCell) badgeCell.innerHTML = getStatusBadge(newStatusId);
            }
        } else {
            showFeedback(\'Fehler: \' + (data.error || \'Unbekannter Fehler\'), \'error\');
        }
    })
    .catch(err => showFeedback(\'Fehler: \' + err.message, \'error\'));
}

// ── Kunde mit Bestellung verknüpfen ───────────────────────────────────────────
let _linkCustomerSearchTimer = null;

function toggleCustomerLink() {
    const panel = document.getElementById(\'customer-link-panel\');
    if (!panel) return;
    const show = panel.style.display === \'none\';
    panel.style.display = show ? \'block\' : \'none\';
    const input = document.getElementById(\'link-customer-search\');
    if (show && input) {
        input.value = \'\';
        const results = document.getElementById(\'link-customer-results\');
        if (results) { results.innerHTML = \'\'; results.style.display = \'none\'; }
        input.focus();
        if (!input.dataset.bound) {
            input.dataset.bound = \'1\';
            input.addEventListener(\'input\', function() {
                clearTimeout(_linkCustomerSearchTimer);
                _linkCustomerSearchTimer = setTimeout(() => runLinkCustomerSearch(input.value.trim()), 300);
            });
        }
    }
}

function runLinkCustomerSearch(query) {
    const resultsEl = document.getElementById(\'link-customer-results\');
    if (!resultsEl) return;
    if (query.length < 2) { resultsEl.style.display = \'none\'; return; }

    fetch(CUSTOMER_SEARCH_URL + \'?q=\' + encodeURIComponent(query), { credentials: \'same-origin\' })
        .then(r => r.json())
        .then(data => {
            if (!data.success || !data.results || data.results.length === 0) {
                resultsEl.innerHTML = \'<div class="list-group-item small text-muted">Keine Treffer</div>\';
                resultsEl.style.display = \'block\';
                return;
            }
            resultsEl.innerHTML = \'\';
            data.results.forEach(c => {
                const name = (c.first_name + \' \' + c.last_name).trim();
                const btn  = document.createElement(\'button\');
                btn.type = \'button\';
                btn.className = \'list-group-item list-group-item-action py-2\';
                btn.innerHTML = `<strong>${escHtml(name)}</strong>`
                    + (c.email ? ` <small class="text-muted">- ${escHtml(c.email)}</small>` : \'\')
                    + `<br><small class="text-muted">${escHtml([c.street, c.house_number, c.postal_code, c.city].filter(Boolean).join(\' \'))}</small>`;
                btn.addEventListener(\'click\', () => linkCustomerToOrder(c.id, name));
                resultsEl.appendChild(btn);
            });
            resultsEl.style.display = \'block\';
        })
        .catch(() => {});
}

function linkCustomerToOrder(customerId, customerName) {
    if (!currentModalIds || currentModalIds.length === 0) {
        showFeedback(\'Keine Bestellung geladen\', \'error\');
        return;
    }
    if (!confirm(`Kunde \"${customerName}\" mit allen ${currentModalIds.length} Karte(n) dieser Bestellung verknüpfen?`)) return;

    fetch(LINK_CUSTOMER_URL, {
        method:  \'POST\',
        headers: { \'Content-Type\': \'application/json\' },
        credentials: \'same-origin\',
        body:    JSON.stringify({ reservation_ids: currentModalIds, customer_id: customerId })
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            showFeedback(\'Kunde erfolgreich verknüpft\', \'success\');
            const display = document.getElementById(\'modalCustomerDisplay\');
            if (display) display.innerHTML = `<a href="${CUSTOMER_EDIT_URL}?id=${data.customer.id}" target="_blank">${escHtml(customerName)}</a>`;
            const panel = document.getElementById(\'customer-link-panel\');
            if (panel) panel.style.display = \'none\';
            setTimeout(() => location.reload(), 1200);
        } else {
            showFeedback(\'Fehler: \' + (data.error || \'Unbekannter Fehler\'), \'error\');
        }
    })
    .catch(err => showFeedback(\'Fehler: \' + err.message, \'error\'));
}

function showFeedback(message, type = "info") {
    let container = document.getElementById("feedback-container");
    if (!container) {
        container = document.createElement("div");
        container.id = "feedback-container";
        container.style.cssText = "position:fixed;top:70px;right:20px;z-index:9999;max-width:400px;";
        document.body.appendChild(container);
    }
    const cls = { success:"alert-success", error:"alert-danger", warning:"alert-warning", info:"alert-info" }[type] || "alert-info";
    const id  = "alert-" + Date.now();
    container.insertAdjacentHTML("beforeend",
        `<div id="${id}" class="alert ${cls} alert-dismissible fade show" role="alert">
            ${message}
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>`
    );
    setTimeout(() => {
        const el = document.getElementById(id);
        if (el) bootstrap.Alert.getOrCreateInstance(el).close();
    }, 5000);
}

function escHtml(s) {
    if (s == null) return \'\';
    const d = document.createElement(\'div\');
    d.textContent = String(s);
    return d.innerHTML;
}

// Price type of a ticket (from api/order_details.php) as badge; normal price stays plain
function priceTypeBadge(type, fnCardNumber) {
    const colors = { "Ermäßigt": "bg-warning text-dark", "FN-Card": "bg-info text-dark", "Generalprobe": "bg-secondary", "Begleitperson": "bg-light text-dark border" };
    if (!type || !colors[type]) return escHtml(type || "Normal");
    const label = type === "FN-Card" && fnCardNumber ? type + " " + fnCardNumber : type;
    return "<span class=\"badge " + colors[type] + "\">" + escHtml(label) + "</span>";
}

function formatSeatId(id) {
    if (!id) return \'\';
    let m = String(id).match(/^seat_(\d+)_(.+)$/);
    if (m) return \'Reihe \' + m[1] + \', Platz \' + m[2];
    m = String(id).match(/^tableseat_(\d+)_(.+)$/);
    if (m) return \'Tisch \' + m[1] + \', Platz \' + m[2];
    m = String(id).match(/^table_(\d+)$/);
    if (m) return \'Tisch \' + m[1];
    return id;
}

const STATUS_BADGE_MAP = ' . reservationStatusBadgeMapJson() . ';
function getStatusBadge(statusId) {
    return STATUS_BADGE_MAP[statusId] || `<span class="badge bg-secondary">Unbekannt (${statusId})</span>`;
}

let currentModalIds = [];

// ids = array of reservation IDs (numbers)
function viewReservation(ids) {
    currentModalIds = ids.slice();
    const modal     = bootstrap.Modal.getOrCreateInstance(document.getElementById("reservationModal"));
    const modalBody = document.getElementById("reservationModalBody");
    const modalFooter = document.getElementById("reservationModalFooter");
    const dlg = document.querySelector("#reservationModal .modal-dialog");
    if (dlg) { dlg.classList.remove("modal-lg"); dlg.classList.add("modal-xl"); }

    modalBody.innerHTML = \'<div class="text-center"><div class="spinner-border" role="status"><span class="visually-hidden">Lade...</span></div></div>\';
    modalFooter.innerHTML = \'<button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Schließen</button>\';
    modal.show();

    fetch(ORDER_DETAILS_URL + \'?reservation_ids=\' + ids.join(\',\'), { credentials: \'same-origin\' })
        .then(r => r.json())
        .then(data => {
            if (!data.success) throw new Error(data.error || \'Unbekannter Fehler\');

            const reservations = data.reservations;
            const summary      = data.summary;
            const first        = reservations[0];

            // Header info
            const customerName = [first.first_name, first.last_name].filter(Boolean).join(\' \')
                || (first.status_id === 1 ? \'Im Warenkorb\' : \'Barverkauf\');
            const eventName    = first.event_name || \'-\';
            const eventDate    = first.event_date ? new Date(first.event_date).toLocaleDateString(\'de-DE\') : \'-\';
            const email        = first.email || \'\';

            // Money formatter (de-DE, two decimals)
            const fmtEur = (n) => (Number(n) || 0).toFixed(2).replace(\'.\', \',\') + \' €\';

            // Ticket rows
            let rows = \'\';
            for (const r of reservations) {
                const seatDisplay = r.seat_display_name || r.seat_identifier || \'-\';
                const priceCell = parseInt(r.is_companion)
                    ? \'0,00 € <span class="text-muted">(B)</span>\'
                    : fmtEur(r.ticket_price);
                rows += `
                    <tr id="res-row-${r.id}" data-ticket-price="${parseInt(r.is_companion) ? 0 : (Number(r.ticket_price) || 0)}">
                        <td>${escHtml(seatDisplay)}${parseInt(r.is_companion) ? \' <span class="badge bg-info">Begleitperson kostenfrei</span>\' : \'\'}</td>
                        <td>${escHtml(r.category_name || \'-\')}${r.category_sign ? \' (\' + escHtml(r.category_sign) + \')\' : \'\'}</td>
                        <td>${priceTypeBadge(r.price_type, r.fn_card_number)}</td>
                        <td class="text-end">${priceCell}</td>
                        <td class="status-badge-cell">${getStatusBadge(parseInt(r.status_id))}</td>
                        <td>${buildStatusSelect(r.id, r.status_id)}</td>
                        <td><button class="btn btn-sm btn-outline-danger" onclick="deleteSingleReservation(${r.id})" title="Karte freigeben"><i class="bi bi-trash"></i></button></td>
                    </tr>`;
            }

            const voucherCodes = data.summary.voucher_codes || [];
            const voucherHtml = voucherCodes.length
                ? voucherCodes.map(c => `<code class="me-1">${escHtml(c)}</code>`).join(\' \')
                : \'<span class="text-muted">–</span>\';

            modalBody.innerHTML = `
                <div class="row mb-3">
                    <div class="col-md-6">
                        <strong>Kunde:</strong>
                        <button type="button" class="btn btn-sm btn-outline-primary ms-2" onclick="toggleCustomerLink()" title="Kunde verknüpfen oder ändern">
                            <i class="bi bi-person-lines-fill"></i> Verknüpfen/Ändern
                        </button>
                        <br>
                        <span id="modalCustomerDisplay">${first.customer_id ? `<a href="${CUSTOMER_EDIT_URL}?id=${first.customer_id}" target="_blank">${escHtml(customerName)}</a>` : `<span class="text-muted">${escHtml(customerName)}</span>`}</span>
                        <div id="customer-link-panel" class="mt-2 position-relative" style="display:none;">
                            <input type="text" class="form-control form-control-sm" id="link-customer-search" placeholder="Name oder E-Mail eingeben..." autocomplete="off">
                            <div id="link-customer-results" class="list-group shadow-sm position-absolute w-100" style="z-index:1060; display:none; max-height:280px; overflow-y:auto;"></div>
                        </div>
                    </div>
                    <div class="col-md-6"><strong>E-Mail:</strong><br><span class="text-muted">${escHtml(email) || \'<em>–</em>\'}</span></div>
                </div>
                <div class="row mb-3">
                    <div class="col-md-6"><strong>Veranstaltung:</strong><br><span class="text-muted">${escHtml(eventName)}</span></div>
                    <div class="col-md-6"><strong>Datum:</strong><br><span class="text-muted">${escHtml(eventDate)}</span></div>
                </div>
                ${voucherCodes.length ? `<div class="row mb-3"><div class="col-12"><strong><i class="bi bi-gift"></i> Verwendeter Gutschein:</strong><br>${voucherHtml}</div></div>` : \'\'}
                <hr>
                <div class="table-responsive">
                <table class="table table-sm table-bordered align-middle">
                    <thead class="table-light">
                        <tr><th>Platz</th><th>Kategorie</th><th>Preisart</th><th class="text-end">Preis</th><th>Status</th><th>Status ändern</th><th></th></tr>
                    </thead>
                    <tbody>${rows}</tbody>
                    <tfoot>
                        <tr class="table-light fw-bold">
                            <td colspan="3" class="text-end">Endsumme:</td>
                            <td class="text-end" id="modalOrderTotal">${fmtEur(summary.total)}</td>
                            <td colspan="3"></td>
                        </tr>
                    </tfoot>
                </table>
                ${reservations.some(r => parseInt(r.is_companion)) ? \'<p class="small text-muted mb-0"><span class="badge bg-info">Begleitperson kostenfrei</span> = Begleitperson des Rollstuhlfahrers, zahlt nichts (0,00 €).</p>\' : \'\'}
                </div>`;

            // Footer buttons
            const idsJson    = JSON.stringify(ids);
            const idsParam   = ids.join(\',\');
            const anyReserved = data.summary.any_reserved;
            const allPaid     = data.summary.all_paid;
            let footerHtml   = \'<button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Schließen</button>\';

            if (email) {
                footerHtml += `
                    <button class="btn btn-outline-secondary" onclick="resendEmailGroup(${idsJson})">
                        <i class="bi bi-envelope"></i> Mail senden
                    </button>`;
            }

            footerHtml += `
                    <button class="btn btn-outline-secondary" onclick="sendOrderToSupport(${first.id})" title="Prüfkopie der Bestätigungs-Mail (inkl. Ticket-Link) an die Support-Adresse senden">
                        <i class="bi bi-shield-check"></i> Prüfkopie an Support
                    </button>`;

            footerHtml += `
                    <button class="btn btn-outline-danger" onclick="deleteGroup(${idsJson})">
                        <i class="bi bi-trash"></i> Alle löschen
                    </button>`;
            if (anyReserved) {
                footerHtml += `
                    <button class="btn btn-success" onclick="markGroupAsPaidAndPrint(${idsJson}, \'${idsParam}\')">
                        <i class="bi bi-check-circle"></i> Tickets sind bezahlt, jetzt drucken
                    </button>`;
            }

            if (allPaid) {
                footerHtml += `
                    <a href="${TICKET_DOWNLOAD_URL}?reservation_ids=${idsParam}" class="btn btn-outline-success" target="_blank">
                        <i class="bi bi-printer"></i> Erneut drucken (A4 / Kunde)
                    </a>
                    <a href="${TICKET_DOWNLOAD_URL}?reservation_ids=${idsParam}&scope=presales" class="btn btn-outline-success" target="_blank">
                        <i class="bi bi-printer"></i> Erneut drucken (Vorverkauf-Template)
                    </a>`;
            }

            // Sitzplan-Overlay (Modal mit iframe) nur bei Sitzplan-Veranstaltungen
            if (first.seat_identifier && first.event_id && first.event_date) {
                const seatplanDate = first.event_date.substring(0, 10);
                window._seatplanOverlay = {
                    url: `${SEATPLAN_VIEW_URL}?event_id=${first.event_id}&date=${seatplanDate}&reservation_ids=${idsParam}&embed=1`,
                    title: (first.event_name || \'Sitzplan\') + \' – \' + new Date(seatplanDate).toLocaleDateString(\'de-DE\'),
                };
                footerHtml += `
                    <button class="btn btn-outline-info" onclick="openSeatplanOverlay(window._seatplanOverlay.url, window._seatplanOverlay.title)">
                        <i class="bi bi-grid"></i> Im Sitzplan anzeigen
                    </button>`;
            }

            // Umbuchen-Button (nur wenn mindestens ein Ticket aktiv: status >= 2 und != 7)
            const rebookableRes = reservations.filter(r => { const s = parseInt(r.status_id); return s >= 2 && s !== 7; });
            if (rebookableRes.length > 0 && first.event_id) {
                window._rebookData = rebookableRes.map(r => ({id: r.id, seat: r.seat_identifier, seat_display: r.seat_display_name || r.seat_identifier, date: r.event_date, category_id: r.category_id, category_name: r.category_name}));
                window._rebookEventId = first.event_id;
                footerHtml += `
                    <button class="btn btn-outline-warning" onclick="startRebook(window._rebookData, window._rebookEventId)">
                        <i class="bi bi-arrow-left-right"></i> Umbuchen
                    </button>`;
            }

            // Plätze hinzufügen — nur bei offener Reservierung (3,6) oder VIP (11)
            const addableAnchor = reservations.find(r => { const s = parseInt(r.status_id); return s === 3 || s === 6 || s === 11; });
            if (addableAnchor && first.event_id && first.seat_identifier) {
                const sameOrderSeats = reservations
                    .filter(r => r.event_date === addableAnchor.event_date && r.seat_identifier)
                    .map(r => r.seat_identifier);
                window._addSeatsAnchor = {
                    id: addableAnchor.id,
                    event_id: first.event_id,
                    event_date: addableAnchor.event_date,
                    status_id: parseInt(addableAnchor.status_id),
                    own_seats: sameOrderSeats,
                };
                footerHtml += `
                    <button class="btn btn-outline-primary" onclick="startAddSeats(window._addSeatsAnchor)">
                        <i class="bi bi-plus-circle"></i> Plätze hinzufügen
                    </button>`;
            }

            modalFooter.innerHTML = footerHtml;
        })
        .catch(err => {
            modalBody.innerHTML = `<div class="alert alert-danger">Fehler: ${escHtml(err.message)}</div>`;
        });
}

function deleteGroup(ids) {
    if (!confirm(ids.length + \' Reservierung(en) wirklich löschen?\')) return;

    fetch(ADMIN_DELETE_URL, {
        method:  \'POST\',
        headers: { \'Content-Type\': \'application/json\' },
        body:    JSON.stringify({ reservation_ids: ids })
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            bootstrap.Modal.getInstance(document.getElementById(\'reservationModal\'))?.hide();
            showFeedback(data.message, \'success\');
            setTimeout(() => location.reload(), 1000);
        } else {
            showFeedback(\'Fehler: \' + (data.error || \'Unbekannter Fehler\'), \'error\');
        }
    })
    .catch(err => showFeedback(\'Fehler: \' + err.message, \'error\'));
}

function deleteSingleReservation(id) {
    if (!confirm(\'Diese Karte wirklich löschen und den Platz freigeben?\')) return;

    fetch(ADMIN_DELETE_URL, {
        method:  \'POST\',
        headers: { \'Content-Type\': \'application/json\' },
        body:    JSON.stringify({ reservation_ids: [id] })
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            currentModalIds = currentModalIds.filter(i => i !== id);
            showFeedback(\'Karte freigegeben\', \'success\');
            if (currentModalIds.length === 0) {
                bootstrap.Modal.getInstance(document.getElementById(\'reservationModal\'))?.hide();
                setTimeout(() => location.reload(), 800);
            } else {
                // Modal mit den verbliebenen Karten neu aufbauen, damit Kartenanzahl,
                // Endsumme und die Footer-Buttons ("Tickets sind bezahlt", Drucken …)
                // die aktuell verbliebenen Reservierungen verwenden.
                viewReservation(currentModalIds);
            }
        } else {
            showFeedback(\'Fehler: \' + (data.error || \'Unbekannter Fehler\'), \'error\');
        }
    })
    .catch(err => showFeedback(\'Fehler: \' + err.message, \'error\'));
}

function markGroupAsPaidAndPrint(ids, idsParam) {
    if (!confirm(\'Alle \' + ids.length + \' Ticket(s) als bezahlt/abgeholt markieren und Tickets öffnen?\')) return;
    fetch(MARK_PAID_URL, {
        method:  \'POST\',
        headers: { \'Content-Type\': \'application/json\' },
        body:    JSON.stringify({ reservation_ids: ids })
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            bootstrap.Modal.getInstance(document.getElementById(\'reservationModal\'))?.hide();
            window.open(TICKET_DOWNLOAD_URL + \'?reservation_ids=\' + idsParam, \'_blank\');
            showFeedback(data.message || \'Tickets als bezahlt markiert\', \'success\');
            setTimeout(() => location.reload(), 1500);
        } else {
            showFeedback(\'Fehler: \' + (data.error || \'Unbekannter Fehler\'), \'error\');
        }
    })
    .catch(err => showFeedback(\'Fehler: \' + err.message, \'error\'));
}

function filterTableByCustomer(query) {
    const q = query.trim().toLowerCase();
    document.querySelectorAll(\'table tbody tr\').forEach(function(row) {
        const name  = (row.dataset.customerName  || \'\').toLowerCase();
        const email = (row.dataset.customerEmail || \'\').toLowerCase();
        row.style.display = (q === \'\' || name.includes(q) || email.includes(q)) ? \'\' : \'none\';
    });
}

function clearCustomerFilter() {
    const input = document.getElementById(\'customer_filter\');
    if (input) { input.value = \'\'; filterTableByCustomer(\'\'); }
}

function resendEmailGroup(ids) {
    if (!confirm(\'Reservierungs-Mail erneut an den Kunden senden?\')) return;

    fetch(RESEND_EMAIL_URL, {
        method:  \'POST\',
        headers: { \'Content-Type\': \'application/json\' },
        body:    JSON.stringify({ reservation_id: ids[0] })
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) showFeedback(data.message || \'Mail gesendet\', \'success\');
        else showFeedback(\'Fehler: \' + (data.error || \'Unbekannter Fehler\'), \'error\');
    })
    .catch(err => showFeedback(\'Fehler: \' + err.message, \'error\'));
}

function sendOrderToSupport(reservationId) {
    if (!confirm(\'Prüfkopie dieser Bestätigungs-Mail (inkl. Ticket-Link) an die Support-Adresse senden?\')) return;

    fetch(SEND_TO_SUPPORT_URL, {
        method:  \'POST\',
        headers: { \'Content-Type\': \'application/json\' },
        body:    JSON.stringify({ reservation_id: reservationId })
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) showFeedback(data.message || \'Prüfkopie gesendet\', \'success\');
        else showFeedback(\'Fehler: \' + (data.error || \'Unbekannter Fehler\'), \'error\');
    })
    .catch(err => showFeedback(\'Fehler: \' + err.message, \'error\'));
}

function openSeatplanOverlay(url, title) {
    const iframe = document.getElementById(\'seatplanIframe\');
    const label  = document.getElementById(\'seatplanModalLabel\');
    if (label) label.innerHTML = \'<i class="bi bi-grid"></i> \' + escHtml(title || \'Sitzplan\');
    if (iframe) iframe.src = url;
    bootstrap.Modal.getOrCreateInstance(document.getElementById(\'seatplanModal\')).show();
}

document.addEventListener(\'DOMContentLoaded\', () => {
    const modalEl = document.getElementById(\'seatplanModal\');
    if (!modalEl) return;
    modalEl.addEventListener(\'hidden.bs.modal\', () => {
        const iframe = document.getElementById(\'seatplanIframe\');
        if (iframe) iframe.src = \'about:blank\';
    });
});

// ============================================================
// REBOOK (Umbuchung) functionality
// ============================================================

let rebookState = {
    reservations: [],
    selected: [],
    eventId: 0,
    eventDates: [],
    seatplanData: null,
    categories: [],
    targetDate: \'\',
    targetSeats: {},
    useSeatPlan: true,
    savedModalBody: \'\',
    savedModalFooter: \'\',
};

function startRebook(reservations, eventId) {
    rebookState.reservations = reservations;
    rebookState.eventId = eventId;
    rebookState.selected = [];
    rebookState.targetSeats = {};

    const modalBody = document.getElementById("reservationModalBody");
    const modalFooter = document.getElementById("reservationModalFooter");
    rebookState.savedModalBody = modalBody.innerHTML;
    rebookState.savedModalFooter = modalFooter.innerHTML;

    let html = \'<div class="alert alert-info py-2"><i class="bi bi-arrow-left-right me-2"></i><strong>Umbuchung:</strong> Wählen Sie die Plätze aus, die umgebucht werden sollen.</div>\';
    html += \'<div class="table-responsive"><table class="table table-sm table-bordered"><thead class="table-light"><tr><th style="width:40px"><input type="checkbox" id="rebook-select-all" onchange="rebookToggleAll(this)"></th><th>Platz</th><th>Kategorie</th><th>Datum</th></tr></thead><tbody>\';

    for (const r of reservations) {
        const dateFormatted = new Date(r.date).toLocaleDateString(\'de-DE\');
        html += `<tr>
            <td><input type="checkbox" class="rebook-cb" value="${r.id}" onchange="rebookUpdateSelection()"></td>
            <td>${escHtml(r.seat_display || r.seat)}</td>
            <td>${escHtml(r.category_name || \'-\')}</td>
            <td>${escHtml(dateFormatted)}</td>
        </tr>`;
    }
    html += \'</tbody></table></div>\';
    modalBody.innerHTML = html;

    modalFooter.innerHTML = `
        <button class="btn btn-secondary" onclick="cancelRebook()">Abbrechen</button>
        <button class="btn btn-primary" id="rebook-next-btn" disabled onclick="rebookStep2()">
            Weiter <i class="bi bi-arrow-right"></i>
        </button>`;
}

function rebookToggleAll(cb) {
    document.querySelectorAll(\'.rebook-cb\').forEach(c => { c.checked = cb.checked; });
    rebookUpdateSelection();
}

function rebookUpdateSelection() {
    rebookState.selected = Array.from(document.querySelectorAll(\'.rebook-cb:checked\')).map(c => parseInt(c.value));
    const btn = document.getElementById(\'rebook-next-btn\');
    if (btn) btn.disabled = rebookState.selected.length === 0;
}

function cancelRebook() {
    document.getElementById("reservationModalBody").innerHTML = rebookState.savedModalBody;
    document.getElementById("reservationModalFooter").innerHTML = rebookState.savedModalFooter;
    const dlg = document.querySelector("#reservationModal .modal-dialog");
    if (dlg) { dlg.classList.remove("modal-lg"); dlg.classList.add("modal-xl"); }
}

function rebookStep2() {
    const modalBody = document.getElementById("reservationModalBody");
    const modalFooter = document.getElementById("reservationModalFooter");
    const selectedRes = rebookState.reservations.filter(r => rebookState.selected.includes(r.id));
    const requiredCategories = [...new Set(selectedRes.map(r => parseInt(r.category_id)))];
    rebookState.targetSeats = {};

    const dlg = document.querySelector("#reservationModal .modal-dialog");
    if (dlg) { dlg.classList.remove("modal-lg"); dlg.classList.add("modal-xl"); }

    const currentDate = selectedRes[0].date.substring(0, 10);
    rebookState.targetDate = currentDate;

    modalBody.innerHTML = \'<div class="text-center py-4"><div class="spinner-border" role="status"></div><p class="mt-2">Sitzplan wird geladen...</p></div>\';
    modalFooter.innerHTML = `
        <div class="form-check me-auto d-flex align-items-center">
            <input class="form-check-input mt-0 me-2" type="checkbox" id="rebook-notify" checked>
            <label class="form-check-label small" for="rebook-notify">Kunde per E-Mail benachrichtigen</label>
        </div>
        <button class="btn btn-secondary" onclick="cancelRebook()">Abbrechen</button>
        <button class="btn btn-warning" id="rebook-confirm-btn" disabled onclick="rebookConfirm()">
            <i class="bi bi-check-lg"></i> Umbuchen bestätigen
        </button>`;

    fetchSeatplanAndRender(currentDate, selectedRes, requiredCategories);
}

function fetchSeatplanAndRender(date, selectedRes, requiredCategories) {
    rebookState.targetDate = date;
    rebookState.targetSeats = {};

    fetch(SEATPLAN_DATA_URL + \'?event_id=\' + rebookState.eventId + \'&date=\' + date, { credentials: \'same-origin\' })
        .then(r => r.json())
        .then(data => {
            if (!data.success) throw new Error(data.error || \'Fehler beim Laden\');
            rebookState.seatplanData = data.seatplan;
            rebookState.categories = data.categories;
            rebookState.eventDates = data.event_dates;
            rebookState.useSeatPlan = data.use_seat_plan;

            const reservedSeats = data.reserved_seats || {};
            const currentSeatsOnThisDate = selectedRes
                .filter(r => r.date.substring(0, 10) === date)
                .map(r => r.seat);

            renderRebookUI(data, selectedRes, requiredCategories, reservedSeats, currentSeatsOnThisDate, date);
        })
        .catch(err => {
            document.getElementById("reservationModalBody").innerHTML =
                `<div class="alert alert-danger">Fehler: ${escHtml(err.message)}</div>`;
        });
}

function renderRebookUI(data, selectedRes, requiredCategories, reservedSeats, freeOverrides, currentDate) {
    const modalBody = document.getElementById("reservationModalBody");
    let html = \'\';

    html += \'<div class="mb-3"><label class="form-label fw-bold">Ziel-Datum:</label><select class="form-select" id="rebook-date-select" onchange="rebookDateChanged()">\';
    for (const d of data.event_dates) {
        const selected = d.date === currentDate ? \'selected\' : \'\';
        const label = new Date(d.date).toLocaleDateString(\'de-DE\', {weekday: \'short\', day: \'2-digit\', month: \'2-digit\', year: \'numeric\'});
        const gpLabel = d.is_general_rehearsal ? \' (Generalprobe)\' : \'\';
        html += `<option value="${d.date}" ${selected}>${label}${gpLabel}</option>`;
    }
    html += \'</select></div>\';

    html += \'<div class="alert alert-info py-2 small mb-3"><i class="bi bi-info-circle me-1"></i> <strong>Hinweis:</strong> Bei Umbuchung auf ein anderes Datum wird die Bestellung in zwei Teile aufgeteilt — die umgebuchten Plätze erscheinen als eigene Bestellung beim neuen Datum. Beide Teile sind über den Kundennamen in der Reservierungs-Übersicht auffindbar.</div>\';

    html += \'<div class="alert alert-secondary py-2 small mb-3"><strong>Umzubuchende Plätze:</strong> \';
    html += selectedRes.map(r => escHtml(r.seat_display || r.seat)).join(\', \');
    html += \'</div>\';

    if (data.use_seat_plan && data.seatplan && data.seatplan.length > 0) {
        const catColors = {};
        for (const cat of data.categories) { catColors[cat.id] = cat.color || \'#0d6efd\'; }

        html += \'<div style="background:#f8f9fa;padding:1rem;border-radius:0.5rem;overflow-x:auto;">\';

        for (const row of data.seatplan) {
            if (row.type !== \'row\') continue;
            if (row.no === 0) {
                html += \'<div style="display:flex;align-items:center;margin-bottom:0.5rem;width:100%;"><div style="width:100%;flex:1;background:#212529;color:white;text-align:center;padding:0.5rem;border-radius:0.2rem;font-weight:bold;"><i class="bi bi-music-note-beamed"></i> BÜHNE</div></div>\';
                continue;
            }

            const rowCat = row.category;
            const displayName = row.row_name || (\'Reihe \' + row.no);
            html += `<div style="display:flex;align-items:center;margin-bottom:0.5rem;gap:0.25rem;"><div style="min-width:60px;font-weight:bold;text-align:right;font-size:0.85rem;">${escHtml(displayName)}</div><div style="display:flex;flex-wrap:nowrap;gap:0.25rem;flex:1;">`;

            for (const el of row.content) {
                if (el.type === \'seat\') {
                    const seatId = \'seat_\' + row.no + \'_\' + el.no;
                    const catId = el.category || rowCat;
                    const isReserved = (seatId in reservedSeats) && !freeOverrides.includes(seatId);
                    const isAllowed = requiredCategories.includes(parseInt(catId));
                    const color = catColors[catId] || \'#0d6efd\';
                    html += renderRebookSeat(seatId, el.no, catId, color, isReserved, isAllowed);

                } else if (el.type === \'table\') {
                    html += renderRebookTable(el, row.no, rowCat, catColors, reservedSeats, freeOverrides, requiredCategories);

                } else if (el.type === \'distance\' || el.type === \'spacer\') {
                    html += `<span ${el.stairs ? "title=\"Treppe\" " : ""}style="${el.stairs ? "background:#fff;color:#000;border:2px solid #000;" : "background:#6c757d;color:white;border:1px solid #6c757d;"}display:inline-block;box-sizing:border-box;width:2.5rem;min-width:2.5rem;flex:0 0 2.5rem;padding:0.25rem 0;border-radius:0.2rem;font-size:0.75rem;text-align:center;cursor:not-allowed;">${el.no || \'\'}</span>`;
                }
            }
            html += \'</div></div>\';
        }
        html += \'</div>\';

        html += \'<div class="mt-2 d-flex flex-wrap gap-3 small">\';
        html += \'<span><span style="display:inline-block;width:16px;height:16px;background:#28a745;border-radius:2px;vertical-align:text-bottom;"></span> Verfügbar</span>\';
        html += \'<span><span style="display:inline-block;width:16px;height:16px;background:#adb5bd;border-radius:2px;vertical-align:text-bottom;"></span> Andere Kategorie</span>\';
        html += \'<span><span style="display:inline-block;width:16px;height:16px;background:#dc3545;border-radius:2px;vertical-align:text-bottom;"></span> Belegt</span>\';
        html += \'<span><span style="display:inline-block;width:16px;height:16px;background:#0d6efd;border-radius:2px;vertical-align:text-bottom;"></span> Ausgewählt</span>\';
        html += \'</div>\';

    } else {
        html += \'<div class="alert alert-info py-2"><i class="bi bi-info-circle me-1"></i>Veranstaltung ohne Sitzplan. Wählen Sie oben das neue Datum.</div>\';
        for (const r of selectedRes) {
            rebookState.targetSeats[r.id] = \'no_seatplan_rebook_\' + r.id + \'_\' + Date.now();
        }
    }

    html += \'<div id="rebook-mapping" class="mt-3"></div>\';
    modalBody.innerHTML = html;
    rebookUpdateConfirmBtn(selectedRes.length);
    rebookUpdateMapping(selectedRes);
}

function renderRebookSeat(seatId, seatNo, catId, color, isReserved, isAllowed) {
    let bgColor, cursor, onclick, title;
    if (isReserved) {
        bgColor = \'#dc3545\'; cursor = \'not-allowed\'; onclick = \'\'; title = \'Belegt\';
    } else if (!isAllowed) {
        bgColor = \'#adb5bd\'; cursor = \'not-allowed\'; onclick = \'\'; title = \'Andere Kategorie\';
    } else {
        bgColor = \'#28a745\'; cursor = \'pointer\'; onclick = `onclick="rebookToggleSeat(this,\'${seatId}\',${catId})"`; title = \'Verfügbar\';
    }
    return `<span class="rebook-seat" data-seat-id="${seatId}" data-category="${catId}" style="background:${bgColor};color:white;border:2px solid ${color};display:inline-block;box-sizing:border-box;width:2.5rem;min-width:2.5rem;flex:0 0 2.5rem;padding:0.25rem 0;border-radius:0.2rem;font-size:0.75rem;text-align:center;cursor:${cursor};user-select:none;" ${onclick} title="${title}">${seatNo}</span>`;
}

function renderRebookTable(el, rowNo, rowCategory, catColors, reservedSeats, freeOverrides, requiredCategories) {
    const tableSeats = el.seats || {};
    let html = \'<div style="display:inline-flex;flex-direction:column;align-items:center;gap:0.25rem;">\';

    const renderArea = (seats, style) => {
        if (!seats || seats.length === 0) return \'\';
        let h = `<div style="${style}">`;
        for (const seat of seats) {
            const seatId = \'tableseat_\' + el.no + \'_\' + seat.number;
            const catId = seat.category || rowCategory;
            const isReserved = (seatId in reservedSeats) && !freeOverrides.includes(seatId);
            const isAllowed = requiredCategories.includes(parseInt(catId));
            const color = catColors[catId] || \'#0d6efd\';
            h += renderRebookSeat(seatId, seat.number, catId, color, isReserved, isAllowed);
        }
        h += \'</div>\';
        return h;
    };

    html += renderArea(tableSeats.front, \'display:flex;gap:0.25rem;\');
    html += \'<div style="display:flex;align-items:center;gap:0.25rem;">\';
    html += renderArea(tableSeats.left, \'display:flex;flex-direction:column;gap:0.25rem;\');
    html += `<span style="font-size:0.9rem;padding:0.5rem;background:#6c757d;color:white;border:1px solid #5a6268;border-radius:0.2rem;text-align:center;">T${el.no}</span>`;
    html += renderArea(tableSeats.right, \'display:flex;flex-direction:column;gap:0.25rem;\');
    html += \'</div>\';
    html += renderArea(tableSeats.back, \'display:flex;gap:0.25rem;\');
    html += \'</div>\';
    return html;
}

function rebookToggleSeat(el, seatId, categoryId) {
    const selectedRes = rebookState.reservations.filter(r => rebookState.selected.includes(r.id));
    const currentlySelected = Object.values(rebookState.targetSeats);

    if (currentlySelected.includes(seatId)) {
        for (const [resId, seat] of Object.entries(rebookState.targetSeats)) {
            if (seat === seatId) { delete rebookState.targetSeats[resId]; break; }
        }
        el.style.backgroundColor = \'#28a745\';
    } else {
        const unassigned = selectedRes.filter(r => !rebookState.targetSeats[r.id]);
        if (unassigned.length === 0) return;
        rebookState.targetSeats[unassigned[0].id] = seatId;
        el.style.backgroundColor = \'#0d6efd\';
    }

    rebookUpdateConfirmBtn(selectedRes.length);
    rebookUpdateMapping(selectedRes);
}

function rebookUpdateConfirmBtn(neededCount) {
    const btn = document.getElementById(\'rebook-confirm-btn\');
    if (!btn) return;
    if (!rebookState.useSeatPlan) { btn.disabled = false; return; }
    btn.disabled = Object.keys(rebookState.targetSeats).length < neededCount;
}

function rebookUpdateMapping(selectedRes) {
    const container = document.getElementById(\'rebook-mapping\');
    if (!container) return;

    const entries = Object.entries(rebookState.targetSeats);
    if (entries.length === 0 && rebookState.useSeatPlan) {
        container.innerHTML = \'<p class="text-muted small">Klicken Sie auf grüne Plätze, um die neuen Plätze zuzuweisen.</p>\';
        return;
    }

    let html = \'<table class="table table-sm table-bordered mb-0"><thead class="table-light"><tr><th>Aktueller Platz</th><th></th><th>Neuer Platz</th></tr></thead><tbody>\';
    for (const r of selectedRes) {
        const newSeat = rebookState.targetSeats[r.id];
        html += `<tr>
            <td>${escHtml(r.seat_display || r.seat)}</td>
            <td class="text-center"><i class="bi bi-arrow-right"></i></td>
            <td>${newSeat ? escHtml(formatSeatId(newSeat)) : \'<span class="text-muted">— noch nicht gewählt —</span>\'}</td>
        </tr>`;
    }
    html += \'</tbody></table>\';
    container.innerHTML = html;
}

function rebookDateChanged() {
    const newDate = document.getElementById(\'rebook-date-select\').value;
    const selectedRes = rebookState.reservations.filter(r => rebookState.selected.includes(r.id));
    const requiredCategories = [...new Set(selectedRes.map(r => parseInt(r.category_id)))];

    document.getElementById("reservationModalBody").innerHTML = \'<div class="text-center py-4"><div class="spinner-border" role="status"></div><p class="mt-2">Sitzplan wird geladen...</p></div>\';
    fetchSeatplanAndRender(newDate, selectedRes, requiredCategories);
}

function rebookConfirm() {
    const selectedRes = rebookState.reservations.filter(r => rebookState.selected.includes(r.id));
    const rebookings = selectedRes.map(r => ({
        reservation_id: r.id,
        new_seat_identifier: rebookState.targetSeats[r.id] || r.seat,
        new_event_date: rebookState.targetDate,
    }));

    const hasChange = rebookings.some(rb => {
        const orig = selectedRes.find(r => r.id === rb.reservation_id);
        return rb.new_seat_identifier !== orig.seat || rb.new_event_date !== orig.date.substring(0, 10);
    });
    if (!hasChange) {
        showFeedback(\'Keine Änderung — Plätze und Datum sind identisch.\', \'warning\');
        return;
    }

    if (!confirm(rebookings.length + \' Platz/Plätze umbuchen?\')) return;

    const notifyCustomer = document.getElementById(\'rebook-notify\')?.checked === true;

    const btn = document.getElementById(\'rebook-confirm-btn\');
    if (btn) { btn.disabled = true; btn.innerHTML = \'<span class="spinner-border spinner-border-sm"></span> Wird umgebucht...\'; }

    fetch(REBOOK_SEATS_URL, {
        method: \'POST\',
        headers: { \'Content-Type\': \'application/json\' },
        body: JSON.stringify({ rebookings: rebookings, notify_customer: notifyCustomer })
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            bootstrap.Modal.getInstance(document.getElementById(\'reservationModal\'))?.hide();
            showFeedback(data.message || \'Umbuchung erfolgreich\', \'success\');
            setTimeout(() => location.reload(), 1200);
        } else {
            showFeedback(\'Fehler: \' + (data.error || \'Unbekannter Fehler\'), \'error\');
            if (btn) { btn.disabled = false; btn.innerHTML = \'<i class="bi bi-check-lg"></i> Umbuchen bestätigen\'; }
        }
    })
    .catch(err => {
        showFeedback(\'Fehler: \' + err.message, \'error\');
        if (btn) { btn.disabled = false; btn.innerHTML = \'<i class="bi bi-check-lg"></i> Umbuchen bestätigen\'; }
    });
}

// ============================================================
// ADD SEATS (Plätze hinzufügen) functionality
// ============================================================

let addSeatsState = {
    anchorId: 0,
    eventId: 0,
    eventDate: \'\',
    statusId: 0,
    selectedSeats: [],
    categoryColors: {},
    ownSeats: [],
};

function startAddSeats(anchor) {
    addSeatsState.anchorId  = anchor.id;
    addSeatsState.eventId   = anchor.event_id;
    addSeatsState.eventDate = (anchor.event_date || \'\').substring(0, 10);
    addSeatsState.statusId  = anchor.status_id;
    addSeatsState.selectedSeats = [];
    addSeatsState.ownSeats = anchor.own_seats || [];

    const dlg = document.querySelector("#reservationModal .modal-dialog");
    if (dlg) { dlg.classList.remove("modal-lg"); dlg.classList.add("modal-xl"); }

    const modalBody   = document.getElementById("reservationModalBody");
    const modalFooter = document.getElementById("reservationModalFooter");
    modalBody.innerHTML = \'<div class="text-center py-4"><div class="spinner-border" role="status"></div><p class="mt-2">Sitzplan wird geladen...</p></div>\';
    modalFooter.innerHTML = `
        <button class="btn btn-secondary" onclick="cancelAddSeats()">Abbrechen</button>
        <button class="btn btn-success" id="addseats-confirm-btn" disabled onclick="confirmAddSeats()">
            <i class="bi bi-check-lg"></i> Hinzufügen bestätigen
        </button>`;

    fetch(SEATPLAN_DATA_URL + \'?event_id=\' + addSeatsState.eventId + \'&date=\' + addSeatsState.eventDate, { credentials: \'same-origin\' })
        .then(r => r.json())
        .then(data => {
            if (!data.success) throw new Error(data.error || \'Sitzplan konnte nicht geladen werden\');
            renderAddSeatsUI(data);
        })
        .catch(err => {
            modalBody.innerHTML = `<div class="alert alert-danger">Fehler: ${escHtml(err.message)}</div>`;
        });
}

function cancelAddSeats() {
    bootstrap.Modal.getInstance(document.getElementById(\'reservationModal\'))?.hide();
    const dlg = document.querySelector("#reservationModal .modal-dialog");
    if (dlg) { dlg.classList.remove("modal-lg"); dlg.classList.add("modal-xl"); }
}

function renderAddSeatsUI(data) {
    const modalBody = document.getElementById("reservationModalBody");
    const reservedSeats = data.reserved_seats || {};
    const isVip = addSeatsState.statusId === 11;
    const headline = isVip
        ? \'<strong>VIP-Bestellung erweitern:\' + \'</strong> Wählen Sie zusätzliche Plätze aus.\'
        : \'<strong>Reservierung erweitern:\' + \'</strong> Wählen Sie zusätzliche Plätze aus.\';

    const catColors = {};
    for (const cat of (data.categories || [])) { catColors[cat.id] = cat.color || \'#0d6efd\'; }
    addSeatsState.categoryColors = catColors;

    let html = \'<div class="alert alert-info py-2"><i class="bi bi-plus-circle me-2"></i>\' + headline + \'</div>\';
    html += \'<div class="form-check mb-2"><input class="form-check-input" type="checkbox" id="addseats-send-email"><label class="form-check-label" for="addseats-send-email">Bestätigungs-Mail mit aktualisiertem Ticket-Link an Kunden senden</label></div>\';

    if (!data.use_seat_plan || !data.seatplan || data.seatplan.length === 0) {
        modalBody.innerHTML = html + \'<div class="alert alert-warning py-2">Diese Veranstaltung hat keinen Sitzplan – Hinzufügen ist hier nicht verfügbar.</div>\';
        return;
    }

    html += \'<div style="background:#f8f9fa;padding:1rem;border-radius:0.5rem;overflow-x:auto;">\';
    for (const row of data.seatplan) {
        if (row.type !== \'row\') continue;
        if (row.no === 0) {
            html += \'<div style="display:flex;align-items:center;margin-bottom:0.5rem;width:100%;"><div style="width:100%;flex:1;background:#212529;color:white;text-align:center;padding:0.5rem;border-radius:0.2rem;font-weight:bold;"><i class="bi bi-music-note-beamed"></i> BÜHNE</div></div>\';
            continue;
        }
        const rowCat = row.category;
        const displayName = row.row_name || (\'Reihe \' + row.no);
        html += `<div style="display:flex;align-items:center;margin-bottom:0.5rem;gap:0.25rem;"><div style="min-width:60px;font-weight:bold;text-align:right;font-size:0.85rem;">${escHtml(displayName)}</div><div style="display:flex;flex-wrap:nowrap;gap:0.25rem;flex:1;">`;
        for (const el of row.content) {
            if (el.type === \'seat\') {
                const seatId = \'seat_\' + row.no + \'_\' + el.no;
                const catId = el.category || rowCat;
                const color = catColors[catId] || \'#0d6efd\';
                const isOwn = addSeatsState.ownSeats.includes(seatId);
                const isReserved = !isOwn && (seatId in reservedSeats);
                html += renderAddSeatCell(seatId, el.no, color, isReserved, isOwn);
            } else if (el.type === \'table\') {
                html += renderAddSeatTable(el, rowCat, catColors, reservedSeats);
            } else if (el.type === \'distance\' || el.type === \'spacer\') {
                html += `<span ${el.stairs ? "title=\"Treppe\" " : ""}style="${el.stairs ? "background:#fff;color:#000;border:2px solid #000;" : "background:#6c757d;color:white;border:1px solid #6c757d;"}display:inline-block;box-sizing:border-box;width:2.5rem;min-width:2.5rem;flex:0 0 2.5rem;padding:0.25rem 0;border-radius:0.2rem;font-size:0.75rem;text-align:center;cursor:not-allowed;">${el.no || \'\'}</span>`;
            }
        }
        html += \'</div></div>\';
    }
    html += \'</div>\';

    html += \'<div class="mt-2 d-flex flex-wrap gap-3 small">\';
    html += \'<span><span style="display:inline-block;width:16px;height:16px;background:#28a745;border-radius:2px;vertical-align:text-bottom;"></span> Verfügbar</span>\';
    html += \'<span><span style="display:inline-block;width:16px;height:16px;background:#dc3545;border-radius:2px;vertical-align:text-bottom;"></span> Belegt</span>\';
    html += \'<span><span style="display:inline-block;width:16px;height:16px;background:#ffc107;border-radius:2px;vertical-align:text-bottom;"></span> Eigener Platz</span>\';
    html += \'<span><span style="display:inline-block;width:16px;height:16px;background:#0d6efd;border-radius:2px;vertical-align:text-bottom;"></span> Ausgewählt</span>\';
    html += \'</div>\';

    html += \'<div id="addseats-summary" class="mt-3 small text-muted">Klicken Sie auf grüne Plätze, um sie zur Bestellung hinzuzufügen.</div>\';
    modalBody.innerHTML = html;
}

function renderAddSeatCell(seatId, seatNo, borderColor, isReserved, isOwn) {
    let bg, cursor, onclick, title, textColor = \'white\';
    if (isOwn) {
        bg = \'#ffc107\'; textColor = \'#212529\'; cursor = \'not-allowed\'; onclick = \'\'; title = \'Eigener Platz\';
    } else if (isReserved) {
        bg = \'#dc3545\'; cursor = \'not-allowed\'; onclick = \'\'; title = \'Belegt\';
    } else {
        bg = \'#28a745\'; cursor = \'pointer\'; onclick = `onclick="toggleAddSeat(this,\'${seatId}\')"`; title = \'Verfügbar\';
    }
    return `<span class="addseats-seat" data-seat-id="${seatId}" style="background:${bg};color:${textColor};border:2px solid ${borderColor};display:inline-block;box-sizing:border-box;width:2.5rem;min-width:2.5rem;flex:0 0 2.5rem;padding:0.25rem 0;border-radius:0.2rem;font-size:0.75rem;text-align:center;cursor:${cursor};user-select:none;" ${onclick} title="${title}">${seatNo}</span>`;
}

function renderAddSeatTable(el, rowCategory, catColors, reservedSeats) {
    const tableSeats = el.seats || {};
    let html = \'<div style="display:inline-flex;flex-direction:column;align-items:center;gap:0.25rem;">\';
    const renderArea = (seats, style) => {
        if (!seats || seats.length === 0) return \'\';
        let h = `<div style="${style}">`;
        for (const seat of seats) {
            const seatId = \'tableseat_\' + el.no + \'_\' + seat.number;
            const catId = seat.category || rowCategory;
            const color = catColors[catId] || \'#0d6efd\';
            const isOwn = addSeatsState.ownSeats.includes(seatId);
            const isReserved = !isOwn && (seatId in reservedSeats);
            h += renderAddSeatCell(seatId, seat.number, color, isReserved, isOwn);
        }
        h += \'</div>\';
        return h;
    };
    html += renderArea(tableSeats.front, \'display:flex;gap:0.25rem;\');
    html += \'<div style="display:flex;align-items:center;gap:0.25rem;">\';
    html += renderArea(tableSeats.left, \'display:flex;flex-direction:column;gap:0.25rem;\');
    html += `<span style="font-size:0.9rem;padding:0.5rem;background:#6c757d;color:white;border:1px solid #5a6268;border-radius:0.2rem;text-align:center;">T${el.no}</span>`;
    html += renderArea(tableSeats.right, \'display:flex;flex-direction:column;gap:0.25rem;\');
    html += \'</div>\';
    html += renderArea(tableSeats.back, \'display:flex;gap:0.25rem;\');
    html += \'</div>\';
    return html;
}

function toggleAddSeat(el, seatId) {
    const idx = addSeatsState.selectedSeats.indexOf(seatId);
    if (idx >= 0) {
        addSeatsState.selectedSeats.splice(idx, 1);
        el.style.backgroundColor = \'#28a745\';
    } else {
        addSeatsState.selectedSeats.push(seatId);
        el.style.backgroundColor = \'#0d6efd\';
    }
    const btn = document.getElementById(\'addseats-confirm-btn\');
    if (btn) btn.disabled = addSeatsState.selectedSeats.length === 0;
    const summary = document.getElementById(\'addseats-summary\');
    if (summary) {
        const n = addSeatsState.selectedSeats.length;
        summary.innerHTML = n === 0
            ? \'Klicken Sie auf grüne Plätze, um sie zur Bestellung hinzuzufügen.\'
            : `<strong>${n}</strong> Platz/Plätze ausgewählt.`;
    }
}

function confirmAddSeats() {
    if (addSeatsState.selectedSeats.length === 0) return;
    if (!confirm(addSeatsState.selectedSeats.length + \' Platz/Plätze zur Bestellung hinzufügen?\')) return;

    const sendEmail = document.getElementById(\'addseats-send-email\')?.checked || false;
    const btn = document.getElementById(\'addseats-confirm-btn\');
    if (btn) { btn.disabled = true; btn.innerHTML = \'<span class="spinner-border spinner-border-sm"></span> Wird hinzugefügt...\'; }

    fetch(ADD_SEATS_URL, {
        method: \'POST\',
        headers: { \'Content-Type\': \'application/json\' },
        body: JSON.stringify({
            anchor_reservation_id: addSeatsState.anchorId,
            seats: addSeatsState.selectedSeats,
            send_email: sendEmail,
        })
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            bootstrap.Modal.getInstance(document.getElementById(\'reservationModal\'))?.hide();
            const dlg = document.querySelector("#reservationModal .modal-dialog");
            if (dlg) { dlg.classList.remove("modal-lg"); dlg.classList.add("modal-xl"); }
            const msg = data.message + (data.email_sent ? \' Mail gesendet.\' : \'\');
            showFeedback(msg, \'success\');
            setTimeout(() => location.reload(), 1200);
        } else {
            showFeedback(\'Fehler: \' + (data.error || \'Unbekannter Fehler\'), \'error\');
            if (btn) { btn.disabled = false; btn.innerHTML = \'<i class="bi bi-check-lg"></i> Hinzufügen bestätigen\'; }
        }
    })
    .catch(err => {
        showFeedback(\'Fehler: \' + err.message, \'error\');
        if (btn) { btn.disabled = false; btn.innerHTML = \'<i class="bi bi-check-lg"></i> Hinzufügen bestätigen\'; }
    });
}
</script>
';

require __DIR__ . '/../../app/views/layouts/main.php';

