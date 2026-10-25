<?php
/**
 * API Endpoint to mark reservation(s) as paid/collected (status 3 → 4)
 * Accepts single reservation_id or array reservation_ids for group actions.
 * Sends pickup confirmation email instead of ticket email.
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
use App\Models\Customer;
use App\Services\EmailService;
use App\Services\LoggerService;

if (ob_get_level()) {
    ob_clean();
}

header('Content-Type: application/json; charset=utf-8');

// Allowed for both admin and presales. Return a JSON 403 instead of the
// redirect Auth::requireRole() would emit – a redirect sends an HTML body
// that breaks the client-side JSON parser ("Unexpected token '<'").
if (!Auth::check() || !in_array(Auth::role(), ['admin', 'presales'], true)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Nicht autorisiert']);
    exit;
}

try {
    $data = json_decode(file_get_contents('php://input'), true);

    // Accept single ID or array of IDs
    if (!empty($data['reservation_ids']) && is_array($data['reservation_ids'])) {
        $ids = array_filter(array_map('intval', $data['reservation_ids']));
    } elseif (!empty($data['reservation_id'])) {
        $ids = [(int)$data['reservation_id']];
    } else {
        throw new \Exception('Keine Reservierungs-ID angegeben');
    }

    if (empty($ids)) {
        throw new \Exception('Ungültige Reservierungs-IDs');
    }

    $reservationModel = new Reservation();
    $markedCount = 0;
    $reservationDetails = [];
    $customerId = null;
    $eventId = null;

    foreach ($ids as $rid) {
        $reservation = $reservationModel->getById($rid);
        if (!$reservation) {
            continue;
        }
        if ((int)$reservation['status_id'] !== 3) {
            continue; // Skip non-reserved tickets
        }
        $reservationModel->updateStatus($rid, 4);
        $reservationDetails[] = $reservationModel->getById($rid);
        $markedCount++;
        if ($customerId === null) {
            $customerId = $reservation['customer_id'] ? (int)$reservation['customer_id'] : null;
            $eventId    = (int)($reservation['event_id'] ?? 0) ?: null;
        }
    }

    if ($markedCount === 0) {
        throw new \Exception('Keine reservierten Tickets gefunden die markiert werden konnten');
    }

    LoggerService::logPresalesMarkPaid(
        (string)Auth::id(),
        Auth::username() ?? '',
        array_column($reservationDetails, 'id'),
        $markedCount
    );

    // Send pickup confirmation email
    $emailEnabled = Config::get('email_enabled', true);
    if ($emailEnabled && $customerId && !empty($reservationDetails)) {
        try {
            $customerModel = new Customer();
            $customer = $customerModel->getById($customerId);
            if ($customer && !empty($customer['email'])) {
                $customerName = trim(($customer['first_name'] ?? '') . ' ' . ($customer['last_name'] ?? ''));
                $emailService = new EmailService();
                $emailService->sendPickupEmail(
                    $customer['email'],
                    $customerName ?: $customer['email'],
                    $reservationDetails
                );
            }
        } catch (\Exception $emailException) {
            error_log("reservation_mark_paid - Email error: " . $emailException->getMessage());
        }
    }

    echo json_encode([
        'success' => true,
        'message' => $markedCount . ' Ticket(s) wurden als bezahlt/abgeholt markiert',
        'marked_count' => $markedCount,
    ]);
    exit;

} catch (\Exception $e) {
    if (ob_get_level()) {
        ob_clean();
    }
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error'   => $e->getMessage()
    ]);
    exit;
}
