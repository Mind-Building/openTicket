<?php
/**
 * Seatplan Data API
 * Returns seatplan structure, reserved seats, categories, and event dates as JSON.
 * Used by the rebooking modal to render an interactive seatplan for a target date.
 *
 * Auth: admin or presales
 * GET: ?event_id=X&date=YYYY-MM-DD
 */

require_once __DIR__ . '/../../app/bootstrap.php';

use App\Core\Auth;
use App\Core\Session;
use App\Models\Event;
use App\Models\Reservation;
use App\Models\SeatCategory;
use App\Models\SeatPlan;

Session::start();

header('Content-Type: application/json');

if (!Auth::check() || !in_array(Auth::role(), ['admin', 'presales'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Nicht autorisiert']);
    exit;
}

try {
    $eventId = (int)($_GET['event_id'] ?? 0);
    $date = $_GET['date'] ?? '';

    if ($eventId <= 0) {
        throw new \Exception('Ungültige Veranstaltungs-ID.');
    }

    $eventModel = new Event();
    $event = $eventModel->getById($eventId);

    if (!$event) {
        throw new \Exception('Veranstaltung nicht gefunden.');
    }

    $seatPlanModel = new SeatPlan();
    $reservationModel = new Reservation();
    $categoryModel = new SeatCategory();

    $useSeatPlan = (bool)($event['use_seat_plan'] ?? true);
    $seatplan = $useSeatPlan ? $seatPlanModel->getSeatPlanStructure($eventId) : [];
    $reservedSeats = !empty($date) ? $reservationModel->getReservedSeats($eventId, $date) : [];
    $categories = $categoryModel->getByEvent($eventId);
    $eventDates = $eventModel->getDates($eventId);

    echo json_encode([
        'success' => true,
        'use_seat_plan' => $useSeatPlan,
        'seatplan' => $seatplan,
        'reserved_seats' => (object)$reservedSeats,
        'categories' => $categories,
        'event_dates' => $eventDates,
    ]);

} catch (\Exception $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
