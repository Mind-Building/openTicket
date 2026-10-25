<?php
require_once __DIR__ . '/../../app/bootstrap.php';

use App\Core\Auth;
use App\Models\Customer;

Auth::requirePresales();

$customerModel = new Customer();
$message = '';
$messageType = '';

$customerId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$customer = $customerModel->getById($customerId);

if (!$customer) {
    header('Location: ' . url('presales/customers.php'));
    exit;
}

// CSRF-Schutz für alle POST-Aktionen dieser Seite
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !\App\Core\Csrf::validatePost()) {
    http_response_code(403);
    die('Ungültige Anfrage: Sicherheits-Token fehlt oder ist abgelaufen. Bitte die Seite neu laden und erneut versuchen.');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (empty($_POST['first_name']) || empty($_POST['last_name'])) {
            throw new \Exception('Bitte füllen Sie Vor- und Nachname aus.');
        }

        $customerModel->update($customerId, [
            'salutation'   => $_POST['salutation'] ?? null,
            'first_name'   => $_POST['first_name'],
            'last_name'    => $_POST['last_name'],
            'street'       => $_POST['street'] ?? '',
            'house_number' => $_POST['house_number'] ?? '',
            'postal_code'  => $_POST['postal_code'] ?? '',
            'city'         => $_POST['city'] ?? '',
            'email'        => $_POST['email'] ?? '',
            'phone_prefix' => $_POST['phone_prefix'] ?? null,
            'phone_number' => $_POST['phone_number'] ?? null,
            'item_type'    => $customer['item_type'] ?? 'ticket',
        ]);

        $_SESSION['flash'] = ['type' => 'success', 'message' => 'Kundendaten wurden aktualisiert.'];
        header('Location: ' . url('presales/customers.php?search=' . urlencode($_POST['last_name'])));
        exit;
    } catch (\Exception $e) {
        $message = $e->getMessage();
        $messageType = 'danger';
        $customer = array_merge($customer, $_POST);
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
                            <option value="Herr" ' . (($customer['salutation'] ?? '') === 'Herr' ? 'selected' : '') . '>Herr</option>
                            <option value="Frau" ' . (($customer['salutation'] ?? '') === 'Frau' ? 'selected' : '') . '>Frau</option>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label for="first_name" class="form-label">Vorname <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="first_name" name="first_name" required
                               value="' . htmlspecialchars($customer['first_name'] ?? '') . '">
                    </div>

                    <div class="mb-3">
                        <label for="last_name" class="form-label">Nachname <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="last_name" name="last_name" required
                               value="' . htmlspecialchars($customer['last_name'] ?? '') . '">
                    </div>

                    <div class="mb-3">
                        <label for="email" class="form-label">E-Mail</label>
                        <input type="email" class="form-control" id="email" name="email"
                               value="' . htmlspecialchars($customer['email'] ?? '') . '">
                    </div>
                </div>

                <div class="col-md-6">
                    <div class="mb-3">
                        <label for="street" class="form-label">Straße</label>
                        <input type="text" class="form-control" id="street" name="street"
                               value="' . htmlspecialchars($customer['street'] ?? '') . '">
                    </div>

                    <div class="row">
                        <div class="col-md-4">
                            <div class="mb-3">
                                <label for="house_number" class="form-label">Hausnummer</label>
                                <input type="text" class="form-control" id="house_number" name="house_number"
                                       value="' . htmlspecialchars($customer['house_number'] ?? '') . '">
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="mb-3">
                                <label for="postal_code" class="form-label">PLZ</label>
                                <input type="text" class="form-control" id="postal_code" name="postal_code"
                                       value="' . htmlspecialchars($customer['postal_code'] ?? '') . '">
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="mb-3">
                                <label for="city" class="form-label">Ort</label>
                                <input type="text" class="form-control" id="city" name="city"
                                       value="' . htmlspecialchars($customer['city'] ?? '') . '">
                            </div>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-4">
                            <div class="mb-3">
                                <label for="phone_prefix" class="form-label">Vorwahl</label>
                                <input type="text" class="form-control" id="phone_prefix" name="phone_prefix"
                                       value="' . htmlspecialchars($customer['phone_prefix'] ?? '') . '">
                            </div>
                        </div>
                        <div class="col-md-8">
                            <div class="mb-3">
                                <label for="phone_number" class="form-label">Telefonnummer</label>
                                <input type="text" class="form-control" id="phone_number" name="phone_number"
                                       value="' . htmlspecialchars($customer['phone_number'] ?? '') . '">
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="mt-4">
                <button type="submit" class="btn btn-primary">
                    <i class="bi bi-save"></i> Änderungen speichern
                </button>
                <a href="' . url('presales/customers.php') . '" class="btn btn-secondary">
                    <i class="bi bi-x-circle"></i> Abbrechen
                </a>
            </div>
        </form>
    </div>
</div>
';

require __DIR__ . '/../../app/views/layouts/main.php';
