<?php
/**
 * Rebook Seats API
 * Atomically moves one or more reservations to new seats and/or dates.
 * Category must match (value must not change).
 *
 * Auth: admin or presales
 * POST JSON: { "rebookings": [{ "reservation_id": int, "new_seat_identifier": string, "new_event_date": string }] }
 */

require_once __DIR__ . '/../../app/bootstrap.php';

use App\Core\Auth;
use App\Core\Session;
use App\Models\Event;
use App\Models\Reservation;
use App\Models\Rebooking;
use App\Services\EmailService;
use App\Services\LoggerService;

Session::start();

header('Content-Type: application/json');

if (!Auth::check() || !in_array(Auth::role(), ['admin', 'presales'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Nicht autorisiert']);
    exit;
}

try {
    $input = json_decode(file_get_contents('php://input'), true);
    $rebookings = $input['rebookings'] ?? [];

    if (empty($rebookings)) {
        throw new \Exception('Keine Umbuchungen angegeben.');
    }

    // Basic input validation
    foreach ($rebookings as $i => $entry) {
        if (empty($entry['reservation_id']) || empty($entry['new_seat_identifier']) || empty($entry['new_event_date'])) {
            throw new \Exception("Umbuchung #" . ($i + 1) . ": Unvollständige Daten.");
        }
    }

    // Validate that all target dates exist for the event
    $reservationModel = new Reservation();
    $eventModel = new Event();

    // Get event_id from first reservation to validate dates
    $firstRes = $reservationModel->getById((int)$rebookings[0]['reservation_id']);
    if (!$firstRes) {
        throw new \Exception('Reservierung nicht gefunden.');
    }

    $eventId = (int)$firstRes['event_id'];
    $eventDates = $eventModel->getDates($eventId);
    $validDates = array_column($eventDates, 'date');

    foreach ($rebookings as $entry) {
        if (!in_array($entry['new_event_date'], $validDates)) {
            throw new \Exception("Datum " . $entry['new_event_date'] . " ist kein gültiger Termin für diese Veranstaltung.");
        }
    }

    // Perform the atomic rebook
    $results = $reservationModel->rebook($rebookings);

    // Log the rebooking
    $channel = Auth::role() === 'presales' ? 'presales' : 'admin';
    LoggerService::logReservationRebooked(
        $channel,
        (string)Auth::id(),
        Auth::username(),
        $eventId,
        $results
    );

    // Persist an audit-trail row per rebooking (durable, queryable per event).
    // Failure here must not break the (already committed) rebooking.
    try {
        (new Rebooking())->recordBatch(
            $eventId,
            $channel,
            Auth::id() !== null ? (int)Auth::id() : null,
            Auth::username(),
            $results
        );
    } catch (\Throwable $e) {
        error_log('Rebooking audit persist failed: ' . $e->getMessage());
    }

    // Optional: Kunde nach der Umbuchung benachrichtigen (frisches Ticket mit
    // den neuen Daten). Pro betroffener Bestellgruppe wird genau eine Mail
    // versendet. Fehler hier duerfen die Umbuchung nicht scheitern lassen.
    $notify      = !empty($input['notify_customer']);
    $emailsSent  = 0;
    $emailFailed = 0;
    if ($notify) {
        $emailService = new EmailService();
        $covered = [];
        foreach ($results as $r) {
            $rid = (int)$r['reservation_id'];
            if (isset($covered[$rid])) {
                continue; // Gruppe bereits benachrichtigt
            }
            try {
                $group = $reservationModel->getGroupOf($rid);
                foreach ($group as $g) {
                    $covered[(int)$g['id']] = true;
                }
                $mail = $emailService->sendGroupConfirmationEmail($group);
                $mail['sent'] ? $emailsSent++ : $emailFailed++;
            } catch (\Throwable $e) {
                $emailFailed++;
                error_log('Rebooking notify failed for #' . $rid . ': ' . $e->getMessage());
            }
        }
    }

    $count = count($results);
    $message = $count === 1 ? '1 Platz erfolgreich umgebucht.' : "$count Plätze erfolgreich umgebucht.";
    if ($notify) {
        if ($emailsSent > 0) {
            $message .= ' ' . $emailsSent . ' Benachrichtigung(en) versendet.';
        }
        if ($emailFailed > 0) {
            $message .= ' ' . $emailFailed . ' E-Mail(s) konnten nicht gesendet werden (kein Kunde/keine Adresse?).';
        }
    }

    echo json_encode([
        'success' => true,
        'message' => $message,
        'rebookings' => $results,
        'emails_sent' => $emailsSent,
        'emails_failed' => $emailFailed,
    ]);

} catch (\Exception $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
