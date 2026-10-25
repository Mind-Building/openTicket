<?php
require_once __DIR__ . '/../../app/bootstrap.php';

use App\Core\Auth;
use App\Models\Voucher;
use App\Models\Event;
use App\Models\SeatCategory;
use App\Models\Customer;
use App\Services\EmailService;
use App\Core\Config;

Auth::requireAuth();

$voucherModel = new Voucher();
$eventModel = new Event();
$categoryModel = new SeatCategory();
$customerModel = new Customer();
$emailService = new EmailService();

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
        $quantity = (int)$_POST['quantity'];
        $customerId = !empty($_POST['customer_id']) ? (int)$_POST['customer_id'] : null;
        $isReduced = isset($_POST['is_reduced']) ? 1 : 0;
        $isFnCard = isset($_POST['is_fn_card']) ? 1 : 0;
        $sendEmail = isset($_POST['send_email']) ? 1 : 0;
        
        if ($eventId <= 0 || $categoryId <= 0 || $quantity <= 0) {
            throw new \Exception('Bitte füllen Sie alle Pflichtfelder aus.');
        }
        
        // Create one voucher with the given quantity (1 code covers multiple tickets)
        $voucherId = $voucherModel->create([
            'event_id' => $eventId,
            'category_id' => $categoryId,
            'quantity' => $quantity,
            'customer_id' => $customerId,
            'session_id' => 'admin_' . uniqid(),
            'status_id' => 0,
            'is_reduced' => $isReduced,
            'is_fn_card' => $isFnCard,
        ]);

        // Get created voucher with relations
        $voucher = $voucherModel->getById($voucherId);

        // Send email if requested and customer is assigned
        if ($sendEmail && $customerId && $voucher) {
            $customer = $customerModel->getById($customerId);
            if ($customer && !empty($customer['email'])) {
                $baseUrl = Config::get('ticket_url_start', absoluteUrl(''));
                $voucherLink = $baseUrl . '/vouchers.php';

                $emailSent = $emailService->sendVoucherEmail(
                    $customer['email'],
                    trim(($customer['first_name'] ?? '') . ' ' . ($customer['last_name'] ?? '')),
                    $voucher,
                    $voucherLink
                );

                if ($emailSent) {
                    $voucherModel->update($voucherId, [
                        'mail_sent' => 1,
                        'mail_sent_date' => date('Y-m-d')
                    ]);
                    $message = 'Gutschein erfolgreich erstellt und E-Mail versendet!';
                } else {
                    $message = 'Gutschein erfolgreich erstellt, aber E-Mail konnte nicht versendet werden.';
                    $messageType = 'warning';
                }
            } else {
                $message = 'Gutschein erfolgreich erstellt, aber E-Mail konnte nicht versendet werden (keine E-Mail-Adresse).';
                $messageType = 'warning';
            }
        } else {
            $message = 'Gutschein erfolgreich erstellt!';
        }

        $messageType = $messageType ?: 'success';

        \App\Services\LoggerService::logAdmin('voucher_created', [
            'voucher_id'   => $voucherId,
            'voucher_code' => $voucher['voucher_code'] ?? null,
            'event_id'     => $eventId,
            'category_id'  => $categoryId,
            'quantity'     => $quantity,
            'customer_id'  => $customerId,
            'email_sent'   => $sendEmail && $customerId,
            'admin_id'     => \App\Core\Auth::id(),
            'admin_user'   => \App\Core\Auth::username(),
        ]);

        // Log status change for the new voucher (created with status 0)
        if ($voucher) {
            logVoucherStatusChange('admin', $voucher['voucher_code'], null, 0, 'admin_create', [
                'voucher_id'  => $voucherId,
                'quantity'    => $quantity,
                'admin_id'    => \App\Core\Auth::id(),
                'customer_id' => $customerId,
            ]);
        }

        // Redirect to edit page
        header('Location: ' . url('admin/vouchers_edit.php?id=' . $voucherId . '&success=1'));
        exit;
    } catch (\Exception $e) {
        $message = 'Fehler beim Erstellen: ' . $e->getMessage();
        $messageType = 'danger';
    }
}

$pageTitle = 'Neuer Gutschein';
$showSidebar = true;
$showHeader = true;

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
                    </div>
                    
                    <div class="mb-3">
                        <label for="customer_search" class="form-label">Kunde (optional)</label>
                        <div class="position-relative">
                            <input type="text" class="form-control" id="customer_search" placeholder="Name oder E-Mail eingeben..." autocomplete="off">
                            <input type="hidden" id="customer_id" name="customer_id" value="">
                            <div id="customer_suggestions" class="list-group position-absolute w-100" style="z-index:1000;display:none;"></div>
                        </div>
                        <div id="customer_selected" class="mt-1" style="display:none;">
                            <span class="badge bg-secondary" id="customer_selected_label"></span>
                            <button type="button" class="btn btn-link btn-sm p-0 ms-1 text-danger" onclick="clearCustomer()">&#x2715; Auswahl aufheben</button>
                        </div>
                        <small class="form-text text-muted">Wenn ein Kunde zugeordnet wird, kann der Gutschein per E-Mail versendet werden.</small>
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
                <a href="' . url('admin/vouchers.php') . '" class="btn btn-secondary">
                    <i class="bi bi-x-circle"></i> Abbrechen
                </a>
            </div>
        </form>
    </div>
</div>

';

$customerSearchUrl = url('api/customer_search.php');
$content .= '<script>
(function() {
    const searchInput = document.getElementById("customer_search");
    const hiddenInput = document.getElementById("customer_id");
    const suggestions = document.getElementById("customer_suggestions");
    const selectedBox = document.getElementById("customer_selected");
    const selectedLabel = document.getElementById("customer_selected_label");
    let debounceTimer;

    searchInput.addEventListener("input", function() {
        clearTimeout(debounceTimer);
        const q = this.value.trim();
        if (q.length < 2) { suggestions.style.display = "none"; return; }
        debounceTimer = setTimeout(() => {
            fetch("' . $customerSearchUrl . '?q=" + encodeURIComponent(q))
                .then(r => r.json())
                .then(data => {
                    suggestions.innerHTML = "";
                    if (!data.success || !data.results.length) { suggestions.style.display = "none"; return; }
                    data.results.forEach(c => {
                        const name = (c.first_name + " " + c.last_name).trim() || c.email;
                        const label = name + (c.email ? " (" + c.email + ")" : "");
                        const item = document.createElement("button");
                        item.type = "button";
                        item.className = "list-group-item list-group-item-action";
                        item.textContent = label;
                        item.addEventListener("click", () => selectCustomer(c.id, label));
                        suggestions.appendChild(item);
                    });
                    suggestions.style.display = "block";
                });
        }, 250);
    });

    document.addEventListener("click", function(e) {
        if (!suggestions.contains(e.target) && e.target !== searchInput) suggestions.style.display = "none";
    });
})();

function selectCustomer(id, label) {
    document.getElementById("customer_id").value = id;
    document.getElementById("customer_search").value = "";
    document.getElementById("customer_suggestions").style.display = "none";
    document.getElementById("customer_selected_label").textContent = label;
    document.getElementById("customer_selected").style.display = "block";
}

function clearCustomer() {
    document.getElementById("customer_id").value = "";
    document.getElementById("customer_selected").style.display = "none";
    document.getElementById("customer_selected_label").textContent = "";
    document.getElementById("customer_search").value = "";
}

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

