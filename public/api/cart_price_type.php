<?php
/**
 * Set the price type of a cart item from the cart page: "normal" or "reduced".
 * Reduced is only stored where allowed (settings, event, category reduced price).
 * FN card goes through api/fncard_validate.php (card number check + limit); choosing
 * "normal"/"reduced" here releases a previously entered FN card.
 */
// Suppress any output before JSON
ob_start();

require_once __DIR__ . '/../../app/bootstrap.php';

use App\Core\Session;
use App\Core\ApiGuard;
use App\Helpers\TicketPrice;
use App\Models\Reservation;

ob_clean();

header('Content-Type: application/json; charset=utf-8');

Session::start();
ApiGuard::publicApi('cart', 90, 60);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true) ?? [];
$reservationId = (int)($input['reservation_id'] ?? 0);
$type = (string)($input['type'] ?? '');

if ($reservationId <= 0 || !in_array($type, ['normal', 'reduced'], true)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid data']);
    exit;
}

try {
    $reservationModel = new Reservation();
    $reservation = $reservationModel->getById($reservationId);
    if (!$reservation || $reservation['session_id'] !== session_id()) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Unauthorized']);
        exit;
    }
    if ((int)$reservation['status_id'] !== 1) {
        echo json_encode(['success' => false, 'error' => 'Nur Warenkorb-Artikel können geändert werden']);
        exit;
    }

    $reservationModel->applyCustomerPriceTypes([$reservation], [$reservationId => $type]);

    $updated = $reservationModel->getById($reservationId);
    if ($type === 'reduced' && empty($updated['is_reduced'])) {
        echo json_encode(['success' => false, 'error' => 'Für diesen Platz ist keine Ermäßigung möglich.']);
        exit;
    }

    $price = TicketPrice::forReservation($updated);
    echo json_encode([
        'success' => true,
        'price' => $price['price'],
        'price_type' => TicketPrice::typeLabel($price['type']) ?: 'Normal',
    ]);
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Die Preisart konnte nicht gespeichert werden.']);
}
