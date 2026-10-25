<?php
require_once __DIR__ . '/../../app/bootstrap.php';

use App\Core\Auth;
use App\Core\Config;
use App\Models\Reservation;
use App\Models\Event;
use App\Models\Customer;

Auth::requirePresales();

$reservationModel = new Reservation();
$eventModel = new Event();
$customerModel = new Customer();

// Filters
$filters = [];
$eventId = (int)($_GET['event_id'] ?? 0);
$eventDate = $_GET['date'] ?? '';
$statusId = (isset($_GET['status_id']) && $_GET['status_id'] !== '') ? (int)$_GET['status_id'] : null;

if ($eventId > 0) {
    $filters['event_id'] = $eventId;
}
if (!empty($eventDate)) {
    $filters['event_date'] = $eventDate;
}
if ($statusId !== null) {
    $filters['status_id'] = $statusId;
}

// Standard: nur Bestellungen zu aktiven Veranstaltungen. Ältere Bestellungen
// erreicht man über den Filter "Alle (inkl. beendete)". Bei gezielter Auswahl
// einer Veranstaltung gilt die Einschränkung nicht.
$eventScope = ($_GET['event_scope'] ?? 'active') === 'all' ? 'all' : 'active';
if ($eventScope === 'active' && $eventId <= 0) {
    $filters['active_events_only'] = true;
}

$reservationModel->cleanupExpiredCartItems();
$reservations = $reservationModel->getGrouped($filters);
$events = $eventModel->getAll();

$pageTitle = 'Reservierungen';
$showSidebar = true;
$showHeader = true;

$content = '
<div class="d-flex justify-content-between align-items-center mb-4">
    <h2>Reservierungen</h2>
    <a href="' . url('presales/sell.php') . '" class="btn btn-primary">
        <i class="bi bi-plus-circle"></i> Neue Reservierung
    </a>
</div>

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
                    <option value="">Alle Status</option>
                    <option value="1" ' . ($statusId === 1 ? 'selected' : '') . '>Warenkorb</option>
                    <option value="2" ' . ($statusId === 2 ? 'selected' : '') . '>Bezahlt (PayPal)</option>
                    <option value="3" ' . ($statusId === 3 ? 'selected' : '') . '>Reserviert (Vorverkauf)</option>
                    <option value="4" ' . ($statusId === 4 ? 'selected' : '') . '>Bezahlt (Vorverkauf)</option>
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
                    <a href="' . url('presales/reservations.php?event_scope=all') . '">Alle Bestellungen anzeigen (inkl. beendeter Veranstaltungen)</a>
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
        $statusId     = (int)$order['status_id'];
        $reservationIds = $order['reservation_ids']; // comma-separated string
        $ticketCount  = (int)$order['ticket_count'];

        $statusBadge = reservationStatusBadge($statusId);

        if ($statusId === 1) {
            $customerDisplay = '<span class="text-muted">-</span>';
        } elseif (!$order['customer_id']) {
            $customerDisplay = '<span class="badge bg-secondary">Barverkauf</span>';
        } elseif ($order['first_name'] && $order['last_name']) {
            $customerDisplay = htmlspecialchars($order['first_name'] . ' ' . $order['last_name']);
        } else {
            $customerDisplay = '<span class="text-muted">-</span>';
        }

        $idsJson = json_encode(array_map('intval', explode(',', $reservationIds)));

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

// Modal HTML
$content .= '
<!-- Order Details Modal -->
<div class="modal fade" id="orderModal" tabindex="-1" aria-labelledby="orderModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="orderModalLabel"><i class="bi bi-ticket-perforated"></i> Bestelldetails</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body" id="orderModalBody">
                <div class="text-center"><div class="spinner-border" role="status"></div></div>
            </div>
            <div class="modal-footer" id="orderModalFooter">
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

$markPaidUrl       = url('api/reservation_mark_paid.php');
$deleteUrl         = url('api/reservation_delete.php');
$resendEmailUrl    = url('api/reservation_resend_email.php');
$orderDetailsUrl   = url('api/order_details.php');
$ticketDownloadUrl = url('api/ticket_download.php');
$seatplanViewUrl   = url('admin/seatplan_view.php');
$customerUrl       = url('presales/customers.php');
$customerEditUrl   = url('presales/customers_edit.php');

$customerUrlJson     = json_encode($customerUrl);
$customerEditUrlJson = json_encode($customerEditUrl);

$additionalJs = '
<script>
const CUSTOMER_URL      = ' . $customerUrlJson . ';
const CUSTOMER_EDIT_URL = ' . $customerEditUrlJson . ';

function showFeedback(message, type = "info") {
    let container = document.getElementById("feedback-container");
    if (!container) {
        container = document.createElement("div");
        container.id = "feedback-container";
        container.style.cssText = "position: fixed; top: 70px; right: 20px; z-index: 9999; max-width: 400px;";
        document.body.appendChild(container);
    }
    const alertClass = { "success": "alert-success", "error": "alert-danger", "warning": "alert-warning", "info": "alert-info" }[type] || "alert-info";
    const alertId = "alert-" + Date.now();
    container.insertAdjacentHTML("beforeend",
        "<div id=\"" + alertId + "\" class=\"alert " + alertClass + " alert-dismissible fade show\" role=\"alert\">" +
        message + "<button type=\"button\" class=\"btn-close\" data-bs-dismiss=\"alert\" aria-label=\"Close\"></button></div>"
    );
    setTimeout(() => { const el = document.getElementById(alertId); if (el) new bootstrap.Alert(el).close(); }, 5000);
}

function escHtml(s) { const d = document.createElement("div"); d.appendChild(document.createTextNode(s || "")); return d.innerHTML; }
// Price type of a ticket (from api/order_details.php) as badge; normal price stays plain
function priceTypeBadge(type, fnCardNumber) {
    const colors = { "Ermäßigt": "bg-warning text-dark", "FN-Card": "bg-info text-dark", "Generalprobe": "bg-secondary", "Begleitperson": "bg-light text-dark border" };
    if (!type || !colors[type]) return escHtml(type || "Normal");
    const label = type === "FN-Card" && fnCardNumber ? type + " " + fnCardNumber : type;
    return "<span class=\"badge " + colors[type] + "\">" + escHtml(label) + "</span>";
}

