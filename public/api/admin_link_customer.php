<?php
/**
 * Admin: Link (or change) the customer for an entire order
 *
 * Assigns the given customer_id to every reservation in the order group.
 * Typical use: a Barverkauf order (without customer) is upgraded to VIP and
 * needs a real customer attached.
 *
 * Auth: admin only
 * Body (JSON): { reservation_ids: number[], customer_id: number }
 */

require_once __DIR__ . '/../../app/bootstrap.php';

use App\Core\Auth;
use App\Core\Session;
use App\Core\Database;
use App\Services\LoggerService;

Session::start();
header('Content-Type: application/json');

if (!Auth::check() || Auth::role() !== 'admin') {
    echo json_encode(['success' => false, 'error' => 'Nicht autorisiert']);
    exit;
}

try {
    $input = json_decode(file_get_contents('php://input'), true);

    $reservationIds = $input['reservation_ids'] ?? [];
    $customerId     = (int)($input['customer_id'] ?? 0);

    if (!is_array($reservationIds)) {
        throw new \Exception('Ungültige Reservierungs-IDs');
    }
    $reservationIds = array_values(array_unique(array_filter(array_map('intval', $reservationIds))));
    if (empty($reservationIds)) {
        throw new \Exception('Keine Reservierungen angegeben');
    }
    if ($customerId <= 0) {
        throw new \Exception('Kein Kunde ausgewählt');
    }

    $db = Database::getInstance();

    // Verify the customer exists
    $customer = $db->query(
        "SELECT id, salutation, first_name, last_name, email FROM customers WHERE id = :id",
        [':id' => $customerId]
    )->fetch();
    if (!$customer) {
        throw new \Exception('Kunde nicht gefunden');
    }

    // Update all given reservations in one statement (bound IN list)
    $placeholders = [];
    $params       = [':customer_id' => $customerId];
    foreach ($reservationIds as $i => $rid) {
        $key = ':id' . $i;
        $placeholders[] = $key;
        $params[$key]   = $rid;
    }

    $db->query(
        "UPDATE reservations SET customer_id = :customer_id WHERE id IN (" . implode(',', $placeholders) . ")",
        $params
    );

    $customerName = trim(($customer['first_name'] ?? '') . ' ' . ($customer['last_name'] ?? ''));

    if (function_exists('logAdminAction')) {
        logAdminAction((string)Auth::id(), 'order_customer_linked', [
            'reservation_ids' => $reservationIds,
            'customer_id'     => $customerId,
            'customer_name'   => $customerName,
        ]);
    }

    echo json_encode([
        'success'  => true,
        'message'  => 'Kunde verknüpft',
        'customer' => [
            'id'         => (int)$customer['id'],
            'first_name' => $customer['first_name'] ?? '',
            'last_name'  => $customer['last_name'] ?? '',
            'email'      => $customer['email'] ?? '',
        ],
    ]);

} catch (\Exception $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
