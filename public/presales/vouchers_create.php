<?php
require_once __DIR__ . '/../../app/bootstrap.php';

use App\Core\Auth;
use App\Models\Voucher;
use App\Models\Event;
use App\Models\Customer;
use App\Services\EmailService;
use App\Core\Config;
use App\Core\Session;

Auth::requirePresales();
$vouchersActive = Config::get('voucher_active', false) && Config::get('presales_allow_vouchers', true);
if (!$vouchersActive) { header('Location: ' . url('presales/index.php')); exit; }

$voucherModel = new Voucher();
$eventModel = new Event();
$emailService = new EmailService();
$customerModel = new Customer();

$message = '';
$messageType = '';

// Get events for dropdown
$events = $eventModel->getAll();

// Handle form submission
// CSRF-Schutz für alle POST-Aktionen dieser Seite
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !\App\Core\Csrf::validatePost()) {
    http_response_code(403);
    die('Ungültige Anfrage: Sicherheits-Token fehlt oder ist abgelaufen. Bitte die Seite neu laden und erneut versuchen.');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $eventId = (int)$_POST['event_id'];
        $categoryId = (int)$_POST['category_id'];
        $quantity = max(1, (int)$_POST['quantity']);
        $isReduced = isset($_POST['is_reduced']) ? 1 : 0;
        $isFnCard = isset($_POST['is_fn_card']) ? 1 : 0;
        $sendEmail = isset($_POST['send_email']) ? 1 : 0;
        $customerType = $_POST['customer_type'] ?? 'none'; // 'existing' or 'new' (none is not allowed in presales)

        if ($eventId <= 0 || $categoryId <= 0) {
            throw new \Exception('Bitte füllen Sie alle Pflichtfelder aus.');
        }

        if ($customerType === 'none' || empty($customerType)) {
            throw new \Exception('Im Vorverkauf muss jedem Gutschein ein Kunde zugeordnet sein. Bitte einen bestehenden oder neuen Kunden angeben.');
        }

        // Resolve customer ID
        $customerId = null;
        if ($customerType === 'existing') {
            $customerId = !empty($_POST['customer_id']) ? (int)$_POST['customer_id'] : null;
            if (!$customerId) {
                throw new \Exception('Bitte einen bestehenden Kunden aus der Liste auswählen.');
            }
        } elseif ($customerType === 'new') {
            $required = ['first_name', 'last_name', 'street', 'house_number', 'postal_code', 'city', 'email'];
            foreach ($required as $field) {
                if (empty(trim($_POST[$field] ?? ''))) {
                    throw new \Exception('Bitte alle Pflichtfelder des neuen Kunden ausfüllen.');
                }
            }
            $email = trim($_POST['email']);
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new \Exception('Ungültige E-Mail-Adresse.');
            }
            $newCustomerData = [
                'salutation'   => $_POST['salutation'] ?? null,
                'first_name'   => trim($_POST['first_name']),
                'last_name'    => trim($_POST['last_name']),
                'street'       => trim($_POST['street']),
                'house_number' => trim($_POST['house_number']),
                'postal_code'  => trim($_POST['postal_code']),
                'city'         => trim($_POST['city']),
                'email'        => $email,
                'phone_prefix' => trim($_POST['phone_prefix'] ?? ''),
                'phone_number' => trim($_POST['phone_number'] ?? ''),
                'session_id'   => session_id(),
                'item_type'    => 'voucher',
            ];
            $customerId = $customerModel->createOrGet($newCustomerData);
        }

        // Create one voucher with the given quantity (1 code covers multiple tickets)
        $voucherId = $voucherModel->create([
            'event_id'     => $eventId,
            'category_id'  => $categoryId,
            'quantity'     => $quantity,
            'customer_id'  => $customerId,
            'session_id'   => 'presales_' . uniqid(),
            'status_id'    => 0,
            'is_reduced'   => $isReduced,
            'is_fn_card'   => $isFnCard,
        ]);
        $createdVoucherIds = [$voucherId];

        // Fetch created voucher with relations
        $createdVouchers = array_filter([$voucherModel->getById($voucherId)]);

        // Log presales voucher creation
        $logUserId = (string)Auth::id();
        if (!empty($createdVouchers)) {
            $firstVoucher = array_values($createdVouchers)[0];
            $value    = (float)($firstVoucher['category_price'] ?? 0) * $quantity;
            $codes    = implode(', ', array_column(array_values($createdVouchers), 'voucher_code'));
            $logCustomerName = null;
            $logEmailHash    = null;
            if ($customerId) {
                $logCust = $customerModel->getById($customerId);
                if ($logCust) {
                    $logCustomerName = trim(($logCust['first_name'] ?? '') . ' ' . ($logCust['last_name'] ?? ''));
                    if (!empty($logCust['email'])) {
                        $logEmailHash = substr(md5($logCust['email']), 0, 8);
                    }
                }
            }
            logPresalesVoucherCreate($logUserId, $codes, $value, $logCustomerName, $logEmailHash);

            // Log status change for the new voucher (created with status 0)
            foreach ($createdVouchers as $cv) {
                logVoucherStatusChange('presales', $cv['voucher_code'], null, 0, 'presales_create', [
                    'voucher_id'  => $cv['id'],
                    'quantity'    => $quantity,
                    'presales_id' => $logUserId,
                    'customer_id' => $customerId,
                ]);
            }
        }

        // Send email if requested and customer is assigned
        if ($sendEmail && $customerId && !empty($createdVouchers)) {
            $customer = $customerModel->getById($customerId);
            if ($customer && !empty($customer['email'])) {
                $baseUrl = Config::get('ticket_url_start', absoluteUrl(''));
                $baseUrl = ensurePublicInUrl($baseUrl);
                $voucherLink = $baseUrl . '/vouchers.php';

                $emailSent = $emailService->sendVoucherEmail(
                    $customer['email'],
                    trim(($customer['first_name'] ?? '') . ' ' . ($customer['last_name'] ?? '')),
                    array_values($createdVouchers),
                    $voucherLink
                );

                // Log e-mail send attempt
                logPresalesEmailSent(
                    $logUserId,
                    'voucher',
                    substr(md5($customer['email']), 0, 8),
                    $emailSent,
                    ['voucher_count' => $quantity]
                );

                if ($emailSent) {
                    // Mark all created vouchers as mail sent
                    foreach ($createdVoucherIds as $vid) {
                        $voucherModel->update($vid, [
                            'mail_sent'      => 1,
                            'mail_sent_date' => date('Y-m-d'),
                        ]);
                    }
                    $message = 'Gutschein erfolgreich erstellt und E-Mail versendet!';
                } else {
                    $message = 'Gutschein(e) erfolgreich erstellt, aber E-Mail konnte nicht versendet werden.';
                    $messageType = 'warning';
                }
            } else {
                logPresalesEmailSent($logUserId, 'voucher', '', false, ['reason' => 'no_email_address', 'voucher_count' => $quantity]);
                $message = 'Gutschein(e) erfolgreich erstellt, aber E-Mail konnte nicht versendet werden (keine E-Mail-Adresse).';
                $messageType = 'warning';
            }
        } else {
            $message = 'Gutschein erfolgreich erstellt!';
        }

        $messageType = $messageType ?: 'success';

        // Redirect to vouchers list
        header('Location: ' . url('presales/vouchers.php?success=1&message=' . urlencode($message)));
        exit;
    } catch (\Exception $e) {
        $message = 'Fehler beim Erstellen: ' . $e->getMessage();
        $messageType = 'danger';
    }
}

