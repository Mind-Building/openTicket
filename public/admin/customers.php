<?php
require_once __DIR__ . '/../../app/bootstrap.php';

use App\Core\Auth;
use App\Models\Customer;
use App\Models\Reservation;

Auth::requireAdmin();

$customerModel = new Customer();
$reservationModel = new Reservation();
$message = '';
$messageType = '';

// Handle search
$searchQuery = $_GET['search'] ?? '';
$customers = $searchQuery ? $customerModel->search($searchQuery) : $customerModel->getAll(100, 0);
$totalCount = $customerModel->count();

// Handle delete
if (isset($_GET['delete']) && is_numeric($_GET['delete'])) {
    try {
        $customerModel->delete((int)$_GET['delete']);
        $message = 'Kunde erfolgreich gelöscht.';
        $messageType = 'success';
        // Reload customers
        $customers = $searchQuery ? $customerModel->search($searchQuery) : $customerModel->getAll(100, 0);
    } catch (\Exception $e) {
        $message = 'Fehler beim Löschen: ' . $e->getMessage();
        $messageType = 'danger';
    }
}

$pageTitle = 'Kunden';
$showSidebar = true;
$showHeader = true;

$content = '
<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <h5 class="card-title mb-0"><i class="bi bi-people"></i> Kundenverwaltung</h5>
        <div class="d-flex gap-2 align-items-center flex-wrap">
            <a href="' . url('admin/customers_import.php') . '" class="btn btn-sm btn-outline-success">
                <i class="bi bi-upload"></i> CSV importieren
            </a>
            <form method="GET" action="" class="d-flex">
                <input type="text" class="form-control form-control-sm" name="search" placeholder="Suchen..."
                       value="' . htmlspecialchars($searchQuery) . '">
                <button type="submit" class="btn btn-sm btn-outline-secondary ms-2">
                    <i class="bi bi-search"></i>
                </button>
                ' . ($searchQuery ? '<a href="' . url('admin/customers.php') . '" class="btn btn-sm btn-outline-secondary ms-1">
                    <i class="bi bi-x-circle"></i>
                </a>' : '') . '
            </form>
        </div>
    </div>
    <div class="card-body">
        ' . ($message ? '<div class="alert alert-' . $messageType . ' alert-dismissible fade show" role="alert">' . htmlspecialchars($message) . '<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>' : '') . '
        
        <div class="mb-3">
            <small class="text-muted">Gesamt: ' . $totalCount . ' Kunden' . ($searchQuery ? ' (Gefiltert: ' . count($customers) . ')' : '') . '</small>
        </div>
        
        <div class="table-responsive">
            <table class="table table-striped table-hover">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Name</th>
                        <th>Adresse</th>
                        <th>E-Mail</th>
                        <th>Telefon</th>
                        <th>Typ</th>
                        <th>Erstellt</th>
                        <th>Aktionen</th>
                    </tr>
                </thead>
                <tbody>
';

if (empty($customers)) {
    $content .= '<tr><td colspan="8" class="text-center text-muted">Keine Kunden gefunden.</td></tr>';
} else {
    foreach ($customers as $customer) {
        $name = trim(($customer['salutation'] ?? '') . ' ' . $customer['first_name'] . ' ' . $customer['last_name']);
        $address = $customer['street'] . ' ' . $customer['house_number'] . ', ' . $customer['postal_code'] . ' ' . $customer['city'];
        $phone = ($customer['phone_prefix'] ? $customer['phone_prefix'] . ' ' : '') . ($customer['phone_number'] ?? '');
        $typeLabel = $customer['item_type'] === 'voucher' ? '<span class="badge bg-info">Gutschein</span>' : '<span class="badge bg-primary">Ticket</span>';
        $createdAt = date('d.m.Y H:i', strtotime($customer['created_at']));
        
        // Count reservations for this customer
        $customerReservations = $reservationModel->getAll(['customer_id' => $customer['id']]);
        $reservationCount = count($customerReservations);
        
        $content .= '<tr>
            <td>' . htmlspecialchars($customer['id']) . '</td>
            <td>' . htmlspecialchars($name) . '</td>
            <td>' . htmlspecialchars($address) . '</td>
            <td>' . htmlspecialchars($customer['email']) . '</td>
            <td>' . htmlspecialchars($phone) . '</td>
            <td>' . $typeLabel . '</td>
            <td>' . $createdAt . '</td>
            <td>
                <div class="btn-group" role="group">
                    <a href="' . url('admin/customers_edit.php?id=' . $customer['id']) . '" class="btn btn-sm btn-outline-primary" title="Bearbeiten">
                        <i class="bi bi-pencil"></i>
                    </a>';
        
        if ($reservationCount > 0) {
            $content .= '<a href="' . url('admin/reservations.php?customer_id=' . $customer['id']) . '" class="btn btn-sm btn-outline-info" title="Tickets anzeigen (' . $reservationCount . ')">
                        <i class="bi bi-ticket"></i> <span class="badge bg-info">' . $reservationCount . '</span>
                    </a>';
        }
        
        $content .= '<a href="' . url('admin/customers.php?delete=' . $customer['id']) . '" class="btn btn-sm btn-outline-danger" title="Löschen" onclick="return confirm(\'Möchten Sie diesen Kunden wirklich löschen?\')">
                        <i class="bi bi-trash"></i>
                    </a>
                </div>
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

require __DIR__ . '/../../app/views/layouts/main.php';