function formatSeatId(id) {
    if (!id) return "";
    let m = String(id).match(/^seat_(\d+)_(.+)$/);
    if (m) return "Reihe " + m[1] + ", Platz " + m[2];
    m = String(id).match(/^tableseat_(\d+)_(.+)$/);
    if (m) return "Tisch " + m[1] + ", Platz " + m[2];
    m = String(id).match(/^table_(\d+)$/);
    if (m) return "Tisch " + m[1];
    return id;
}

const STATUS_BADGE_MAP = ' . reservationStatusBadgeMapJson() . ';
function getStatusBadge(sid) {
    return STATUS_BADGE_MAP[sid] || \'<span class="badge bg-secondary">Unbekannt (\' + sid + \')</span>\';
}

// IDs der aktuell im Modal angezeigten Bestellung (für Einzel-Löschung)
var presCurrentIds = [];

function viewReservation(ids) {
    const modal     = new bootstrap.Modal(document.getElementById("orderModal"));
    const body      = document.getElementById("orderModalBody");
    const footer    = document.getElementById("orderModalFooter");
    const idsParam  = Array.isArray(ids) ? ids.join(",") : ids;
    const dlg       = document.querySelector("#orderModal .modal-dialog");
    if (dlg) { dlg.classList.remove("modal-lg"); dlg.classList.add("modal-xl"); }

    body.innerHTML = \'<div class="text-center"><div class="spinner-border" role="status"></div></div>\';
    footer.innerHTML = \'<button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Schließen</button>\';
    modal.show();

    fetch("' . $orderDetailsUrl . '?reservation_ids=" + encodeURIComponent(idsParam), { credentials: "same-origin" })
    .then(r => r.json())
    .then(data => {
        if (!data.success) throw new Error(data.error || "Fehler");
        const rs   = data.reservations;
        const first = rs[0];
        const allIds = rs.map(r => parseInt(r.id));
        presCurrentIds = allIds.slice();
        const anyReserved = data.summary.any_reserved;
        const eventDate = first.event_date ? new Date(first.event_date).toLocaleDateString("de-DE") : "-";
        const customerName = ((first.first_name || "") + " " + (first.last_name || "")).trim()
            || (parseInt(first.status_id) === 1 ? "-" : "Barverkauf");

        const fmtEur = (n) => (Number(n) || 0).toFixed(2).replace(".", ",") + " €";

        let rows = "";
        rs.forEach(r => {
            let seat = escHtml(r.seat_display_name || r.seat_identifier || "-");
            if (parseInt(r.is_companion)) seat += \' <span class="badge bg-info">Begleitperson kostenfrei</span>\';
            let cat  = escHtml(r.category_name || "-");
            let red  = priceTypeBadge(r.price_type, r.fn_card_number);
            let price = parseInt(r.is_companion) ? \'0,00 € <span class="text-muted">(B)</span>\' : fmtEur(r.ticket_price);
            const sid = parseInt(r.status_id);
            // Löschen nur bei reservierten (3) oder Warenkorb-Karten (1) anbieten
            const delCell = (sid === 3 || sid === 1)
                ? `<button class="btn btn-sm btn-outline-danger" onclick="deleteSingleReservation(${parseInt(r.id)})" title="Reservierung löschen und Platz freigeben"><i class="bi bi-trash"></i></button>`
                : "";
            rows += `<tr id="pres-res-row-${parseInt(r.id)}" data-ticket-price="${parseInt(r.is_companion) ? 0 : (Number(r.ticket_price) || 0)}"><td>${seat}</td><td>${cat}</td><td>${red}</td><td class="text-end">${price}</td><td>${getStatusBadge(sid)}</td><td>${delCell}</td></tr>`;
        });

        const voucherCodes = data.summary.voucher_codes || [];
        const voucherHtml = voucherCodes.map(c => `<code class="me-1">${escHtml(c)}</code>`).join(" ");

        body.innerHTML = `
            <div class="row mb-3">
                <div class="col-md-6"><strong>Veranstaltung:</strong><br><span class="text-muted">${escHtml(first.event_name || "-")}</span></div>
                <div class="col-md-3"><strong>Datum:</strong><br><span class="text-muted">${eventDate}</span></div>
                <div class="col-md-3"><strong>Karten:</strong><br><span class="badge bg-primary fs-6">${rs.length}</span></div>
            </div>
            <div class="row mb-3">
                <div class="col-md-6"><strong>Kunde:</strong><br>${first.customer_id ? `<a href="${CUSTOMER_EDIT_URL}?id=${first.customer_id}" target="_blank">${escHtml(customerName)}</a>` : `<span class="text-muted">${escHtml(customerName)}</span>`}</div>
                <div class="col-md-6"><strong>E-Mail:</strong><br><span class="text-muted">${escHtml(first.email || "-")}</span></div>
            </div>
            ${voucherCodes.length ? `<div class="row mb-3"><div class="col-12"><strong><i class="bi bi-gift"></i> Verwendeter Gutschein:</strong><br>${voucherHtml}</div></div>` : ""}
            <hr>
            <table class="table table-sm table-bordered align-middle">
                <thead class="table-light"><tr><th>Platz</th><th>Kategorie</th><th>Preisart</th><th class="text-end">Preis</th><th>Status</th><th></th></tr></thead>
                <tbody>${rows}</tbody>
                <tfoot>
                    <tr class="table-light fw-bold">
                        <td colspan="3" class="text-end">Endsumme:</td>
                        <td class="text-end" id="presModalOrderTotal">${fmtEur(data.summary.total)}</td>
                        <td></td>
                        <td></td>
                    </tr>
                </tfoot>
            </table>
            ${rs.some(r => parseInt(r.is_companion)) ? \'<p class="small text-muted mb-0"><span class="badge bg-info">Begleitperson kostenfrei</span> = Begleitperson des Rollstuhlfahrers, zahlt nichts (0,00 €).</p>\' : ""}`;

        // Build footer buttons
        const idsJson  = JSON.stringify(allIds);
        const idsParam2 = allIds.join(",");
        const allPaid  = data.summary.all_paid;
        let btns = \'<button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Schließen</button>\';
        if (first.email) {
            btns += ` <button class="btn btn-outline-secondary" onclick="resendEmailGroup(${idsJson})"><i class="bi bi-envelope"></i> Mail senden</button>`;
        }
        if (anyReserved) {
            btns += ` <button class="btn btn-success" onclick="markGroupAsPaidAndPrint(${idsJson}, \'${encodeURIComponent(idsParam2)}\')"><i class="bi bi-check-circle"></i> Tickets sind bezahlt, jetzt drucken</button>`;
        }
        if (allPaid) {
            btns += ` <a href="' . $ticketDownloadUrl . '?reservation_ids=${encodeURIComponent(idsParam2)}" class="btn btn-outline-success" target="_blank"><i class="bi bi-printer"></i> Karten erneut drucken (Vorverkauf-Template)</a>`;
        }
        if (first.seat_identifier && first.event_id && first.event_date) {
            const seatplanDate = first.event_date.substring(0, 10);
            window._seatplanOverlay = {
                url: "' . $seatplanViewUrl . '?event_id=" + first.event_id + "&date=" + seatplanDate + "&reservation_ids=" + encodeURIComponent(idsParam2) + "&embed=1",
                title: (first.event_name || "Sitzplan") + " – " + new Date(seatplanDate).toLocaleDateString("de-DE"),
            };
            btns += \' <button class="btn btn-outline-info" onclick="openSeatplanOverlay(window._seatplanOverlay.url, window._seatplanOverlay.title)"><i class="bi bi-grid"></i> Im Sitzplan anzeigen</button>\';
        }

        // Umbuchen-Button
        const rebookableRes = rs.filter(r => { const s = parseInt(r.status_id); return s >= 2 && s !== 7; });
        if (rebookableRes.length > 0 && first.event_id) {
            window._rebookData = rebookableRes.map(r => ({id: r.id, seat: r.seat_identifier, seat_display: r.seat_display_name || r.seat_identifier, date: r.event_date, category_id: r.category_id, category_name: r.category_name}));
            window._rebookEventId = first.event_id;
            btns += \' <button class="btn btn-outline-warning" onclick="startRebook(window._rebookData, window._rebookEventId)"><i class="bi bi-arrow-left-right"></i> Umbuchen</button>\';
        }

        // Plätze hinzufügen — nur bei offener Reservierung (status 3 oder 6); VIP nur im Admin
        const addableAnchor = rs.find(r => { const s = parseInt(r.status_id); return s === 3 || s === 6; });
        if (addableAnchor && first.event_id && first.seat_identifier) {
            const sameOrderSeats = rs.filter(r => r.event_date === addableAnchor.event_date && r.seat_identifier).map(r => r.seat_identifier);
            window._addSeatsAnchor = { id: addableAnchor.id, event_id: first.event_id, event_date: addableAnchor.event_date, status_id: parseInt(addableAnchor.status_id), own_seats: sameOrderSeats };
            btns += \' <button class="btn btn-outline-primary" onclick="startAddSeats(window._addSeatsAnchor)"><i class="bi bi-plus-circle"></i> Plätze hinzufügen</button>\';
        }

        footer.innerHTML = btns;
    })
    .catch(err => {
        body.innerHTML = `<div class="alert alert-danger">Fehler: ${escHtml(err.message)}</div>`;
    });
}