$pageTitle = 'Neuer Gutschein';
$showSidebar = true;
$showHeader = true;

$customerSearchUrl = json_encode(url('api/customer_search.php'));

$content = '
<div class="card">
    <div class="card-header">
        <h5 class="card-title mb-0"><i class="bi bi-plus-circle"></i> Neuer Gutschein</h5>
    </div>
    <div class="card-body">
        ' . ($message ? '<div class="alert alert-' . $messageType . ' alert-dismissible fade show" role="alert">' . htmlspecialchars($message) . '<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>' : '') . '

        <form method="POST" action="">
            ' . \App\Core\Csrf::field() . '
            <div class="row">
                <div class="col-md-6">
                    <div class="mb-3">
                        <label for="event_id" class="form-label">Veranstaltung <span class="text-danger">*</span></label>
                        <select class="form-select" id="event_id" name="event_id" required onchange="loadCategories()">
                            <option value="">Bitte wählen...</option>';
foreach ($events as $event) {
    $content .= '<option value="' . $event['id'] . '">' . htmlspecialchars($event['name']) . '</option>';
}
$content .= '
                        </select>
                    </div>

                    <div class="mb-3">
                        <label for="category_id" class="form-label">Kategorie <span class="text-danger">*</span></label>
                        <select class="form-select" id="category_id" name="category_id" required>
                            <option value="">Bitte zuerst eine Veranstaltung wählen...</option>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label for="quantity" class="form-label">Anzahl <span class="text-danger">*</span></label>
                        <input type="number" class="form-control" id="quantity" name="quantity" min="1" value="1" required>
                        <small class="form-text text-muted">Ein Gutscheincode gilt für die angegebene Anzahl an Tickets.</small>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Kunde <span class="text-danger">*</span></label>
                        <div class="alert alert-info py-1 px-2 mb-2 small"><i class="bi bi-info-circle"></i> Im Vorverkauf ist die Angabe eines Kunden <strong>Pflicht</strong>.</div>
                        <div class="btn-group w-100 mb-2" role="group">
                            <input type="radio" class="btn-check" name="customer_type" id="ct_existing" value="existing" checked onchange="toggleCustomerType()">
                            <label class="btn btn-outline-secondary btn-sm" for="ct_existing">Bestehender Kunde</label>
                            <input type="radio" class="btn-check" name="customer_type" id="ct_new" value="new" onchange="toggleCustomerType()">
                            <label class="btn btn-outline-secondary btn-sm" for="ct_new">Neuer Kunde</label>
                        </div>

                        <div id="customer-existing-section" style="display:none;">
                            <input type="hidden" id="customer_id" name="customer_id" value="">
                            <div class="position-relative">
                                <input type="text" class="form-control" id="customer_search_input"
                                       placeholder="Name oder E-Mail eingeben..." autocomplete="off"
                                       oninput="searchCustomer(this.value)">
                                <div id="customer_search_results" class="list-group position-absolute w-100 shadow" style="z-index:1000;display:none;max-height:220px;overflow-y:auto;"></div>
                            </div>
                            <div id="customer_selected" class="mt-1 text-success small" style="display:none;"></div>
                        </div>

                        <div id="customer-new-section" style="display:none;">
                            <div class="row">
                                <div class="col-md-3 mb-2">
                                    <select class="form-select form-select-sm" name="salutation">
                                        <option value="">Anrede</option>
                                        <option value="Herr">Herr</option>
                                        <option value="Frau">Frau</option>
                                    </select>
                                </div>
                                <div class="col-md-4 mb-2">
                                    <input type="text" class="form-control form-control-sm" name="first_name" placeholder="Vorname *">
                                </div>
                                <div class="col-md-5 mb-2">
                                    <input type="text" class="form-control form-control-sm" name="last_name" placeholder="Nachname *">
                                </div>
                            </div>
                            <div class="row">
                                <div class="col-md-8 mb-2">
                                    <input type="text" class="form-control form-control-sm" name="street" placeholder="Straße *">
                                </div>
                                <div class="col-md-4 mb-2">
                                    <input type="text" class="form-control form-control-sm" name="house_number" placeholder="Nr. *">
                                </div>
                            </div>
                            <div class="row">
                                <div class="col-md-4 mb-2">
                                    <input type="text" class="form-control form-control-sm" name="postal_code" placeholder="PLZ *">
                                </div>
                                <div class="col-md-8 mb-2">
                                    <input type="text" class="form-control form-control-sm" name="city" placeholder="Ort *">
                                </div>
                            </div>
                            <div class="row">
                                <div class="col-md-6 mb-2">
                                    <input type="email" class="form-control form-control-sm" name="email" placeholder="E-Mail *">
                                </div>
                                <div class="col-md-2 mb-2">
                                    <input type="text" class="form-control form-control-sm" name="phone_prefix" placeholder="Vorwahl">
                                </div>
                                <div class="col-md-4 mb-2">
                                    <input type="text" class="form-control form-control-sm" name="phone_number" placeholder="Telefon">
                                </div>
                            </div>
                        </div>
                        <small class="form-text text-muted">Der zugeordnete Kunde erhält den Gutschein optional per E-Mail.</small>
                    </div>
                </div>

                <div class="col-md-6">
                    <div class="mb-3 form-check form-switch">
                        <input class="form-check-input" type="checkbox" id="is_reduced" name="is_reduced">
                        <label class="form-check-label" for="is_reduced">Ermäßigt</label>
                    </div>

                    <div class="mb-3 form-check form-switch">
                        <input class="form-check-input" type="checkbox" id="is_fn_card" name="is_fn_card">
                        <label class="form-check-label" for="is_fn_card">FN-Karte</label>
                    </div>

                    <div class="mb-3 form-check form-switch">
                        <input class="form-check-input" type="checkbox" id="send_email" name="send_email" checked>
                        <label class="form-check-label" for="send_email">Gutschein per E-Mail versenden (nur wenn Kunde zugeordnet)</label>
                    </div>
                </div>
            </div>

            <div class="mt-4">
                <button type="submit" class="btn btn-primary">
                    <i class="bi bi-save"></i> Gutschein erstellen
                </button>
                <a href="' . url('presales/vouchers.php') . '" class="btn btn-secondary">
                    <i class="bi bi-x-circle"></i> Abbrechen
                </a>
            </div>
        </form>
    </div>
