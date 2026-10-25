<?php
require_once __DIR__ . '/../../app/bootstrap.php';

use App\Core\Auth;
use App\Core\Config;
use App\Models\Customer;

Auth::requirePresales();

$customerModel = new Customer();
$message = '';
$messageType = '';

// Handle search
$searchQuery = $_GET['search'] ?? '';
$customers = $searchQuery ? $customerModel->search($searchQuery) : $customerModel->getAll(100, 0);
$totalCount = $customerModel->count();

$pageTitle = 'Kunden';
$showSidebar = true;
$showHeader = true;

$content = '
<div class="d-flex justify-content-between align-items-center mb-4">
    <h2>Kunden</h2>
</div>

<div class="card mb-4">
    <div class="card-body">
        <form method="GET" action="" class="row g-3">
            <div class="col-md-10">
                <input type="text" class="form-control" name="search" placeholder="Suche nach Name, E-Mail oder Stadt..." value="' . htmlspecialchars($searchQuery) . '">
            </div>
            <div class="col-md-2">
                <button type="submit" class="btn btn-primary w-100">
                    <i class="bi bi-search"></i> Suchen
                </button>
            </div>
        </form>
    </div>
</div>

<div class="card">
    <div class="card-body">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h5 class="mb-0">Kundenliste</h5>
            <small class="text-muted">Gesamt: ' . $totalCount . ' Kunden</small>
        </div>
        <div class="table-responsive">
            <table class="table table-hover">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Name</th>
                        <th>E-Mail</th>
                        <th>Adresse</th>
                        <th>Telefon</th>
                        <th>Erstellt am</th>
                        <th class="no-sort">Aktionen</th>
                    </tr>
                </thead>
                <tbody>';

if (empty($customers)) {
    $content .= '
                    <tr>
                        <td colspan="7" class="text-center text-muted">
                            <i class="bi bi-inbox"></i> Keine Kunden gefunden
                        </td>
                    </tr>';
} else {
    foreach ($customers as $customer) {
        $name = trim(($customer['first_name'] ?? '') . ' ' . ($customer['last_name'] ?? ''));
        if (empty($name)) {
            $name = '<span class="text-muted">-</span>';
        }
        
        $address = trim(($customer['street'] ?? '') . ' ' . ($customer['house_number'] ?? ''));
        if (!empty($address)) {
            $address .= ', ' . ($customer['postal_code'] ?? '') . ' ' . ($customer['city'] ?? '');
        }
        if (empty($address)) {
            $address = '<span class="text-muted">-</span>';
        }
        
        $phone = '';
        if (!empty($customer['phone_prefix']) || !empty($customer['phone_number'])) {
            $phone = ($customer['phone_prefix'] ?? '') . ' ' . ($customer['phone_number'] ?? '');
        }
        if (empty($phone)) {
            $phone = '<span class="text-muted">-</span>';
        }
        
        $content .= '
                    <tr>
                        <td>' . $customer['id'] . '</td>
                        <td>' . htmlspecialchars($name) . '</td>
                        <td>' . htmlspecialchars($customer['email'] ?? '-') . '</td>
                        <td>' . htmlspecialchars($address) . '</td>
                        <td>' . htmlspecialchars($phone) . '</td>
                        <td>' . date('d.m.Y H:i', strtotime($customer['created_at'])) . '</td>
                        <td>
                            <a href="' . url('presales/customers_edit.php?id=' . $customer['id']) . '" class="btn btn-sm btn-outline-primary" title="Bearbeiten">
                                <i class="bi bi-pencil"></i>
                            </a>
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