function markGroupAsPaidAndPrint(ids, idsParam) {
    if (!confirm("Alle " + ids.length + " Ticket(s) als bezahlt/abgeholt markieren und Tickets öffnen?")) return;
    fetch("' . $markPaidUrl . '", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ reservation_ids: ids })
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            bootstrap.Modal.getInstance(document.getElementById("orderModal"))?.hide();
            window.open("' . $ticketDownloadUrl . '?reservation_ids=" + idsParam, "_blank");
            showFeedback(data.message || "Tickets als bezahlt markiert", "success");
            setTimeout(() => window.location.reload(), 1500);
        } else {
            showFeedback("Fehler: " + (data.error || "Unbekannter Fehler"), "error");
        }
    })
    .catch(err => showFeedback("Fehler: " + err.message, "error"));
}

function deleteGroup(ids) {
    if (!confirm("Alle " + ids.length + " Ticket(s) wirklich löschen?")) return;
    fetch("' . $deleteUrl . '", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ reservation_ids: ids })
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            bootstrap.Modal.getInstance(document.getElementById("orderModal"))?.hide();
            showFeedback(data.message, "success");
            setTimeout(() => window.location.reload(), 1000);
        } else {
            showFeedback("Fehler: " + (data.error || "Unbekannter Fehler"), "error");
        }
    })
    .catch(err => showFeedback("Fehler: " + err.message, "error"));
}

// Einzelne Reservierung löschen (nur reserviert/Warenkorb erlaubt, serverseitig geprüft)
function deleteSingleReservation(id) {
    if (!confirm("Diese Reservierung wirklich löschen und den Platz freigeben?")) return;
    fetch("' . $deleteUrl . '", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ reservation_ids: [id] })
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            presCurrentIds = presCurrentIds.filter(i => i !== id);
            showFeedback(data.message || "Reservierung gelöscht", "success");
            if (presCurrentIds.length === 0) {
                bootstrap.Modal.getInstance(document.getElementById("orderModal"))?.hide();
                setTimeout(() => window.location.reload(), 800);
            } else {
                // Modal mit den verbliebenen Karten neu aufbauen, damit Kartenanzahl,
                // Endsumme und die Footer-Buttons ("Tickets sind bezahlt", Drucken …)
                // die aktuell verbliebenen Reservierungen verwenden.
                viewReservation(presCurrentIds);
            }
        } else {
            showFeedback("Fehler: " + (data.error || "Unbekannter Fehler"), "error");
        }
    })
    .catch(err => showFeedback("Fehler: " + err.message, "error"));
}

function resendEmailGroup(ids) {
    if (!confirm("E-Mail erneut senden?")) return;
    // Resend for the first reservation ID (represents the whole order)
    fetch("' . $resendEmailUrl . '", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ reservation_id: ids[0] })
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) showFeedback(data.message, "success");
        else showFeedback("Fehler: " + (data.error || "Unbekannter Fehler"), "error");
    })
    .catch(err => showFeedback("Fehler: " + err.message, "error"));
}

// ── Live customer name/email filter ──────────────────────────────────────────
function filterTableByCustomer(query) {
    const q = query.trim().toLowerCase();
    document.querySelectorAll("table tbody tr").forEach(function(row) {
        const name  = (row.dataset.customerName  || "").toLowerCase();
        const email = (row.dataset.customerEmail || "").toLowerCase();
        row.style.display = (q === "" || name.includes(q) || email.includes(q)) ? "" : "none";
    });
}