</div>

';

$content .= '<script>
const CUSTOMER_SEARCH_URL = ' . $customerSearchUrl . ';
let searchTimer = null;

function toggleCustomerType() {
    const type = document.querySelector("input[name=customer_type]:checked")?.value;
    document.getElementById("customer-existing-section").style.display = (type === "existing") ? "" : "none";
    document.getElementById("customer-new-section").style.display = (type === "new") ? "" : "none";
    if (type !== "existing") {
        document.getElementById("customer_id").value = "";
        document.getElementById("customer_search_input").value = "";
        document.getElementById("customer_selected").style.display = "none";
        document.getElementById("customer_search_results").style.display = "none";
    }
}
// Show existing customer section by default on page load
document.addEventListener("DOMContentLoaded", function() { toggleCustomerType(); });

function searchCustomer(query) {
    const resultsEl = document.getElementById("customer_search_results");
    const hiddenId  = document.getElementById("customer_id");
    const selectedEl = document.getElementById("customer_selected");

    // Clear previous selection when typing again
    hiddenId.value = "";
    selectedEl.style.display = "none";

    clearTimeout(searchTimer);
    if (query.length < 2) {
        resultsEl.style.display = "none";
        resultsEl.innerHTML = "";
        return;
    }

    searchTimer = setTimeout(() => {
        fetch(CUSTOMER_SEARCH_URL + "?q=" + encodeURIComponent(query), { credentials: "same-origin" })
            .then(r => r.json())
            .then(data => {
                resultsEl.innerHTML = "";
                if (!data.success || !data.results.length) {
                    resultsEl.innerHTML = \'<span class="list-group-item text-muted small">Keine Kunden gefunden.</span>\';
                    resultsEl.style.display = "block";
                    return;
                }
                data.results.forEach(c => {
                    const name = [c.salutation, c.first_name, c.last_name].filter(Boolean).join(" ");
                    const label = name + (c.email ? " — " + c.email : "") + (c.city ? " (" + c.city + ")" : "");
                    const btn = document.createElement("button");
                    btn.type = "button";
                    btn.className = "list-group-item list-group-item-action small";
                    btn.textContent = label;
                    btn.onclick = () => selectCustomer(c.id, label);
                    resultsEl.appendChild(btn);
                });
                resultsEl.style.display = "block";
            })
            .catch(() => {
                resultsEl.innerHTML = \'<span class="list-group-item text-danger small">Fehler bei der Suche.</span>\';
                resultsEl.style.display = "block";
            });
    }, 250);
}

