<?php
/**
 * Customer Search API (Presales/Admin only)
 * Returns matching customers for autocomplete (scrollable result list).
 */

require_once __DIR__ . '/../../app/bootstrap.php';

use App\Core\Session;
use App\Core\Auth;
use App\Models\Customer;

Session::start();

header('Content-Type: application/json');

if (!Auth::check() || !in_array(Auth::role(), ['admin', 'presales'])) {
    echo json_encode(['success' => false, 'error' => 'Nicht autorisiert']);
    exit;
}

$query = trim($_GET['q'] ?? '');

if (strlen($query) < 2) {
    echo json_encode(['success' => true, 'results' => []]);
    exit;
}

try {
    $customerModel = new Customer();
    $results = $customerModel->search($query, 50);

    $customers = array_map(function($c) {
        return [
            'id'           => (int)$c['id'],
            'salutation'   => $c['salutation'] ?? '',
            'first_name'   => $c['first_name'] ?? '',
            'last_name'    => $c['last_name'] ?? '',
            'street'       => $c['street'] ?? '',
            'house_number' => $c['house_number'] ?? '',
            'postal_code'  => $c['postal_code'] ?? '',
            'city'         => $c['city'] ?? '',
            'email'        => $c['email'] ?? '',
            'phone_prefix' => $c['phone_prefix'] ?? '',
            'phone_number' => $c['phone_number'] ?? '',
        ];
    }, $results);

    echo json_encode(['success' => true, 'results' => $customers]);
} catch (\Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
