<?php
/**
 * API Endpoint to resend confirmation email for a reservation
 */

if (ob_get_level()) {
    ob_clean();
} else {
    ob_start();
}

require_once __DIR__ . '/../../app/bootstrap.php';

use App\Core\Auth;
use App\Core\Config;
use App\Models\Reservation;
use App\Services\EmailService;

// Allow both presales and admin roles
Auth::requireAuth();
if (!Auth::isAdmin() && !Auth::isPresales()) {
    if (ob_get_level()) ob_clean();
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Keine Berechtigung']);
    exit;
}

if (ob_get_level()) {
    ob_clean();
}

header('Content-Type: application/json; charset=utf-8');

try {
    $data = json_decode(file_get_contents('php://input'), true);
    $reservationId = (int)($data['reservation_id'] ?? 0);

    if ($reservationId <= 0) {
        throw new \Exception('Ungültige Reservierungs-ID');
    }

    $reservationModel = new Reservation();

    // Load the whole order group so counts and seat lists are correct
    $groupReservations = $reservationModel->getGroupOf($reservationId);

    if (empty($groupReservations)) {
        throw new \Exception('Reservierung nicht gefunden');
    }

    // Gemeinsame Versand-Logik (Status-abhaengig: VIP/Ticket/Reservierung)
    $result = (new EmailService())->sendGroupConfirmationEmail($groupReservations);

    if (!$result['sent']) {
        throw new \Exception($result['reason'] ?? 'E-Mail konnte nicht gesendet werden');
    }

    echo json_encode([
        'success' => true,
        'message' => $result['type'] . ' wurde erneut an ' . $result['email'] . ' gesendet'
    ]);
    exit;

} catch (\Exception $e) {
    if (ob_get_level()) {
        ob_clean();
    }
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
    exit;
}
