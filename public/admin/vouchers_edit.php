<?php
require_once __DIR__ . '/../../app/bootstrap.php';

use App\Core\Auth;
use App\Models\Voucher;
use App\Models\Customer;
use App\Services\EmailService;
use App\Core\Config;
use App\Core\Database;

Auth::requireAuth();

$voucherModel = new Voucher();
$customerModel = new Customer();
$emailService = new EmailService();
$db = Database::getInstance();
$message = '';
$messageType = '';

$voucherId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$voucher = $voucherModel->getById($voucherId);


if (!$voucher) {
    header('Location: ' . url('admin/vouchers.php'));
    exit;
}

// Voucher-specific statuses (different meaning than reservation statuses)
$statuses = [
    ['id' => 0, 'name' => 'Vorverkauf, nicht eingelöst'],
    ['id' => 1, 'name' => 'Im Warenkorb'],
    ['id' => 2, 'name' => 'Bezahlt, nicht eingelöst'],
    ['id' => 3, 'name' => 'Eingelöst'],
];

// Handle resend email
if (isset($_GET['resend_email'])) {
    try {
        if ($voucher['customer_id']) {
            $customer = $customerModel->getById($voucher['customer_id']);
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
                    $message = 'E-Mail erfolgreich versendet!';
                    $messageType = 'success';
                    // Reload voucher
                    $voucher = $voucherModel->getById($voucherId);
                } else {
                    $message = 'E-Mail konnte nicht versendet werden.';
                    $messageType = 'danger';
                }
            } else {
                $message = 'Keine E-Mail-Adresse für diesen Kunden gefunden.';
                $messageType = 'warning';
            }
        } else {
            $message = 'Kein Kunde diesem Gutschein zugeordnet.';
            $messageType = 'warning';
        }
    } catch (\Exception $e) {
        $message = 'Fehler beim Versenden: ' . $e->getMessage();
        $messageType = 'danger';
    }
}

// Handle form submission
// CSRF-Schutz für alle POST-Aktionen dieser Seite
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !\App\Core\Csrf::validatePost()) {
    http_response_code(403);
    die('Ungültige Anfrage: Sicherheits-Token fehlt oder ist abgelaufen. Bitte die Seite neu laden und erneut versuchen.');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $newData = [
            'customer_id'   => !empty($_POST['customer_id']) ? (int)$_POST['customer_id'] : null,
            'status_id'     => (int)$_POST['status_id'],
            'is_reduced'    => isset($_POST['is_reduced']) ? 1 : 0,
            'is_fn_card'    => isset($_POST['is_fn_card']) ? 1 : 0,
            'mail_sent'     => isset($_POST['mail_sent']) ? 1 : 0,
            'mail_sent_date'=> !empty($_POST['mail_sent_date']) ? $_POST['mail_sent_date'] : null,
        ];
        $oldStatusId = (int)$voucher['status_id'];
        $voucherModel->update($voucherId, $newData);

        \App\Services\LoggerService::logAdmin('voucher_updated', [
            'voucher_id'   => $voucherId,
            'voucher_code' => $voucher['voucher_code'] ?? null,
            'changes'      => $newData,
            'admin_id'     => \App\Core\Auth::id(),
            'admin_user'   => \App\Core\Auth::username(),
        ]);

        if ($oldStatusId !== (int)$newData['status_id']) {
            logVoucherStatusChange('admin', $voucher['voucher_code'], $oldStatusId, (int)$newData['status_id'], 'admin_edit', [
                'voucher_id' => $voucherId,
                'admin_id'   => \App\Core\Auth::id(),
                'admin_user' => \App\Core\Auth::username(),
            ]);
        }

        $message = 'Änderungen erfolgreich gespeichert.';
        $messageType = 'success';
        // Reload voucher
        $voucher = $voucherModel->getById($voucherId);
    } catch (\Exception $e) {
        $message = 'Fehler beim Speichern: ' . $e->getMessage();
        $messageType = 'danger';
    }
}

$pageTitle = 'Gutschein bearbeiten';
$showSidebar = true;
$showHeader = true;

$content = '
<div class="card">
    <div class="card-header">
        <h5 class="card-title mb-0"><i class="bi bi-pencil"></i> Gutschein bearbeiten</h5>
    </div>
    <div class="card-body">
        ' . ($message ? '<div class="alert alert-' . $messageType . ' alert-dismissible fade show" role="alert">' . htmlspecialchars($message) . '<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>' : '') . '
        
        <div class="mb-4">
            <h6>Gutschein-Informationen</h6>
            <dl class="row">
                <dt class="col-sm-3">Gutscheincode:</dt>
                <dd class="col-sm-9"><code>' . htmlspecialchars($voucher['voucher_code']) . '</code></dd>
                
                <dt class="col-sm-3">Veranstaltung:</dt>
                <dd class="col-sm-9">' . htmlspecialchars($voucher['event_name'] ?? '-') . '</dd>
                
                <dt class="col-sm-3">Kategorie:</dt>
                <dd class="col-sm-9">' . htmlspecialchars($voucher['category_name'] ?? '-') . '</dd>
                
                <dt class="col-sm-3">Anzahl:</dt>
                <dd class="col-sm-9">' . htmlspecialchars($voucher['quantity']) . '</dd>
            </dl>
        </div>
        
        <form method="POST" action="">
            ' . \App\Core\Csrf::field() . '
            <div class="row">
                <div class="col-md-6">
                    <div class="mb-3">
                        <label for="status_id" class="form-label">Status</label>
                        <select class="form-select" id="status_id" name="status_id">
