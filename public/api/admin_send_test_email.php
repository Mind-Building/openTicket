<?php
/**
 * Admin: Send a test/preview email for a given template type
 */

require_once __DIR__ . '/../../app/bootstrap.php';

use App\Core\Auth;
use App\Core\Config;
use App\Core\Session;
use App\Services\EmailService;

Session::start();
header('Content-Type: application/json');

if (!Auth::check() || Auth::role() !== 'admin') {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Nicht autorisiert']);
    exit;
}

try {
    $input = json_decode(file_get_contents('php://input'), true);
    $type  = $input['type']  ?? '';
    $email = trim($input['email'] ?? '');

    $allowedTypes = ['reservation', 'ticket', 'voucher', 'pickup', 'presales', 'combined', 'vip'];
    if (!in_array($type, $allowedTypes, true)) {
        throw new \Exception('Ungültiger Template-Typ');
    }
    if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new \Exception('Ungültige E-Mail-Adresse');
    }

    if (!Config::get('email_enabled', true)) {
        throw new \Exception('E-Mail-Versand ist in den Einstellungen deaktiviert');
    }

    // Dummy reservation data for placeholder substitution
    $dummyReservation = [
        'id'             => 1,
        'event_id'       => 1,
        'event_name'     => 'Beispiel-Veranstaltung',
        'event_date'     => date('Y-m-d'),
        'event_time'     => '20:00',
        'seat_identifier'=> 'seat_3_12',
        'seat_display_name' => 'Reihe 3, Platz 12',
        'category_name'  => 'Parkett',
        'category_price' => 18.00,
        'category_sign'  => '',
        'is_reduced'     => false,
        'status_id'      => 2,
        'customer_id'    => 1,
        'first_name'     => 'Max',
        'last_name'      => 'Mustermann',
        'email'          => $email,
    ];
    $dummyReservations = [$dummyReservation];

    $emailService = new EmailService();
    $recipientName = 'Test-Empfänger';

    $typeLabels = [
        'reservation' => 'Reservierungsbestätigung',
        'ticket'      => 'Ticket-E-Mail',
        'voucher'     => 'Gutschein-E-Mail',
        'pickup'      => 'Abholbestätigung',
        'presales'    => 'Vorverkauf-Bestätigung',
        'combined'    => 'Kombinierte Bestellung',
        'vip'         => 'VIP-Ticket-E-Mail',
    ];

    switch ($type) {
        case 'reservation':
            $emailService->sendReservationEmail($email, $recipientName, $dummyReservations, '');
            break;

        case 'ticket':
            $ticketLink = absoluteUrl('api/ticket_download.php') . '?reservation_ids=1&token=test-token-preview';
            $emailService->sendTicketEmail($email, $recipientName, $dummyReservations, $ticketLink);
            break;

        case 'voucher':
            $dummyVoucher = [
                'id'            => 1,
                'code'          => 'BEISPIEL-CODE',
                'value'         => 25.00,
                'category_name' => 'Parkett',
                'event_name'    => 'Beispiel-Veranstaltung',
                'status_id'     => 0,
            ];
            $emailService->sendVoucherEmail($email, $recipientName, [$dummyVoucher], '');
            break;

        case 'pickup':
            $emailService->sendPickupEmail($email, $recipientName, $dummyReservations);
            break;

        case 'presales':
            $emailService->sendPresalesEmail($email, $recipientName, $dummyReservations);
            break;

        case 'combined':
            $ticketLink = absoluteUrl('api/ticket_download.php') . '?reservation_ids=1&token=test-token-preview';
            $emailService->sendCombinedPurchaseEmail($email, $recipientName, $dummyReservations, [], $ticketLink, null, '');
            break;

        case 'vip':
            $dummyReservation['status_id'] = 11;
            $dummyReservations = [$dummyReservation];
            $ticketLink = absoluteUrl('api/ticket_download.php') . '?reservation_ids=1&token=test-token-preview';
            $emailService->sendVipEmail($email, $recipientName, $dummyReservations, $ticketLink);
            break;
    }

    echo json_encode([
        'success' => true,
        'message' => $typeLabels[$type] . ' wurde an ' . htmlspecialchars($email) . ' gesendet',
    ]);

} catch (\Exception $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