function clearCustomerFilter() {
    const input = document.getElementById("customer_filter");
    if (input) { input.value = ""; filterTableByCustomer(""); }
}

function openSeatplanOverlay(url, title) {
    const iframe = document.getElementById("seatplanIframe");
    const label  = document.getElementById("seatplanModalLabel");
    if (label) label.innerHTML = \'<i class="bi bi-grid"></i> \' + escHtml(title || "Sitzplan");
    if (iframe) iframe.src = url;
    bootstrap.Modal.getOrCreateInstance(document.getElementById("seatplanModal")).show();
}

document.addEventListener("DOMContentLoaded", () => {
    const modalEl = document.getElementById("seatplanModal");
    if (!modalEl) return;
    modalEl.addEventListener("hidden.bs.modal", () => {
        const iframe = document.getElementById("seatplanIframe");
        if (iframe) iframe.src = "about:blank";
    });
});

// ============================================================
// REBOOK (Umbuchung) functionality
// ============================================================

const SEATPLAN_DATA_URL = "' . url('api/seatplan_data.php') . '";
const REBOOK_SEATS_URL  = "' . url('api/rebook_seats.php') . '";
const ADD_SEATS_URL     = "' . url('api/reservation_add_seats.php') . '";
const REBOOK_MODAL_ID   = "orderModal";

let rebookState = {
    reservations: [], selected: [], eventId: 0, eventDates: [],
    seatplanData: null, categories: [], targetDate: "",
    targetSeats: {}, useSeatPlan: true, savedModalBody: "", savedModalFooter: "",
};

function startRebook(reservations, eventId) {
    rebookState.reservations = reservations;
    rebookState.eventId = eventId;
    rebookState.selected = [];
    rebookState.targetSeats = {};

    const modalBody = document.getElementById("orderModalBody");
    const modalFooter = document.getElementById("orderModalFooter");
    rebookState.savedModalBody = modalBody.innerHTML;
    rebookState.savedModalFooter = modalFooter.innerHTML;

    let html = \'<div class="alert alert-info py-2"><i class="bi bi-arrow-left-right me-2"></i><strong>Umbuchung:</strong> Wählen Sie die Plätze aus, die umgebucht werden sollen.</div>\';
    html += \'<div class="table-responsive"><table class="table table-sm table-bordered"><thead class="table-light"><tr><th style="width:40px"><input type="checkbox" id="rebook-select-all" onchange="rebookToggleAll(this)"></th><th>Platz</th><th>Kategorie</th><th>Datum</th></tr></thead><tbody>\';
    for (const r of reservations) {
        const dateFormatted = new Date(r.date).toLocaleDateString("de-DE");
        html += `<tr><td><input type="checkbox" class="rebook-cb" value="${r.id}" onchange="rebookUpdateSelection()"></td><td>${escHtml(r.seat_display || r.seat)}</td><td>${escHtml(r.category_name || "-")}</td><td>${escHtml(dateFormatted)}</td></tr>`;
    }
    html += "</tbody></table></div>";
    modalBody.innerHTML = html;
    modalFooter.innerHTML = `<button class="btn btn-secondary" onclick="cancelRebook()">Abbrechen</button><button class="btn btn-primary" id="rebook-next-btn" disabled onclick="rebookStep2()">Weiter <i class="bi bi-arrow-right"></i></button>`;
}

function rebookToggleAll(cb) { document.querySelectorAll(".rebook-cb").forEach(c => { c.checked = cb.checked; }); rebookUpdateSelection(); }
function rebookUpdateSelection() {
    rebookState.selected = Array.from(document.querySelectorAll(".rebook-cb:checked")).map(c => parseInt(c.value));
    const btn = document.getElementById("rebook-next-btn");
    if (btn) btn.disabled = rebookState.selected.length === 0;
}
function cancelRebook() {
    document.getElementById("orderModalBody").innerHTML = rebookState.savedModalBody;
    document.getElementById("orderModalFooter").innerHTML = rebookState.savedModalFooter;
    const dlg = document.querySelector("#orderModal .modal-dialog");
    if (dlg) { dlg.classList.remove("modal-lg"); dlg.classList.add("modal-xl"); }
}

function rebookStep2() {
    const selectedRes = rebookState.reservations.filter(r => rebookState.selected.includes(r.id));
    const requiredCategories = [...new Set(selectedRes.map(r => parseInt(r.category_id)))];
    rebookState.targetSeats = {};
    const currentDate = selectedRes[0].date.substring(0, 10);
    rebookState.targetDate = currentDate;

    const dlg = document.querySelector("#orderModal .modal-dialog");
    if (dlg) { dlg.classList.remove("modal-lg"); dlg.classList.add("modal-xl"); }

    document.getElementById("orderModalBody").innerHTML = \'<div class="text-center py-4"><div class="spinner-border" role="status"></div><p class="mt-2">Sitzplan wird geladen...</p></div>\';
    document.getElementById("orderModalFooter").innerHTML = `<div class="form-check me-auto d-flex align-items-center"><input class="form-check-input mt-0 me-2" type="checkbox" id="rebook-notify" checked><label class="form-check-label small" for="rebook-notify">Kunde per E-Mail benachrichtigen</label></div><button class="btn btn-secondary" onclick="cancelRebook()">Abbrechen</button><button class="btn btn-warning" id="rebook-confirm-btn" disabled onclick="rebookConfirm()"><i class="bi bi-check-lg"></i> Umbuchen bestätigen</button>`;
    fetchSeatplanAndRender(currentDate, selectedRes, requiredCategories);
}

function fetchSeatplanAndRender(date, selectedRes, requiredCategories) {
    rebookState.targetDate = date;
    rebookState.targetSeats = {};
    fetch(SEATPLAN_DATA_URL + "?event_id=" + rebookState.eventId + "&date=" + date, { credentials: "same-origin" })
        .then(r => r.json())
        .then(data => {
            if (!data.success) throw new Error(data.error || "Fehler beim Laden");
            rebookState.seatplanData = data.seatplan;
            rebookState.categories = data.categories;
            rebookState.eventDates = data.event_dates;
            rebookState.useSeatPlan = data.use_seat_plan;
            const reservedSeats = data.reserved_seats || {};
            const currentSeatsOnThisDate = selectedRes.filter(r => r.date.substring(0, 10) === date).map(r => r.seat);
            renderRebookUI(data, selectedRes, requiredCategories, reservedSeats, currentSeatsOnThisDate, date);
        })
        .catch(err => { document.getElementById("orderModalBody").innerHTML = `<div class="alert alert-danger">Fehler: ${escHtml(err.message)}</div>`; });
}

