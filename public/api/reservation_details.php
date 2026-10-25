<?php
require_once __DIR__ . '/../../app/bootstrap.php';

use App\Core\Auth;
use App\Models\Reservation;

header('Content-Type: application/json');

// Only allow admin access
Auth::requireAdmin();

$reservationId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($reservationId <= 0) {
    echo json_encode(['success' => false, 'error' => 'Ungültige Reservierungs-ID']);
    exit;
}

try {
    $reservationModel = new Reservation();
    $reservation = $reservationModel->getById($reservationId);
    
    if (!$reservation) {
        echo json_encode(['success' => false, 'error' => 'Reservierung nicht gefunden']);
        exit;
    }
    
    echo json_encode(['success' => true, 'reservation' => $reservation]);
} catch (\Exception $e) {
    error_log("Error fetching reservation details: " . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Fehler beim Laden der Reservierungsdetails']);
}


