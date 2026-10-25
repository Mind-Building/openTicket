<?php
/**
 * API Endpoint: send a review copy of an order's confirmation email to the
 * configured support address (admin only).
 *
 * Mirrors reservation_resend_email.php, but the recipient is the support
 * mailbox instead of the customer — so an admin can review the exact email
 * content and ticket download as the customer would receive it.
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

// Admin only — this is an internal review tool.
Auth::requireAuth();
if (!Auth::isAdmin()) {
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

    if (!Config::get('email_enabled', true)) {
        throw new \Exception('E-Mail-Versand ist in den Einstellungen deaktiviert');
    }

    $supportEmail = trim((string)Config::get('support_email', ''));
    if ($supportEmail === '') {
        throw new \Exception('Es ist keine Support-E-Mail-Adresse konfiguriert (Einstellungen → Support-E-Mail)');
    }

    $reservationModel = new Reservation();

    // Load the whole order group so counts and seat lists are correct
    $groupReservations = $reservationModel->getGroupOf($reservationId);
    if (empty($groupReservations)) {
        throw new \Exception('Reservierung nicht gefunden');
    }

    $reservation = $groupReservations[0];
    $statusId    = (int)$reservation['status_id'];
    $emailService = new EmailService();

    // Recipient name makes it obvious in the inbox that this is a review copy.
    $supportName = 'Support (Prüfkopie)';

    $allIds   = array_column($groupReservations, 'id');
    $idsParam = implode(',', $allIds);

    // Statuses that get a ticket email (paid) / reservation confirmation
    $ticketStatuses      = [2, 4, 5, 8];
    $reservationStatuses = [3, 6];

    if ($statusId === 11) {
        $baseUrl = ensurePublicInUrl(Config::get('ticket_url_start', absoluteUrl('')));
        $token = generateTicketDownloadToken($allIds);
        $ticketLink = $baseUrl . '/api/ticket_download.php?reservation_ids=' . $idsParam . '&token=' . $token;
        $emailService->sendVipEmail($supportEmail, $supportName, $groupReservations, $ticketLink);
        $emailType = 'VIP-Ticket-E-Mail';
    } elseif (in_array($statusId, $ticketStatuses, true)) {
        $baseUrl = ensurePublicInUrl(Config::get('ticket_url_start', absoluteUrl('')));
        $token = generateTicketDownloadToken($allIds);
        $ticketLink = $baseUrl . '/api/ticket_download.php?reservation_ids=' . $idsParam . '&token=' . $token;
        $emailService->sendTicketEmail(
            $supportEmail,
            $supportName,
            $groupReservations,
            $ticketLink,
            (int)($reservation['event_id'] ?? 0) ?: null
        );
        $emailType = 'Ticket-E-Mail';
    } elseif (in_array($statusId, $reservationStatuses, true)) {
        $emailService->sendReservationEmail($supportEmail, $supportName, $groupReservations, '');
        $emailType = 'Reservierungsbestätigung';
    } else {
        throw new \Exception('Für diesen Status ist kein E-Mail-Versand vorgesehen');
    }

    echo json_encode([
        'success' => true,
        'message' => $emailType . ' (Prüfkopie) wurde an ' . $supportEmail . ' gesendet'
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
