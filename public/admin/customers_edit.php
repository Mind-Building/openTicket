<?php
require_once __DIR__ . '/../../app/bootstrap.php';

use App\Core\Auth;
use App\Models\Customer;

Auth::requireAdmin();

$customerModel = new Customer();
$message = '';
$messageType = '';

$customerId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$customer = $customerModel->getById($customerId);

if (!$customer) {
    header('Location: ' . url('admin/customers.php'));
    exit;
}

// Handle form submission
// CSRF-Schutz für alle POST-Aktionen dieser Seite
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !\App\Core\Csrf::validatePost()) {
    http_response_code(403);
    die('Ungültige Anfrage: Sicherheits-Token fehlt oder ist abgelaufen. Bitte die Seite neu laden und erneut versuchen.');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        // Validate input
        if (empty($_POST['first_name']) || empty($_POST['last_name']) || empty($_POST['email'])) {
            throw new \Exception('Bitte füllen Sie alle Pflichtfelder aus.');
        }
        
        $customerModel->update($customerId, [
            'salutation' => $_POST['salutation'] ?? null,
            'first_name' => $_POST['first_name'],
            'last_name' => $_POST['last_name'],
            'street' => $_POST['street'],
            'house_number' => $_POST['house_number'],
            'postal_code' => $_POST['postal_code'],
            'city' => $_POST['city'],
            'email' => $_POST['email'],
            'phone_prefix' => $_POST['phone_prefix'] ?? null,
            'phone_number' => $_POST['phone_number'] ?? null,
            'item_type' => $_POST['item_type'] ?? 'ticket',
        ]);
        
        header('Location: ' . url('admin/customers.php'));
        exit;
    } catch (\Exception $e) {
        $message = $e->getMessage();
        $messageType = 'danger';
    }
}

$pageTitle = 'Kunde bearbeiten';
$showSidebar = true;
$showHeader = true;

$content = '
<div class="card">
    <div class="card-header">
        <h5 class="card-title mb-0"><i class="bi bi-pencil"></i> Kunde bearbeiten</h5>
    </div>
    <div class="card-body">
        ' . ($message ? '<div class="alert alert-' . $messageType . ' alert-dismissible fade show" role="alert">' . htmlspecialchars($message) . '<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>' : '') . '
        
        <form method="POST" action="">
            ' . \App\Core\Csrf::field() . '
            <div class="row">
                <div class="col-md-6">
                    <div class="mb-3">
                        <label for="salutation" class="form-label">Anrede</label>
                        <select class="form-select" id="salutation" name="salutation">
                            <option value="">-</option>
                            <option value="Herr" ' . (($_POST['salutation'] ?? $customer['salutation']) == 'Herr' ? 'selected' : '') . '>Herr</option>
                            <option value="Frau" ' . (($_POST['salutation'] ?? $customer['salutation']) == 'Frau' ? 'selected' : '') . '>Frau</option>
                        </select>
                    </div>
                    
                    <div class="mb-3">
                        <label for="first_name" class="form-label">Vorname <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="first_name" name="first_name" required 
                               value="' . htmlspecialchars($_POST['first_name'] ?? $customer['first_name']) . '">
                    </div>
                    
                    <div class="mb-3">
                        <label for="last_name" class="form-label">Nachname <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="last_name" name="last_name" required 
                               value="' . htmlspecialchars($_POST['last_name'] ?? $customer['last_name']) . '">
                    </div>
                    
                    <div class="mb-3">
                        <label for="email" class="form-label">E-Mail <span class="text-danger">*</span></label>
                        <input type="email" class="form-control" id="email" name="email" required 
                               value="' . htmlspecialchars($_POST['email'] ?? $customer['email']) . '">
                    </div>
                </div>
                
                <div class="col-md-6">
                    <div class="mb-3">
                        <label for="street" class="form-label">Straße <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="street" name="street" required 
                               value="' . htmlspecialchars($_POST['street'] ?? $customer['street']) . '">
                    </div>
                    
                    <div class="row">
                        <div class="col-md-4">
                            <div class="mb-3">
                                <label for="house_number" class="form-label">Hausnummer <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" id="house_number" name="house_number" required 
                                       value="' . htmlspecialchars($_POST['house_number'] ?? $customer['house_number']) . '">
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="mb-3">
                                <label for="postal_code" class="form-label">PLZ <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" id="postal_code" name="postal_code" required 
                                       value="' . htmlspecialchars($_POST['postal_code'] ?? $customer['postal_code']) . '">
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="mb-3">
                                <label for="city" class="form-label">Ort <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" id="city" name="city" required 
                                       value="' . htmlspecialchars($_POST['city'] ?? $customer['city']) . '">
                            </div>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-4">
                            <div class="mb-3">
                                <label for="phone_prefix" class="form-label">Telefon Vorwahl</label>
                                <input type="text" class="form-control" id="phone_prefix" name="phone_prefix" 
                                       value="' . htmlspecialchars($_POST['phone_prefix'] ?? $customer['phone_prefix'] ?? '') . '">
                            </div>
                        </div>
                        <div class="col-md-8">
                            <div class="mb-3">
                                <label for="phone_number" class="form-label">Telefonnummer</label>
                                <input type="text" class="form-control" id="phone_number" name="phone_number" 
                                       value="' . htmlspecialchars($_POST['phone_number'] ?? $customer['phone_number'] ?? '') . '">
                            </div>
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label for="item_type" class="form-label">Typ</label>
                        <select class="form-select" id="item_type" name="item_type">
                            <option value="ticket" ' . (($_POST['item_type'] ?? $customer['item_type']) == 'ticket' ? 'selected' : '') . '>Ticket</option>
                            <option value="voucher" ' . (($_POST['item_type'] ?? $customer['item_type']) == 'voucher' ? 'selected' : '') . '>Gutschein</option>
                        </select>
                    </div>
                </div>
            </div>
            
            <div class="mt-4">
                <button type="submit" class="btn btn-primary">
                    <i class="bi bi-save"></i> Änderungen speichern
                </button>
                <a href="' . url('admin/customers.php') . '" class="btn btn-secondary">
                    <i class="bi bi-x-circle"></i> Abbrechen
                </a>
            </div>
        </form>
    </div>
</div>
';

require __DIR__ . '/../../app/views/layouts/main.php';