function selectCustomer(id, label) {
    document.getElementById("customer_id").value = id;
    document.getElementById("customer_search_input").value = label;
    document.getElementById("customer_search_results").style.display = "none";
    const sel = document.getElementById("customer_selected");
    sel.textContent = "✓ Ausgewählt";
    sel.style.display = "block";
}

// Close dropdown when clicking outside
document.addEventListener("click", function(e) {
    const el = document.getElementById("customer_search_results");
    if (el && !el.contains(e.target) && e.target.id !== "customer_search_input") {
        el.style.display = "none";
    }
});

function loadCategories() {
    const eventId = document.getElementById("event_id").value;
    const categorySelect = document.getElementById("category_id");

    categorySelect.innerHTML = "<option value=\'\'>Lade Kategorien...</option>";

    if (!eventId) {
        categorySelect.innerHTML = "<option value=\'\'>Bitte zuerst eine Veranstaltung wählen...</option>";
        return;
    }

    fetch("' . url('api/get_categories.php') . '?event_id=" + eventId)
        .then(response => response.json())
        .then(data => {
            categorySelect.innerHTML = "<option value=\'\'>Bitte wählen...</option>";
            if (data.categories) {
                data.categories.forEach(category => {
                    const option = document.createElement("option");
                    option.value = category.id;
                    option.textContent = category.name + " (" + parseFloat(category.price).toFixed(2) + " €)";
                    categorySelect.appendChild(option);
                });
            }
        })
        .catch(error => {
            console.error("Error loading categories:", error);
            categorySelect.innerHTML = "<option value=\'\'>Fehler beim Laden der Kategorien</option>";
        });
}
</script>';

require __DIR__ . '/../../app/views/layouts/main.php';