';

foreach ($statuses as $status) {
    $selected = (($_POST['status_id'] ?? $voucher['status_id']) == $status['id']) ? 'selected' : '';
    $content .= '<option value="' . $status['id'] . '" ' . $selected . '>' . htmlspecialchars($status['name']) . '</option>';
}

$content .= '
                        </select>
                    </div>
                    
                    <div class="mb-3">
                        <label for="customer_search" class="form-label">Kunde (optional)</label>
                        <div class="position-relative">
                            <input type="text" class="form-control" id="customer_search" placeholder="Name oder E-Mail eingeben..." autocomplete="off">
                            <input type="hidden" id="customer_id" name="customer_id" value="">
                            <div id="customer_suggestions" class="list-group position-absolute w-100" style="z-index:1000;display:none;"></div>
                        </div>
                        <div id="customer_selected" style="display:none;margin-top:.25rem;">
                            <span class="badge bg-secondary" id="customer_selected_label"></span>
                            <button type="button" class="btn btn-link btn-sm p-0 ms-1 text-danger" onclick="clearCustomer()">&#x2715; Auswahl aufheben</button>
                        </div>
                    </div>';

// Pre-fill current customer
$currentCustomerId = $voucher['customer_id'] ?? null;
$currentCustomerLabel = '';
if ($currentCustomerId) {
    $fn = trim(($voucher['customer_first_name'] ?? '') . ' ' . ($voucher['customer_last_name'] ?? ''));
    $em = $voucher['customer_email'] ?? '';
    $currentCustomerLabel = $fn ? $fn . ($em ? ' (' . htmlspecialchars($em) . ')' : '') : htmlspecialchars($em ?: 'Kunde #' . $currentCustomerId);
}

$content .= '

                    
                    <div class="mb-3 form-check form-switch">
                        <input class="form-check-input" type="checkbox" id="is_reduced" name="is_reduced" 
                               ' . (($_POST['is_reduced'] ?? $voucher['is_reduced']) ? 'checked' : '') . '>
                        <label class="form-check-label" for="is_reduced">Ermäßigt</label>
                    </div>
                    
                    <div class="mb-3 form-check form-switch">
                        <input class="form-check-input" type="checkbox" id="is_fn_card" name="is_fn_card" 
                               ' . (($_POST['is_fn_card'] ?? $voucher['is_fn_card']) ? 'checked' : '') . '>
                        <label class="form-check-label" for="is_fn_card">FN-Karte</label>
                    </div>
                </div>
                
                <div class="col-md-6">
                    <div class="mb-3 form-check form-switch">
                        <input class="form-check-input" type="checkbox" id="mail_sent" name="mail_sent" 
                               ' . (($_POST['mail_sent'] ?? $voucher['mail_sent']) ? 'checked' : '') . '>
                        <label class="form-check-label" for="mail_sent">E-Mail gesendet</label>
                    </div>
                    
                    <div class="mb-3">
                        <label for="mail_sent_date" class="form-label">E-Mail gesendet am</label>
                        <input type="date" class="form-control" id="mail_sent_date" name="mail_sent_date" 
                               value="' . htmlspecialchars($_POST['mail_sent_date'] ?? ($voucher['mail_sent_date'] ?? '')) . '">
                    </div>
                </div>
            </div>
            
            <div class="mt-4">
                <button type="submit" class="btn btn-primary">
                    <i class="bi bi-save"></i> Änderungen speichern
                </button>
                ' . ($voucher['customer_id'] ? '
                <a href="' . url('admin/vouchers_edit.php?id=' . $voucherId . '&resend_email=1') . '" class="btn btn-success" onclick="return confirm(\'Möchten Sie die E-Mail wirklich erneut versenden?\')">
                    <i class="bi bi-envelope"></i> E-Mail erneut senden
                </a>
                ' : '') . '
                <a href="' . url('admin/vouchers.php') . '" class="btn btn-secondary">
                    <i class="bi bi-x-circle"></i> Abbrechen
                </a>
            </div>
        </form>
    </div>
</div>
';

$customerSearchUrl = url('api/customer_search.php');
$initId    = (int)($currentCustomerId ?? 0);
$initLabel = addslashes($currentCustomerLabel);
$content .= '<script>
(function() {
    const searchInput  = document.getElementById("customer_search");
    const hiddenInput  = document.getElementById("customer_id");
    const suggestions  = document.getElementById("customer_suggestions");
    const selectedBox  = document.getElementById("customer_selected");
    const selectedLbl  = document.getElementById("customer_selected_label");
    let debounceTimer;

    // Pre-fill if a customer is already assigned
    if (' . ($initId ? 'true' : 'false') . ') {
        hiddenInput.value     = ' . $initId . ';
        selectedLbl.textContent = "' . $initLabel . '";
        selectedBox.style.display = "block";
    }

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
                        const name  = (c.first_name + " " + c.last_name).trim() || c.email;
                        const label = name + (c.email ? " (" + c.email + ")" : "");
                        const item  = document.createElement("button");
                        item.type   = "button";
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
</script>';

require __DIR__ . '/../../app/views/layouts/main.php';