function renderRebookUI(data, selectedRes, requiredCategories, reservedSeats, freeOverrides, currentDate) {
    const modalBody = document.getElementById("orderModalBody");
    let html = "";
    html += \'<div class="mb-3"><label class="form-label fw-bold">Ziel-Datum:</label><select class="form-select" id="rebook-date-select" onchange="rebookDateChanged()">\';
    for (const d of data.event_dates) {
        const selected = d.date === currentDate ? "selected" : "";
        const label = new Date(d.date).toLocaleDateString("de-DE", {weekday: "short", day: "2-digit", month: "2-digit", year: "numeric"});
        const gpLabel = d.is_general_rehearsal ? " (Generalprobe)" : "";
        html += `<option value="${d.date}" ${selected}>${label}${gpLabel}</option>`;
    }
    html += "</select></div>";
    html += \'<div class="alert alert-info py-2 small mb-3"><i class="bi bi-info-circle me-1"></i> <strong>Hinweis:</strong> Bei Umbuchung auf ein anderes Datum wird die Bestellung in zwei Teile aufgeteilt — die umgebuchten Plätze erscheinen als eigene Bestellung beim neuen Datum. Beide Teile sind über den Kundennamen in der Reservierungs-Übersicht auffindbar.</div>\';
    html += \'<div class="alert alert-secondary py-2 small mb-3"><strong>Umzubuchende Plätze:</strong> \' + selectedRes.map(r => escHtml(r.seat_display || r.seat)).join(", ") + "</div>";

    if (data.use_seat_plan && data.seatplan && data.seatplan.length > 0) {
        const catColors = {};
        for (const cat of data.categories) { catColors[cat.id] = cat.color || "#0d6efd"; }
        html += \'<div style="background:#f8f9fa;padding:1rem;border-radius:0.5rem;overflow-x:auto;">\';
        for (const row of data.seatplan) {
            if (row.type !== "row") continue;
            if (row.no === 0) { html += \'<div style="display:flex;align-items:center;margin-bottom:0.5rem;width:100%;"><div style="width:100%;flex:1;background:#212529;color:white;text-align:center;padding:0.5rem;border-radius:0.2rem;font-weight:bold;"><i class="bi bi-music-note-beamed"></i> BÜHNE</div></div>\'; continue; }
            const rowCat = row.category;
            const displayName = row.row_name || ("Reihe " + row.no);
            html += `<div style="display:flex;align-items:center;margin-bottom:0.5rem;gap:0.25rem;"><div style="min-width:60px;font-weight:bold;text-align:right;font-size:0.85rem;">${escHtml(displayName)}</div><div style="display:flex;flex-wrap:nowrap;gap:0.25rem;flex:1;">`;
            for (const el of row.content) {
                if (el.type === "seat") {
                    const seatId = "seat_" + row.no + "_" + el.no;
                    const catId = el.category || rowCat;
                    const isReserved = (seatId in reservedSeats) && !freeOverrides.includes(seatId);
                    const isAllowed = requiredCategories.includes(parseInt(catId));
                    html += renderRebookSeat(seatId, el.no, catId, catColors[catId] || "#0d6efd", isReserved, isAllowed);
                } else if (el.type === "wheelchair") {
                    const seatId = "wheelchair_" + row.no + "_" + el.no;
                    const catId = el.category || rowCat;
                    const isReserved = (seatId in reservedSeats) && !freeOverrides.includes(seatId);
                    // Wheelchair seats are not rebookable to from regular reservations — render as locked
                    html += `<span style="background:#6c757d;color:white;border:4px solid ${catColors[catId] || "#0d6efd"};display:inline-block;box-sizing:border-box;width:2.5rem;min-width:2.5rem;flex:0 0 2.5rem;padding:0.25rem 0;border-radius:0.2rem;font-size:0.75rem;text-align:center;cursor:not-allowed;user-select:none;" title="Rollstuhlplatz – Umbuchen nicht möglich"><i class="bi bi-person-wheelchair"></i> ${el.no}</span>`;
                } else if (el.type === "table") {
                    html += renderRebookTable(el, row.no, rowCat, catColors, reservedSeats, freeOverrides, requiredCategories);
                } else if (el.type === "distance" || el.type === "spacer") {
                    html += `<span ${el.stairs ? "title=\"Treppe\" " : ""}style="${el.stairs ? "background:#fff;color:#000;border:2px solid #000;" : "background:#6c757d;color:white;border:1px solid #6c757d;"}display:inline-block;box-sizing:border-box;width:2.5rem;min-width:2.5rem;flex:0 0 2.5rem;padding:0.25rem 0;border-radius:0.2rem;font-size:0.75rem;text-align:center;cursor:not-allowed;">${el.stairs ? "" : (el.no || "")}</span>`;
                }
            }
            html += "</div></div>";
        }
        html += "</div>";
        html += \'<div class="mt-2 d-flex flex-wrap gap-3 small"><span><span style="display:inline-block;width:16px;height:16px;background:#28a745;border-radius:2px;vertical-align:text-bottom;"></span> Verfügbar</span><span><span style="display:inline-block;width:16px;height:16px;background:#adb5bd;border-radius:2px;vertical-align:text-bottom;"></span> Andere Kategorie</span><span><span style="display:inline-block;width:16px;height:16px;background:#dc3545;border-radius:2px;vertical-align:text-bottom;"></span> Belegt</span><span><span style="display:inline-block;width:16px;height:16px;background:#0d6efd;border-radius:2px;vertical-align:text-bottom;"></span> Ausgewählt</span></div>\';
    } else {
        html += \'<div class="alert alert-info py-2"><i class="bi bi-info-circle me-1"></i>Veranstaltung ohne Sitzplan. Wählen Sie oben das neue Datum.</div>\';
        for (const r of selectedRes) { rebookState.targetSeats[r.id] = "no_seatplan_rebook_" + r.id + "_" + Date.now(); }
    }
    html += \'<div id="rebook-mapping" class="mt-3"></div>\';
    modalBody.innerHTML = html;
    rebookUpdateConfirmBtn(selectedRes.length);
    rebookUpdateMapping(selectedRes);
}

function renderRebookSeat(seatId, seatNo, catId, color, isReserved, isAllowed) {
    let bgColor, cursor, onclick, title;
    if (isReserved) { bgColor = "#dc3545"; cursor = "not-allowed"; onclick = ""; title = "Belegt"; }
    else if (!isAllowed) { bgColor = "#adb5bd"; cursor = "not-allowed"; onclick = ""; title = "Andere Kategorie"; }
    else { bgColor = "#28a745"; cursor = "pointer"; onclick = `onclick="rebookToggleSeat(this,\'${seatId}\',${catId})"`; title = "Verfügbar"; }
    return `<span class="rebook-seat" data-seat-id="${seatId}" data-category="${catId}" style="background:${bgColor};color:white;border:4px solid ${color};display:inline-block;box-sizing:border-box;width:2.5rem;min-width:2.5rem;flex:0 0 2.5rem;padding:0.25rem 0;border-radius:0.2rem;font-size:0.75rem;text-align:center;cursor:${cursor};user-select:none;" ${onclick} title="${title}">${seatNo}</span>`;
}

function renderRebookTable(el, rowNo, rowCategory, catColors, reservedSeats, freeOverrides, requiredCategories) {
    const tableSeats = el.seats || {};
    let html = \'<div style="display:inline-flex;flex-direction:column;align-items:center;gap:0.25rem;">\';
    const renderArea = (seats, style) => {
        if (!seats || seats.length === 0) return "";
        let h = `<div style="${style}">`;
        for (const seat of seats) {
            const seatId = "tableseat_" + el.no + "_" + seat.number;
            const catId = seat.category || rowCategory;
            const isReserved = (seatId in reservedSeats) && !freeOverrides.includes(seatId);
            const isAllowed = requiredCategories.includes(parseInt(catId));
            h += renderRebookSeat(seatId, seat.number, catId, catColors[catId] || "#0d6efd", isReserved, isAllowed);
        }
        return h + "</div>";
    };
    html += renderArea(tableSeats.front, "display:flex;gap:0.25rem;");
    html += \'<div style="display:flex;align-items:center;gap:0.25rem;">\';
    html += renderArea(tableSeats.left, "display:flex;flex-direction:column;gap:0.25rem;");
    html += `<span style="font-size:0.9rem;padding:0.5rem;background:#6c757d;color:white;border:1px solid #5a6268;border-radius:0.2rem;text-align:center;">T${el.no}</span>`;
    html += renderArea(tableSeats.right, "display:flex;flex-direction:column;gap:0.25rem;");
    html += "</div>";
    html += renderArea(tableSeats.back, "display:flex;gap:0.25rem;");
    html += "</div>";
    return html;
}

function rebookToggleSeat(el, seatId, categoryId) {
    const selectedRes = rebookState.reservations.filter(r => rebookState.selected.includes(r.id));
    const currentlySelected = Object.values(rebookState.targetSeats);
    if (currentlySelected.includes(seatId)) {
        for (const [resId, seat] of Object.entries(rebookState.targetSeats)) { if (seat === seatId) { delete rebookState.targetSeats[resId]; break; } }
        el.style.backgroundColor = "#28a745";
    } else {
        const unassigned = selectedRes.filter(r => !rebookState.targetSeats[r.id]);
        if (unassigned.length === 0) return;
        rebookState.targetSeats[unassigned[0].id] = seatId;
        el.style.backgroundColor = "#0d6efd";
    }
    rebookUpdateConfirmBtn(selectedRes.length);
    rebookUpdateMapping(selectedRes);
}

function rebookUpdateConfirmBtn(neededCount) {
    const btn = document.getElementById("rebook-confirm-btn");
    if (!btn) return;
    if (!rebookState.useSeatPlan) { btn.disabled = false; return; }
    btn.disabled = Object.keys(rebookState.targetSeats).length < neededCount;
}

function rebookUpdateMapping(selectedRes) {
    const container = document.getElementById("rebook-mapping");
    if (!container) return;
    const entries = Object.entries(rebookState.targetSeats);
    if (entries.length === 0 && rebookState.useSeatPlan) { container.innerHTML = \'<p class="text-muted small">Klicken Sie auf grüne Plätze, um die neuen Plätze zuzuweisen.</p>\'; return; }
    let html = \'<table class="table table-sm table-bordered mb-0"><thead class="table-light"><tr><th>Aktueller Platz</th><th></th><th>Neuer Platz</th></tr></thead><tbody>\';
    for (const r of selectedRes) {
        const newSeat = rebookState.targetSeats[r.id];
        html += `<tr><td>${escHtml(r.seat_display || r.seat)}</td><td class="text-center"><i class="bi bi-arrow-right"></i></td><td>${newSeat ? escHtml(formatSeatId(newSeat)) : \'<span class="text-muted">— noch nicht gewählt —</span>\'}</td></tr>`;
    }
    container.innerHTML = html + "</tbody></table>";
}

function rebookDateChanged() {
    const newDate = document.getElementById("rebook-date-select").value;
    const selectedRes = rebookState.reservations.filter(r => rebookState.selected.includes(r.id));
    const requiredCategories = [...new Set(selectedRes.map(r => parseInt(r.category_id)))];
    document.getElementById("orderModalBody").innerHTML = \'<div class="text-center py-4"><div class="spinner-border" role="status"></div><p class="mt-2">Sitzplan wird geladen...</p></div>\';
    fetchSeatplanAndRender(newDate, selectedRes, requiredCategories);
}

function rebookConfirm() {
    const selectedRes = rebookState.reservations.filter(r => rebookState.selected.includes(r.id));
    const rebookings = selectedRes.map(r => ({ reservation_id: r.id, new_seat_identifier: rebookState.targetSeats[r.id] || r.seat, new_event_date: rebookState.targetDate }));
    const hasChange = rebookings.some(rb => { const orig = selectedRes.find(r => r.id === rb.reservation_id); return rb.new_seat_identifier !== orig.seat || rb.new_event_date !== orig.date.substring(0, 10); });
    if (!hasChange) { showFeedback("Keine Änderung — Plätze und Datum sind identisch.", "warning"); return; }
    if (!confirm(rebookings.length + " Platz/Plätze umbuchen?")) return;
    const notifyCustomer = document.getElementById("rebook-notify")?.checked === true;
    const btn = document.getElementById("rebook-confirm-btn");
    if (btn) { btn.disabled = true; btn.innerHTML = \'<span class="spinner-border spinner-border-sm"></span> Wird umgebucht...\'; }
    fetch(REBOOK_SEATS_URL, { method: "POST", headers: { "Content-Type": "application/json" }, body: JSON.stringify({ rebookings: rebookings, notify_customer: notifyCustomer }) })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            bootstrap.Modal.getInstance(document.getElementById("orderModal"))?.hide();
            showFeedback(data.message || "Umbuchung erfolgreich", "success");
            setTimeout(() => location.reload(), 1200);
        } else {
            showFeedback("Fehler: " + (data.error || "Unbekannter Fehler"), "error");
            if (btn) { btn.disabled = false; btn.innerHTML = \'<i class="bi bi-check-lg"></i> Umbuchen bestätigen\'; }
        }
    })
    .catch(err => {
        showFeedback("Fehler: " + err.message, "error");
        if (btn) { btn.disabled = false; btn.innerHTML = \'<i class="bi bi-check-lg"></i> Umbuchen bestätigen\'; }
    });
}

// ============================================================
// ADD SEATS (Plätze hinzufügen) functionality — Reservierungen
// ============================================================

let addSeatsState = { anchorId: 0, eventId: 0, eventDate: "", statusId: 0, selectedSeats: [], categoryColors: {}, ownSeats: [] };

function startAddSeats(anchor) {
    addSeatsState.anchorId  = anchor.id;
    addSeatsState.eventId   = anchor.event_id;
    addSeatsState.eventDate = (anchor.event_date || "").substring(0, 10);
    addSeatsState.statusId  = anchor.status_id;
    addSeatsState.selectedSeats = [];
    addSeatsState.ownSeats = anchor.own_seats || [];

    const dlg = document.querySelector("#orderModal .modal-dialog");
    if (dlg) { dlg.classList.remove("modal-lg"); dlg.classList.add("modal-xl"); }

    const body   = document.getElementById("orderModalBody");
    const footer = document.getElementById("orderModalFooter");
    body.innerHTML = \'<div class="text-center py-4"><div class="spinner-border" role="status"></div><p class="mt-2">Sitzplan wird geladen...</p></div>\';
    footer.innerHTML = \'<button class="btn btn-secondary" onclick="cancelAddSeats()">Abbrechen</button> <button class="btn btn-success" id="addseats-confirm-btn" disabled onclick="confirmAddSeats()"><i class="bi bi-check-lg"></i> Hinzufügen bestätigen</button>\';

    fetch(SEATPLAN_DATA_URL + "?event_id=" + addSeatsState.eventId + "&date=" + addSeatsState.eventDate, { credentials: "same-origin" })
    .then(r => r.json())
    .then(data => {
        if (!data.success) throw new Error(data.error || "Sitzplan konnte nicht geladen werden");
        renderAddSeatsUI(data);
    })
    .catch(err => { body.innerHTML = `<div class="alert alert-danger">Fehler: ${escHtml(err.message)}</div>`; });
}

function cancelAddSeats() {
    bootstrap.Modal.getInstance(document.getElementById("orderModal"))?.hide();
    const dlg = document.querySelector("#orderModal .modal-dialog");
    if (dlg) { dlg.classList.remove("modal-lg"); dlg.classList.add("modal-xl"); }
}

function renderAddSeatsUI(data) {
    const body = document.getElementById("orderModalBody");
    const reservedSeats = data.reserved_seats || {};
    const catColors = {};
    for (const cat of (data.categories || [])) { catColors[cat.id] = cat.color || "#0d6efd"; }
    addSeatsState.categoryColors = catColors;

    let html = \'<div class="alert alert-info py-2"><i class="bi bi-plus-circle me-2"></i><strong>Reservierung erweitern:</strong> Wählen Sie zusätzliche Plätze aus.</div>\';
    html += \'<div class="form-check mb-2"><input class="form-check-input" type="checkbox" id="addseats-send-email"><label class="form-check-label" for="addseats-send-email">Bestätigungs-Mail mit aktualisiertem Ticket-Link an Kunden senden</label></div>\';

    if (!data.use_seat_plan || !data.seatplan || data.seatplan.length === 0) {
        body.innerHTML = html + \'<div class="alert alert-warning py-2">Diese Veranstaltung hat keinen Sitzplan – Hinzufügen ist hier nicht verfügbar.</div>\';
        return;
    }

    html += \'<div style="background:#f8f9fa;padding:1rem;border-radius:0.5rem;overflow-x:auto;">\';
    for (const row of data.seatplan) {
        if (row.type !== "row") continue;
        if (row.no === 0) {
            html += \'<div style="display:flex;align-items:center;margin-bottom:0.5rem;width:100%;"><div style="width:100%;flex:1;background:#212529;color:white;text-align:center;padding:0.5rem;border-radius:0.2rem;font-weight:bold;"><i class="bi bi-music-note-beamed"></i> BÜHNE</div></div>\';
            continue;
        }
        const rowCat = row.category;
        const displayName = row.row_name || ("Reihe " + row.no);
        html += `<div style="display:flex;align-items:center;margin-bottom:0.5rem;gap:0.25rem;"><div style="min-width:60px;font-weight:bold;text-align:right;font-size:0.85rem;">${escHtml(displayName)}</div><div style="display:flex;flex-wrap:nowrap;gap:0.25rem;flex:1;">`;
        for (const el of row.content) {
            if (el.type === "seat") {
                const seatId = "seat_" + row.no + "_" + el.no;
                const catId = el.category || rowCat;
                const color = catColors[catId] || "#0d6efd";
                const isOwn = addSeatsState.ownSeats.includes(seatId);
                const isReserved = !isOwn && (seatId in reservedSeats);
                html += renderAddSeatCell(seatId, el.no, color, isReserved, isOwn);
            } else if (el.type === "table") {
                html += renderAddSeatTable(el, rowCat, catColors, reservedSeats);
            } else if (el.type === "distance" || el.type === "spacer") {
                html += `<span ${el.stairs ? "title=\"Treppe\" " : ""}style="${el.stairs ? "background:#fff;color:#000;border:2px solid #000;" : "background:#6c757d;color:white;border:1px solid #6c757d;"}display:inline-block;box-sizing:border-box;width:2.5rem;min-width:2.5rem;flex:0 0 2.5rem;padding:0.25rem 0;border-radius:0.2rem;font-size:0.75rem;text-align:center;cursor:not-allowed;">${el.stairs ? "" : (el.no || "")}</span>`;
            }
        }
        html += "</div></div>";
    }
    html += "</div>";

    html += \'<div class="mt-2 d-flex flex-wrap gap-3 small">\';
    html += \'<span><span style="display:inline-block;width:16px;height:16px;background:#28a745;border-radius:2px;vertical-align:text-bottom;"></span> Verfügbar</span>\';
    html += \'<span><span style="display:inline-block;width:16px;height:16px;background:#dc3545;border-radius:2px;vertical-align:text-bottom;"></span> Belegt</span>\';
    html += \'<span><span style="display:inline-block;width:16px;height:16px;background:#ffc107;border-radius:2px;vertical-align:text-bottom;"></span> Eigener Platz</span>\';
    html += \'<span><span style="display:inline-block;width:16px;height:16px;background:#0d6efd;border-radius:2px;vertical-align:text-bottom;"></span> Ausgewählt</span>\';
    html += "</div>";

    html += \'<div id="addseats-summary" class="mt-3 small text-muted">Klicken Sie auf grüne Plätze, um sie zur Bestellung hinzuzufügen.</div>\';
    body.innerHTML = html;
}

function renderAddSeatCell(seatId, seatNo, borderColor, isReserved, isOwn) {
    let bg, cursor, onclick, title, textColor = "white";
    if (isOwn) {
        bg = "#ffc107"; textColor = "#212529"; cursor = "not-allowed"; onclick = ""; title = "Eigener Platz";
    } else if (isReserved) {
        bg = "#dc3545"; cursor = "not-allowed"; onclick = ""; title = "Belegt";
    } else {
        bg = "#28a745"; cursor = "pointer"; onclick = `onclick="toggleAddSeat(this,\'${seatId}\')"`; title = "Verfügbar";
    }
    return `<span class="addseats-seat" data-seat-id="${seatId}" style="background:${bg};color:${textColor};border:2px solid ${borderColor};display:inline-block;box-sizing:border-box;width:2.5rem;min-width:2.5rem;flex:0 0 2.5rem;padding:0.25rem 0;border-radius:0.2rem;font-size:0.75rem;text-align:center;cursor:${cursor};user-select:none;" ${onclick} title="${title}">${seatNo}</span>`;
}

function renderAddSeatTable(el, rowCategory, catColors, reservedSeats) {
    const tableSeats = el.seats || {};
    let html = \'<div style="display:inline-flex;flex-direction:column;align-items:center;gap:0.25rem;">\';
    const renderArea = (seats, style) => {
        if (!seats || seats.length === 0) return "";
        let h = `<div style="${style}">`;
        for (const seat of seats) {
            const seatId = "tableseat_" + el.no + "_" + seat.number;
            const catId = seat.category || rowCategory;
            const color = catColors[catId] || "#0d6efd";
            const isOwn = addSeatsState.ownSeats.includes(seatId);
            const isReserved = !isOwn && (seatId in reservedSeats);
            h += renderAddSeatCell(seatId, seat.number, color, isReserved, isOwn);
        }
        return h + "</div>";
    };
    html += renderArea(tableSeats.front, "display:flex;gap:0.25rem;");
    html += \'<div style="display:flex;align-items:center;gap:0.25rem;">\';
    html += renderArea(tableSeats.left, "display:flex;flex-direction:column;gap:0.25rem;");
    html += `<span style="font-size:0.9rem;padding:0.5rem;background:#6c757d;color:white;border:1px solid #5a6268;border-radius:0.2rem;text-align:center;">T${el.no}</span>`;
    html += renderArea(tableSeats.right, "display:flex;flex-direction:column;gap:0.25rem;");
    html += "</div>";
    html += renderArea(tableSeats.back, "display:flex;gap:0.25rem;");
    return html + "</div>";
}

function toggleAddSeat(el, seatId) {
    const idx = addSeatsState.selectedSeats.indexOf(seatId);
    if (idx >= 0) { addSeatsState.selectedSeats.splice(idx, 1); el.style.backgroundColor = "#28a745"; }
    else { addSeatsState.selectedSeats.push(seatId); el.style.backgroundColor = "#0d6efd"; }
    const btn = document.getElementById("addseats-confirm-btn");
    if (btn) btn.disabled = addSeatsState.selectedSeats.length === 0;
    const summary = document.getElementById("addseats-summary");
    if (summary) {
        const n = addSeatsState.selectedSeats.length;
        summary.innerHTML = n === 0 ? "Klicken Sie auf grüne Plätze, um sie zur Bestellung hinzuzufügen." : `<strong>${n}</strong> Platz/Plätze ausgewählt.`;
    }
}

function confirmAddSeats() {
    if (addSeatsState.selectedSeats.length === 0) return;
    if (!confirm(addSeatsState.selectedSeats.length + " Platz/Plätze zur Reservierung hinzufügen?")) return;
    const sendEmail = document.getElementById("addseats-send-email")?.checked || false;
    const btn = document.getElementById("addseats-confirm-btn");
    if (btn) { btn.disabled = true; btn.innerHTML = \'<span class="spinner-border spinner-border-sm"></span> Wird hinzugefügt...\'; }
    fetch(ADD_SEATS_URL, {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ anchor_reservation_id: addSeatsState.anchorId, seats: addSeatsState.selectedSeats, send_email: sendEmail })
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            bootstrap.Modal.getInstance(document.getElementById("orderModal"))?.hide();
            const dlg = document.querySelector("#orderModal .modal-dialog");
            if (dlg) { dlg.classList.remove("modal-lg"); dlg.classList.add("modal-xl"); }
            showFeedback(data.message + (data.email_sent ? " Mail gesendet." : ""), "success");
            setTimeout(() => location.reload(), 1200);
        } else {
            showFeedback("Fehler: " + (data.error || "Unbekannter Fehler"), "error");
            if (btn) { btn.disabled = false; btn.innerHTML = \'<i class="bi bi-check-lg"></i> Hinzufügen bestätigen\'; }
        }
    })
    .catch(err => {
        showFeedback("Fehler: " + err.message, "error");
        if (btn) { btn.disabled = false; btn.innerHTML = \'<i class="bi bi-check-lg"></i> Hinzufügen bestätigen\'; }
    });
}
</script>
';

require __DIR__ . '/../../app/views/layouts/main.php';

